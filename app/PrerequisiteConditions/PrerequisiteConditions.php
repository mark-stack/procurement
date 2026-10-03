<?php

namespace App\PrerequisiteConditions;

use App\Models\Batch;
use App\Models\Offcut;
use App\Models\Project;
use App\Models\Quote;
use App\Models\User;
use Illuminate\Support\Collection;

class PrerequisiteConditions
{
    public function startQuoting(
        User $user,
        Collection $projectsReadyForBatching,
        Collection $piecesReadyForBatching
    ): bool
    {
        /**
         * 1) BUSINESS: Is your business
         * 2) PROJECT: Owner of at least 1 project
         * 3) RAWMATERIALQUOTE: has materials
         * 4) PIECE: Pieces belong to this business
         * 5A) PIECE: All pieces have no batch
         * 5B) QUOTE: There are no quotes
         * 5C) ORDER: There are no orders
         * 6) PROJECT: All projects not archived
         */

        //1) BUSINESS: Is your business
        $condition_1 = true; //$user->business

        //2) User is owner of at least 1 project
        $condition_2 = false;
        foreach($projectsReadyForBatching as $project){
            if($project->user->id === $user->id){
                $condition_2 = true;
            }
        }

        //3) RAWMATERIALQUOTE: Has materials
        $condition_3 = $piecesReadyForBatching->count() > 0;

        //4) PIECE: Pieces belong to this business
        $condition_4 = true;
        foreach($projectsReadyForBatching as $project){
            if($project->user->business->id !== $user->business->id){
                $condition_4 = false;
            }
        }

        //5A) PIECE: All pieces have no batch
        //5B) QUOTE: There no quotes (requires batch)
        //5C) ORDER: There are no orders (requires batch)
        $condition_5 = $piecesReadyForBatching->where("batch_id","!=",null)->count() === 0;

        //6) All projects not archived
        $condition_6 = $projectsReadyForBatching->where("archive",true)->count() === 0;

        return
            $condition_1 &&
            $condition_2 &&
            $condition_3 &&
            $condition_4 &&
            $condition_5 &&
            $condition_6;
    }

