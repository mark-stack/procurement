<?php

namespace App\PrerequisiteConditions;

use App\Models\Batch;
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
         * 6) PROJECT: All projects not done
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

        //6) All projects not done
        $condition_6 = $projectsReadyForBatching->where("done",true)->count() === 0;

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
         * 3) PROJECT: All projects not done
         * 4) ORDER: no order sent
         * 5) OFFCUT: Are your offcuts being released
         * 6) OFFCUT: "allocated_to" is this batch (released from)
         * 7) RAWMATERIALQUOTE: All materials are assigned to this batch
         * 8) OFFCUT: None of the offcuts this batch produced has been consumed downstream
         * 9) BATCH: Nobody has recorded buying any of it off the application
         */

        /*
         * 1-3) The projects on the batch: whose business they are, whether one of them is yours, and
         * whether any has been marked done.
         *
         * Read off projectApprovalFlags(), which is the three columns these conditions actually
         * touch with the owner's business beside them. This walked $batch->projects() three times -
         * memoised, so one load, but that load eager-fetches the rawMaterialQuotes/piece/order tree
         * and the uploader for every project on the batch, which is what ProjectResource needs and
         * none of what is read here. On the Nesting page that tree was being built per card.
         */
        $projects = $batch->projectApprovalFlags();

        //1) PROJECT: All projects belong to your business
        $condition_1 = true;
        foreach($projects as $project){
            if($project->user->business_id !== $user->business_id){
                $condition_1 = false;
            }
        }

        //2) PROJECT: At least project is yours
        $condition_2 = false;
        foreach($projects as $project){
            if($project->user_id === $user->id){
                $condition_2 = true;
            }
        }

        //3) PROJECT: All projects not done
        $condition_3 = true;
        foreach($projects as $project){
            if($project->done){
                $condition_3 = false;
            }
        }

        //4) ORDER: no order sent
        //A single-argument where() compiles to "order_sent is null", and the column is a non-nullable
        //boolean - so this counted 0 every time and let a batch be unwound after it had been ordered
        $condition_4 = ! $batch->hasSentOrder();

        //5) OFFCUT: Are your offcuts being released
        //6) OFFCUT: "allocated_to" is this batch (released from)
        //One pass for both - batchTo() is a Batch::find, so checking them separately doubled the queries
        $condition_5 = true;
        $condition_6 = true;
        foreach($offcutsAssignedToThisBatch as $offcut){
            /*
             * batchTo() is a Batch::find, so the batch in hand answers for itself rather than being
             * fetched again - which is every offcut in the collection the Nesting page passes, it
             * having selected them on batch_to_id. Nullable either way: a released offcut with no
             * destination belongs to nobody.
             */
            $batchTo = (int) $offcut->batch_to_id === (int) $batch->id
                ? $batch
                : $offcut->batchTo();

            if(!$batchTo){
                $condition_5 = false;
                $condition_6 = false;
                continue;
            }

            $businessOwnership = $batchTo->user->business_id === $user->business_id;
            if(!$businessOwnership){
                $condition_5 = false;
            }

            $batchOwnership = $batchTo->id === $batch->id;
            if(!$batchOwnership){
                $condition_6 = false;
            }
        }

        /*
         * 7) All materials are assigned to this batch.
         *
         * True by construction. $batch->pieces is a hasMany keyed on batch_id, so every row it returns carries
         * this batch's id - the test it used to make, $piece->batch->id !== $batch->id, could not
         * come out false. What it could do was cost a batch lookup per piece: a belongsTo walked
         * lazily, once for every cut on the job, which on a real material list is hundreds of queries
         * behind a menu item that only wants to know whether to grey itself out.
         *
         * So there is no $condition_7 below. The number is kept here, rather than the nine being
         * renumbered, so this block still reads against the list at the top of the method.
         */

        /*
         * 8) OFFCUT: None of the offcuts this batch produced has been consumed downstream.
         *
         * Unwinding the batch un-nests it, so the offcuts it produced were never cut and get deleted
         * with it. If a later batch has already nested into one of them, deleting it would strip that
         * batch of the offcut and of the certificate trail behind it.
         */
        $condition_8 = ! $batch->producedOffcutsConsumed();

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
            $condition_8 &&
            $condition_9;
    }

    public function uploadMaterials(User $user, Project $project): bool
    {
        /**
         * 1) BUSINESS: Your business
         * 2) PROJECT: Yours to change - its manager, or whoever uploaded it for them
         * 3) PROJECT: Not done
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

        //3) PROJECT: Not done
        $condition_3 = !$project->done;

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
         * The same answer markProjectDone gives, and for the same reason. The Nesting column is
         * shared, so every colleague's project carried a live Edit button beside a greyed-out
         * Done one - and Edit is not the smaller of the two. The name is how the rest of the
         * business recognises the project on the board and in Past Batches, and
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

    /**
     * May this user move the day this job's fabrication begins?
     *
     * Its manager, while there is still a batch the date could make a difference to.
     *
     * The date is not decoration: every deadline the Nesting page prints is counted back from it, and
     * a batch's day is the earliest fabrication date among the jobs on it, so moving one re-dates the
     * whole batch for every colleague whose work is on it. While the steel is still being quoted,
     * bought or waited on, that is exactly right - jobs slip, and the page has to say so.
     *
     * Once it has all arrived it is not. A delivered or cut batch has met its date or missed it, and
     * the race is over; re-dating it then does not change what anybody has to do, it changes what the
     * record says was asked for. The card goes on printing a required-by date, so the page would be
     * showing steel that is in the rack as having been wanted on a day it was not. That is the one
     * reading of this edit that cannot be undone by making the next decision differently, which is
     * why it is the only one refused.
     *
     * The name and the reference stay editable throughout - they are how the business recognises the
     * job, and a job finishing is no reason to be stuck with a typo in it.
     *
     * 1) PROJECT: This user may edit it at all - its manager, in their own business
     * 2) BATCH: At least one batch carrying its steel is still live, or it has no batch yet
     */
    public function moveFabricationDate(User $user, Project $project): bool
    {
        //1) PROJECT: This user may edit it at all
        $condition_1 = $this->editProject($user, $project);

        //2) BATCH: Something is still outstanding
        $batchIds = $project->pieces()
            ->whereNotNull('batch_id')
            ->distinct()
            ->pluck('batch_id');

        /*
         * Nothing nested yet is the ordinary case - a project in the Nesting column, where the date
         * is doing the most work it ever does. It answers yes without a batch to ask about.
         */
        $condition_2 = $batchIds->isEmpty() || Batch::query()
            ->whereIn('id', $batchIds)
            ->get()
            ->contains(fn (Batch $batch) => ! $batch->done
                && $batch->cut_at === null
                && ! $batch->isDelivered($user->business));

        return
            $condition_1 &&
            $condition_2;
    }

    public function markProjectDone(User $user, Project $project): bool
    {
        /**
         * Marking a project done takes it off the board for the whole business, and only its
         * owner can reopen it - so it is the owner's call. Nothing enforced that server side: the
         * button hid itself outside the Nesting column and the notification "Lost it" action
         * skipped even that, which is how a batch could end up permanently un-re-nestable
         * (undoStartQuoting condition 3) because of a project nobody else could see, let alone
         * reopen.
         *
         * This was called archiveProject, and the column behind it projects.archive, until the
         * application settled on one word for a job being over - see the migration renaming it.
         *
         * Condition 4 used to read "no batch exists for this project", which locked the name of
         * every project that ever finished. Nesting writes a batch_id onto the pieces and nothing
         * ever clears it, so a project that went all the way through - quoted, ordered, delivered,
         * "Move to done" - failed that test for good. Both project forms exclude the names of
         * projects marked done from their uniqueness check and tell the user so ("ones marked done
         * are free to reuse"), but the one kind of project whose name you actually want back could
         * never be marked done to free it. The card's tooltip offered "re-nest the batch first",
         * and MarkAsDone is a one-way door off the board, so there was no batch left to re-nest
         * either.
         *
         * What the condition is really protecting is undoStartQuoting condition 3: a project taken
         * out from under a batch still being worked leaves that batch un-re-nestable, and
         * invisible to everyone but the owner. A done batch is not being worked on - nothing
         * re-nests it and nothing quotes it - so it is no longer a reason to refuse.
         *
         * 1) BUSINESS: Your business
         * 2) PROJECT: Your project
         * 3) PROJECT: Not already done
         * 4) PIECE: No piece of this project is on a batch that is still live
         */

        //1) BUSINESS: Your business
        $condition_1 = $project->user->business->id === $user->business->id;

        //2) PROJECT: Your project
        $condition_2 = $project->user->id === $user->id;

        //3) PROJECT: Not already done
        $condition_3 = !$project->done;

        /*
         * 4) PIECE: No piece of this project is on a batch that is still live
         *
         * Strictly looser than the old test, so a project that never reached a batch still passes
         * here the way it always did - it has no pieces on a live batch either.
         */
        $condition_4 = $project->pieces()
            ->whereNotNull("batch_id")
            ->whereHas("batch", fn ($query) => $query->where("done", false))
            ->doesntExist();

        return
            $condition_1 &&
            $condition_2 &&
            $condition_3 &&
            $condition_4;
    }

    public function reopenProject(User $user, Project $project): bool
    {
        /**
         * The way back from markProjectDone, so it asks for no more than that one did: a project
         * marked done while it was on a batch has to be reopenable, or the batch it is holding up
         * stays held up forever.
         *
         * The name clash is deliberately not one of these conditions - it is answered separately by
         * reopenProjectNameIsFree() below, because it is the one obstacle the owner can clear
         * themselves and so is worth a sentence rather than a 403.
         *
         * 1) BUSINESS: Your business
         * 2) PROJECT: Your project
         * 3) PROJECT: Done
         */

        //1) BUSINESS: Your business
        $condition_1 = $project->user->business->id === $user->business->id;

        //2) PROJECT: Your project
        $condition_2 = $project->user->id === $user->id;

        //3) PROJECT: Done
        $condition_3 = (bool) $project->done;

        return
            $condition_1 &&
            $condition_2 &&
            $condition_3;
    }

    /**
     * Is this done project's name still free on the board it wants to come back to?
     *
     * Both project forms exclude the names of projects marked done from their uniqueness check, and
     * say so out loud: "ones marked done are free to reuse". That is the intended behaviour and it
     * is what opens this - mark "Tower A" done, give a new project the freed-up name, reopen the old
     * one, and the shared Nesting column has two live projects called "Tower A" belonging to
     * different jobs.
     *
     * Nothing downstream can tell them apart for a person. The board draws two identical cards, Past
     * Projects lists the name twice, and a colleague pressing "Start quoting" nests both into one
     * batch - where the cut drawings label their pieces by letter but the spec sheet, the BOM
     * download and every notification name the project. Steel gets cut for the wrong Tower A.
     *
     * Refusing the reopen is safe in a way that refusing to mark done would not be: the owner can
     * rename a project that is done (editProject does not ask whether it is), so there is always a
     * way through, and a batch held up by a done project can never reach this - a project on a live
     * batch cannot be marked done through markProjectDone in the first place.
     */
    public function reopenProjectNameIsFree(Project $project): bool
    {
        return ! $project->user->business->projects()
            ->where('projects.done', false)
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
     *
     * And refused once every merchant on the batch has been priced one at a time - $everyGroupPriced,
     * which is Batch::everySupplierGroupPriced for the press and the card's own pill for the menu. A
     * shop that works the order list block by block arrives at the same place this press jumps to, and
     * the menu item was still sitting there offering to do it again: the card already read Quoted, or
     * Ordered, over a live "All quoted". The caller is given the question because the page has already
     * answered it for the whole column and must not be made to ask again per card.
     */
    public function markBatchQuoted(User $user, Batch $batch, bool $everyGroupPriced): bool
    {
        return $this->canMarkBatchMilestone($user, $batch)
            && ! $everyGroupPriced
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
     *
     * Refused, like the quoted one above, once every merchant on the batch has been bought from one
     * at a time - $everyGroupBought, which is Batch::everySupplierGroupBought for the press and the
     * card's own answer for the menu. Note that being fully priced is no bar at all: a batch with
     * every price in is exactly the batch somebody is about to buy.
     */
    public function markBatchOrdered(User $user, Batch $batch, bool $everyGroupBought): bool
    {
        return $this->canMarkBatchMilestone($user, $batch)
            && ! $everyGroupBought
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
     *
     * Refused, like the two above it, once every merchant on the batch has been marked delivered one
     * at a time - $everyGroupDelivered, which is Batch::everySupplierGroupDelivered for the press and
     * the card's own answer for the menu. A shop working the order list block by block arrives where
     * this press jumps to, and the item left live underneath it would be offering to record what the
     * card's pill is already saying.
     */
    public function markBatchDelivered(User $user, Batch $batch, bool $everyGroupDelivered): bool
    {
        return $this->canMarkBatchMilestone($user, $batch)
            && ! $everyGroupDelivered
            && $batch->delivered_at === null;
    }

    /**
     * And the ordered mark said of one merchant - the order list's "Ordered" on a single block.
     *
     * Deliberately not behind canMarkBatchMilestone. That gate refuses the moment any real order has
     * gone out on the batch, which is right for a mark that speaks for the whole job and wrong for
     * one that speaks for a single merchant: the common shop buys its steel through the quotes screen
     * and its bolts over the counter, and that fastener block has to be markable while the steel
     * block has an order behind it.
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
     * And the quoted mark said of one merchant - the order list's "Quoted" on a single block.
     *
     * The same gate as the ordered one above, for the same reason: this speaks for a merchant rather
     * than for the job, so a sent order on another block of the batch is none of its business.
     *
     * Looser than markBatchQuoted, which refuses once the batch has been called quoted, ordered or
     * delivered outright. Those three are the whole job, and a second claim about the whole job is a
     * contradiction; a merchant's price coming in after somebody pressed "All ordered" is just a late
     * price on a job that was bought, and recording it costs nothing. Whether this particular group
     * is already priced is the caller's question - see BatchMarkGroupQuotedController.
     */
    public function markBatchGroupQuoted(User $user, Batch $batch): bool
    {
        return $this->canChangeBatchItself($user, $batch);
    }

    /**
     * And the delivered mark said of one merchant - the order list's "Delivered" on a single block.
     *
     * The same gate as the two above, for the same reason: it speaks for a merchant, so a sent order
     * on another block of the batch is none of its business. The shop this is for buys its steel
     * through the quotes screen and its bolts over the counter, and the bolts turning up have to be
     * recordable while the steel is still booked in on a goods receipt.
     *
     * Whether this particular merchant may be marked at all is the caller's question, the group being
     * the thing it knows about - and there is one more of those here than for the other two marks.
     * A group with a sent order of its own is delivered by receiving that order
     * (OrderMarkDeliveredController), and the block refuses the mark rather than keeping two answers
     * to "has it arrived" that can disagree. See BatchMarkGroupDeliveredController.
     */
    public function markBatchGroupDelivered(User $user, Batch $batch): bool
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
     * And "Move to done" - the job is over and the batch belongs in Past Batches.
     *
     * The board's own button, which is where this lived until the board was deleted: the card that
     * carried it (KanbanMinimalCard.vue) went with the screen, and the route, the gate and the action
     * behind it were left with nothing calling them. Nothing else writes Batch::done - the auto-done
     * sweep aside - so for the weeks since, every batch a shop made stayed live for ever, the one page
     * in the application grew by a card a job, and Past Batches could never gain another row.
     *
     * Gated like "Cut" rather than like the buying marks, and for the same reason: closing a batch is
     * not a claim about how it was bought, so a batch ordered through the quotes screen and booked in
     * on its receipts is exactly as closeable as one bought over the phone. $batchIsDelivered is the
     * DELIVERED pill either way round - there is nothing to close while the steel is still out.
     *
     * Deliberately not insisting on the cut. Plenty of shops never press it, and a door off the page
     * that only opens for the shops that keep the application fully up to date is a door that leaves
     * everybody else's list growing - which is the state this is fixing.
     *
     * App\Actions\Batch\MarkAsDone asks the other half, the half this cannot see: whether a sent order
     * on the batch is still out for delivery. Both have to pass, and that one is also what the
     * auto-done sweep applies on the business's behalf.
     */
    public function markBatchDone(User $user, Batch $batch, bool $batchIsDelivered): bool
    {
        return $this->canChangeBatchItself($user, $batch) && $batchIsDelivered;
    }

    /**
     * What the buying marks need, which is what every press on a batch needs.
     *
     * 1) BUSINESS: is your business
     * 2) USER: you're a PM on at least 1 project of the batch
     * 3) PROJECT: no project on the batch is done
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
            && ! $batch->hasSentOrder();
    }

    /**
     * And what every press on a batch needs, whatever it records.
     *
     * 1) BUSINESS: is your business
     * 2) USER: you're a PM on at least 1 project of the batch
     * 3) PROJECT: no project on the batch is done
     * 4) BATCH: the batch is live
     *
     * The first three are canChangeQuoteSentState's, worded the same way and for the same reason: a
     * mark that moves the batch on is the same decision as ticking every supplier on it, and it would
     * be a strange application that let a colleague with no job on the batch do by one press what the
     * quotes modal refuses them row by row.
     */
    private function canChangeBatchItself(User $user, Batch $batch): bool
    {
        /*
         * 1) BUSINESS: is your business
         *
         * Off the foreign key rather than through the relation. Three of the four marks ask this gate
         * about the same batch while a page is drawn, and $batch->user->business was a business row
         * loaded per card to compare an id the user already carries.
         */
        if ($batch->user->business_id !== $user->business_id) {
            return false;
        }

        //2) USER: you're a PM on at least 1 project
        $projects = $batch->projectApprovalFlags();

        if (! $projects->contains(fn (Project $project) => $project->user_id === $user->id)) {
            return false;
        }

        //3) PROJECT: all projects not done
        if ($projects->contains(fn (Project $project) => (bool) $project->done)) {
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
         * 3) PROJECT: project is not done
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

        //3) PROJECT: all projects not done
        $condition_3 = true;
        foreach($batch->projectApprovalFlags() as $project){
            if($project->done){
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