    public function undoStartQuoting(
        Batch $batch,
        User $user,
        Collection $offcutsAssignedToThisBatch,
    ): bool
    {
        /**
         * 1) PROJECT: All projects belong to your business
         * 2) PROJECT: At least project is yours
         * 3) PROJECT: All projects not archived
         * 4) ORDER: no order sent
         * 5) OFFCUT: Are your offcuts being released
         * 6) OFFCUT: "allocated_to" is this batch (released from)
         * 7) RAWMATERIALQUOTE: All materials are assigned to this batch
         * 8) OFFCUT: None of the offcuts this batch produced has been consumed downstream
         * 9) BATCH: Nobody has recorded buying any of it off the application
         */

        //1) PROJECT: All projects belong to your business
        $condition_1 = true;
        foreach($batch->projects() as $project){
            if($project->user->business->id !== $user->business->id){
                $condition_1 = false;
            }
        }

        //2) PROJECT: At least project is yours
        $condition_2 = false;
        foreach($batch->projects() as $project){
            if($project->user->id === $user->id){
                $condition_2 = true;
            }
        }

        //3) PROJECT: All projects not archived
        $condition_3 = true;
        foreach($batch->projects() as $project){
            if($project->archive){
                $condition_3 = false;
            }
        }

        //4) ORDER: no order sent
        //A single-argument where() compiles to "order_sent is null", and the column is a non-nullable
        //boolean - so this counted 0 every time and let a batch be unwound after it had been ordered
        $condition_4 = $batch->orders()->where("order_sent", true)->count() === 0;

        //5) OFFCUT: Are your offcuts being released
        //6) OFFCUT: "allocated_to" is this batch (released from)
        //One pass for both - batchTo() is a Batch::find, so checking them separately doubled the queries
        $condition_5 = true;
        $condition_6 = true;
        foreach($offcutsAssignedToThisBatch as $offcut){
            //batchTo() is nullable, and a released offcut with no destination belongs to nobody
            $batchTo = $offcut->batchTo();
            if(!$batchTo){
                $condition_5 = false;
                $condition_6 = false;
                continue;
            }

            $businessOwnership = $batchTo->user->business->id === $user->business->id;
            if(!$businessOwnership){
                $condition_5 = false;
            }

            $batchOwnership = $batchTo->id === $batch->id;
            if(!$batchOwnership){
                $condition_6 = false;
            }
        }

        //7) All materials are assigned to this batch
        $condition_7 = true;
        foreach($batch->pieces as $piece){
            if($piece->batch->id !== $batch->id){
                $condition_7 = false;
            }
        }

        /*
         * 8) OFFCUT: None of the offcuts this batch produced has been consumed downstream.
         *
         * Unwinding the batch un-nests it, so the offcuts it produced were never cut and get deleted
         * with it. If a later batch has already nested into one of them, deleting it would strip that
         * batch of the offcut and of the certificate trail behind it.
         */
        $condition_8 = ! Offcut::query()
            ->where("batch_from_id",$batch->id)
            ->whereNotNull("batch_to_id")
            ->exists();

        /*
         * 9) BATCH: Nobody has recorded buying any of it.
         *
         * Condition 4 only sees orders placed through the application. A shop that rings the merchant
         * and buys in the one call records it with "All ordered" on the Nesting card instead
         * (BatchMarkOrderedController), or one merchant at a time on the order list
         * (BatchMarkGroupOrderedController) - and that steel is just as bought, so unpicking the
         * batch would un-nest material nobody can get back.
         *
         * One marked merchant is enough to refuse, the way one sent order is: the question is whether
         * any of this nest has been committed to, not whether all of it has.
         *
         * The quoted mark is deliberately not here: prices coming in changes nothing about whether
         * the nest can be thrown away, which is why a QUOTED card still offers the way back.
         */
        $condition_9 = $batch->ordered_at === null
            && ($batch->ordered_supplier_groups ?? []) === [];

        return
            $condition_1 &&
            $condition_2 &&
            $condition_3 &&
            $condition_4 &&
            $condition_5 &&
            $condition_6 &&
            $condition_7 &&
            $condition_8 &&
            $condition_9;
    }

    public function uploadMaterials(User $user, Project $project): bool
    {
        /**
         * 1) BUSINESS: Your business
         * 2) PROJECT: Yours to change - its manager, or whoever uploaded it for them
         * 3) PROJECT: Not archived
         * 4A) BATCH: No Batch exists for this project
         * 4B) No quote for this project (requires batch)
         * 4C) No order for this project (requires batch)
         */

        //1) BUSINESS: Your business
        $condition_1 = $project->user->business->id === $user->business->id;

        /*
         * 2) PROJECT: Yours to change
         *
         * This was user_id alone, which is the project manager. A draftsman who uploads a material
         * list for a manager's project could then do nothing further to it: no second file when the
         * rest of the BOM arrives, and no finishing an import that stopped at a price book
         * clarification. See Project::isManagedBy for where that line now sits and why.
         */
        $condition_2 = $project->isManagedBy($user);

        //3) PROJECT: Not archived
        $condition_3 = !$project->archive;

        //4A) BATCH: No Batch exists for this project
        //4B) No quote for this project (requires batch)
        //4C) No order for this project (requires batch)
        $condition_4 = true;
        foreach($project->pieces as $piece){
            if($piece->batch){
                $condition_4 = false;
            }
        }

        return
            $condition_1 &&
            $condition_2 &&
            $condition_3 &&
            $condition_4;
    }

    public function editProject(User $user, Project $project): bool
    {
        /**
         * The same answer archiveProject gives, and for the same reason. The Nesting column is
         * shared, so every colleague's project carried a live Edit button beside a greyed-out
         * Archive one - and Edit is not the smaller of the two. The name is how the rest of the
         * business recognises the project on the board and in Past Projects, and
         * date_materials_required drives the critical path and every deadline notification the
         * owner receives. A colleague could move both, silently, with nothing recording that
         * they had.
         *
         * Nothing enforced this server side: ProjectPolicy asks only whether the project belongs
         * to your business.
         *
         * 1) BUSINESS: Your business
         * 2) PROJECT: Your project
         */

        //1) BUSINESS: Your business
        $condition_1 = $project->user->business->id === $user->business->id;

        //2) PROJECT: Your project
        $condition_2 = $project->user->id === $user->id;

        return
            $condition_1 &&
            $condition_2;
    }

    public function archiveProject(User $user, Project $project): bool
    {
        /**
         * Archiving takes a project off the board for the whole business, and only its owner
         * can put it back - so it is the owner's call, and only while the project is still
         * pre-nesting. Nothing enforced either of those server side: the button hid itself
         * outside the Nesting column and the notification "Lost it" action skipped even that,
         * which is how a batch could end up permanently un-re-nestable (undoStartQuoting
         * condition 3) because of a project nobody else could see, let alone restore.
         *
         * 1) BUSINESS: Your business
         * 2) PROJECT: Your project
         * 3) PROJECT: Not already archived
         * 4) PIECE: No batch exists for this project
         */

        //1) BUSINESS: Your business
        $condition_1 = $project->user->business->id === $user->business->id;

        //2) PROJECT: Your project
        $condition_2 = $project->user->id === $user->id;

        //3) PROJECT: Not already archived
        $condition_3 = !$project->archive;

        //4) PIECE: No batch exists for this project
        $condition_4 = $project->pieces()->whereNotNull("batch_id")->doesntExist();

        return
            $condition_1 &&
            $condition_2 &&
            $condition_3 &&
            $condition_4;
    }

    public function restoreProject(User $user, Project $project): bool
    {
        /**
         * The way back from archiveProject, so it asks for no more than that one did: a project
         * archived while it was on a batch has to be restorable, or the batch it is holding up
         * stays held up forever.
         *
         * The name clash is deliberately not one of these conditions - it is answered separately by
         * restoreProjectNameIsFree() below, because it is the one obstacle the owner can clear
         * themselves and so is worth a sentence rather than a 403.
         *
         * 1) BUSINESS: Your business
         * 2) PROJECT: Your project
         * 3) PROJECT: Archived
         */

        //1) BUSINESS: Your business
        $condition_1 = $project->user->business->id === $user->business->id;

        //2) PROJECT: Your project
        $condition_2 = $project->user->id === $user->id;

        //3) PROJECT: Archived
        $condition_3 = (bool) $project->archive;

        return
            $condition_1 &&
            $condition_2 &&
            $condition_3;
    }

    /**
     * Is this archived project's name still free on the board it wants to come back to?
     *
     * Both project forms exclude archived names from their uniqueness check, and say so out loud:
     * "archived ones are free to reuse". That is the intended behaviour and it is what opens this -
     * archive "Tower A", give a new project the freed-up name, restore the old one, and the shared
     * Nesting column has two live projects called "Tower A" belonging to different jobs.
     *
     * Nothing downstream can tell them apart for a person. The board draws two identical cards, Past
     * Projects lists the name twice, and a colleague pressing "Start quoting" nests both into one
     * batch - where the cut drawings label their pieces by letter but the spec sheet, the BOM
     * download and every notification name the project. Steel gets cut for the wrong Tower A.
     *
     * Refusing the restore is safe in a way that refusing the archive would not be: the owner can
     * rename an archived project (editProject does not ask whether it is archived), so there is
     * always a way through, and a batch held up by an archived project can never reach this - a
     * project on a batch cannot be archived through archiveProject in the first place.
     */
    public function restoreProjectNameIsFree(Project $project): bool
    {
        return ! $project->user->business->projects()
            ->where('projects.archive', false)
            ->where('projects.id', '!=', $project->id)
            ->where('projects.name', $project->name)
            ->exists();
    }

    /**
     * Whether this user may call the whole batch quoted, naming no supplier.
     *
     * The Nesting page's "All quoted", which is the step for a shop that does not buy through the
     * quotes modal - see the 2026_10_03 migration. It records that the prices are in; it sends
     * nothing and marks no quote row, so it is deliberately not markQuoteAsSent applied in a loop.
     *
     * Refused once the batch has been called bought: ordering is the step after quoting, and a batch
     * that has passed it is not waiting for a price. Nothing undoes either mark yet, so the item greys
     * once it has been used rather than offering to set a date that is already set.
     */
    public function markBatchQuoted(User $user, Batch $batch): bool
    {
        return $this->canMarkBatchMilestone($user, $batch)
            && $batch->quoted_at === null
            && $batch->ordered_at === null
            && $batch->delivered_at === null;
    }

    /**
     * And the same for "All ordered" - the material on this batch has been bought.
     *
     * Allowed on a batch nobody has marked quoted: a shop that rings the merchant and buys in the one
     * call has not skipped a step this application can see, and refusing would make the page insist on
     * a mark that records nothing anybody did.
     */
    public function markBatchOrdered(User $user, Batch $batch): bool
    {
        return $this->canMarkBatchMilestone($user, $batch)
            && $batch->ordered_at === null
            && $batch->delivered_at === null;
    }

    /**
     * And "Delivered" - the steel on this batch is in the rack.
     *
     * The third of the supplier-free marks, and the last one that only a shop buying off the
     * application needs: a batch ordered through the quotes and orders screen reaches Delivered on its
     * goods receipts, and this is for the batch that has no order to book in.
     *
     * Offered from Quoting, Quoted and Ordered alike, the way "All ordered" is offered without "All
     * quoted" first. A shop that rings the merchant on Monday and takes delivery on Friday has not
     * skipped a step this application can see, and insisting on the marks in order would only teach
     * people to press all three in a row to get to the true one.
     */
    public function markBatchDelivered(User $user, Batch $batch): bool
    {
        return $this->canMarkBatchMilestone($user, $batch) && $batch->delivered_at === null;
    }

    /**
     * And the ordered mark said of one merchant - the order list's "Ordered" on a single block.
     *
     * Deliberately not behind canMarkBatchMilestone. That gate refuses the moment any real order has
     * gone out on the batch, which is right for a mark that speaks for the whole job and wrong for
     * one that speaks for a single merchant: the common shop buys its steel through the quotes screen
     * and its timber over the phone, and that timber block has to be markable while the steel block
     * has an order behind it.
     *
     * Whether this particular group is already bought - a sent order of its own, or this mark set
     * before - is the caller's question, the group being the thing it knows about. See
     * BatchMarkGroupOrderedController.
     */
    public function markBatchGroupOrdered(User $user, Batch $batch): bool
    {
        return $this->canChangeBatchItself($user, $batch);
    }

    /**
     * And "Cut" - the saw has been through it.
     *
     * The one mark that is not about buying, which is why it does not sit behind
     * canMarkBatchMilestone's last condition: cutting happens on every job, and a batch ordered
     * through the application and booked in on its goods receipts is exactly as cuttable as one bought
     * over the phone. The sent orders it carries are the reason it is cut, not a reason to refuse.
     *
     * What it does insist on is the step before it - $batch->isDelivered(), which is the DELIVERED
     * pill either way round. A shop cannot cut steel that has not turned up, and a card offering "Cut"
     * over "Ordering" would be inviting somebody to say so.
     */
    public function markBatchCut(User $user, Batch $batch, bool $batchIsDelivered): bool
    {
        return $this->canChangeBatchItself($user, $batch)
            && $batch->cut_at === null
            && $batchIsDelivered;
    }

    /**
     * What the buying marks need, which is what every press on a batch needs.
     *
     * 1) BUSINESS: is your business
     * 2) USER: you're a PM on at least 1 project of the batch
     * 3) PROJECT: no project on the batch is archived
     * 4) BATCH: the batch is live
     * 5) BATCH: nothing has been ordered through a supplier on it
     *
     * The first four are canChangeBatchItself's. The last one keeps the two ways of buying from
     * talking over each other: once a real order has gone out the batch is past the Quoting column,
     * the card stops offering these at all, and what the pill says is read off the orders and their
     * deliveries - a mark set then could only contradict them.
     *
     * Not shared with "Cut", which is the mark that is not about buying - see markBatchCut.
     */
    private function canMarkBatchMilestone(User $user, Batch $batch): bool
    {
        //5) BATCH: nothing ordered through a supplier
        return $this->canChangeBatchItself($user, $batch)
            && $batch->orders()->where('order_sent', true)->doesntExist();
    }

    /**
     * And what every press on a batch needs, whatever it records.
     *
     * 1) BUSINESS: is your business
     * 2) USER: you're a PM on at least 1 project of the batch
     * 3) PROJECT: no project on the batch is archived
     * 4) BATCH: the batch is live
     *
     * The first three are canChangeQuoteSentState's, worded the same way and for the same reason: a
     * mark that moves the batch on is the same decision as ticking every supplier on it, and it would
     * be a strange application that let a colleague with no job on the batch do by one press what the
     * quotes modal refuses them row by row.
     */
    private function canChangeBatchItself(User $user, Batch $batch): bool
    {
        //1) BUSINESS: is your business
        if ($batch->user->business->id !== $user->business->id) {
            return false;
        }

        //2) USER: you're a PM on at least 1 project
        $projects = $batch->projectApprovalFlags();

        if (! $projects->contains(fn (Project $project) => $project->user_id === $user->id)) {
            return false;
        }

        //3) PROJECT: all projects not archived
        if ($projects->contains(fn (Project $project) => (bool) $project->archive)) {
            return false;
        }

        //4) BATCH: live
        return ! $batch->done;
    }

    public function markQuoteAsSent(User $user, Quote $quote): bool
    {
        //4) QUOTE: quote is not sent
        return $this->canChangeQuoteSentState($user, $quote) && ! $quote->quote_sent;
    }

    public function undoMarkQuoteAsSent(User $user, Quote $quote): bool
    {
        //4) QUOTE: quote is sent
        return $this->canChangeQuoteSentState($user, $quote) && $quote->quote_sent;
    }

    private function canChangeQuoteSentState(User $user, Quote $quote): bool
    {
        /**
         * Everything markQuoteAsSent and undoMarkQuoteAsSent share. Only condition 4 differs between
         * them, and keeping two copies is how condition 2 came to contradict its own description in
         * both of them.
         *
         * 1) BUSINESS: is your business
         * 2) USER: you're a PM on at least 1 project
         * 3) PROJECT: project is not archived
         * 5) ORDER: order not sent
         * 6) ORDER: order not delivered
         */
        $batch = $quote->batch;
        $order = $quote->order;

        //Both are optional on a quote, and conditions 2, 3, 5 and 6 all need them
        if (! $batch || ! $order) {
            return false;
        }

        //1) BUSINESS: is your business
        $condition_1 = $quote->user->business->id === $user->business->id;

        /*
         * 2) USER: You're a PM on at least 1 project
         * This read "set false unless you own every project", which deadlocked any batch spanning two
         * project managers - nobody could mark a quote sent. startQuoting spells the same sentence the
         * other way round, and that is the one that matches the description.
         */
        $condition_2 = false;
        foreach($batch->projectApprovalFlags() as $project){
            if($project->user_id === $user->id){
                $condition_2 = true;
            }
        }

        //3) PROJECT: all projects not archived
        $condition_3 = true;
        foreach($batch->projectApprovalFlags() as $project){
            if($project->archive){
                $condition_3 = false;
            }
        }

        //5) ORDER: order not sent
        $condition_5 = !$order->order_sent;

        //6) ORDER: order not delivered
        $condition_6 = !$order->is_delivered;

        return
            $condition_1 &&
            $condition_2 &&
            $condition_3 &&
            $condition_5 &&
            $condition_6;
    }
}
