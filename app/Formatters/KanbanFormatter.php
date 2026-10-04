<?php

namespace App\Formatters;

use App\Enums\SupplierGroupEnums;
use App\Http\Resources\ProjectResource;
use App\Models\Batch;
use App\Models\Business;
use App\Models\Offcut;
use App\Models\Project;
use App\Models\User;
use App\PrerequisiteConditions\PrerequisiteConditions;
use App\Services\BatchService;
use App\Services\BatchStages;
use App\Services\DeliveredBatchAutoDone;
use App\Services\FabricationDeadlineQuoting;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class KanbanFormatter
{
    /**
     * The day this column has to stop waiting and buy.
     *
     * The earliest fabrication start date on the card, less the days
     * App\Services\FabricationDeadlineQuoting allows for quoting and delivery before it starts
     * chasing the column's manager. Computed here, off that same constant, rather than subtracting
     * five in the template - the board would otherwise go on promising a date the warnings had
     * stopped keeping to the moment anybody changed the window.
     *
     * Null when no project in the column has a fabrication date. That is only possible for projects
     * created before the date was asked for (see the add_date_fabrication_begins migration), and a
     * card of those genuinely has no deadline to print: nothing will come and chase them.
     *
     * Public because the Nesting page prints the same deadline on its pending card
     * (NestingIndexController), and a second subtraction of that constant somewhere else is exactly
     * what this method exists to prevent.
     *
     * @param  Collection<int, Project>  $projects
     */
    public function orderingTriggerDate(Collection $projects): ?string
    {
        $earliest = $projects
            ->pluck('date_fabrication_begins')
            ->filter()
            ->map(fn ($date) => Carbon::parse($date))
            ->min();

        return $earliest
            ?->subDays(FabricationDeadlineQuoting::DAYS_BEFORE_FABRICATION)
            ->toDateString();
    }

    public function quotedColumn(Business $business, User $user): array
    {
        /*
         * Services
         */
        $batchService = new BatchService;

        $quoted = [];
        /*
         * hasNoSentOrder() is BatchStages::QUOTING stated as a scope - the two have to keep saying the
         * same thing, since the dashboard counts this column through BatchStages.
         */
        $batchesForQuoting = $business->batches()
            ->hasNoSentOrder()
            ->active()
            ->get();

        //Sort
        $batchesForQuoting = $batchService->sortByUserAndLatest($batchesForQuoting, $business);

        foreach ($batchesForQuoting as $batch) {
            /**
             * Modal: "add quote requests"
             * table rows of each unique supplier-product_category.
             * e.g "ABC Steel" who does 'fasteners' and 'steel merchant' is 2 rows
             */

            /*
             * Prerequisite Gate
             */
            $offcutsAssignedToThisBatch = Offcut::query()
                ->where("batch_to_id",$batch->id)
                ->get();
            $prerequisiteUndoStartQuoting = (new PrerequisiteConditions())->undoStartQuoting(
                $batch,
                $user,
                $offcutsAssignedToThisBatch,
            );

            /*
             * Deliberately NOT quotesData() here.
             *
             * That method provisions a quote per supplier and an order per quote with firstOrCreate,
             * so calling it from the board minted rows for every batch in this column on every render
             * - on a GET, for batches nobody opened - and re-ran the whole nesting algorithm to do it
             * (~440ms of the ~485ms this page took). DownloadQuotesDataController is where it belongs:
             * the modal fetches it for the one batch the user actually opened. The card itself draws
             * none of it.
             */
            $quoted[$batch->id] = [
                'info' => [
                    'batch' => [
                        'id' => $batch->id,
                    ],
                    'projects' => ProjectResource::collection($batch->projects()),
                    "prerequisiteUndoStartQuoting" => $prerequisiteUndoStartQuoting,
                ],
            ];
        }

        return array_values($quoted);
    }

    public function orderedColumn(Business $business): array
    {
        /*
         * Services
         */
        $batchService = new BatchService;

        $ordered = [];
        /*
         * "At least one order sent, and less than 100% order coverage" - now asked by BatchStages,
         * which gives the same answer in two bounded queries rather than by loading every project of
         * every active batch with its material/piece/order tree and counting rows. The Delivering
         * column below asked the opposite half of the same question the same expensive way, so a
         * board with batches on it ran that walk twice per render.
         */
        $batchesForOrdering = $business->batches()
            ->active()
            ->get()
            ->filter(fn (Batch $batch) => (new BatchStages)->of($batch) === BatchStages::ORDERING)
            ->values();

        //Sort
        $batchesForOrdering = $batchService->sortByUserAndLatest($batchesForOrdering, $business);

        foreach ($batchesForOrdering as $batch) {
            $ordered[$batch->id] = [
                'info' => [
                    'batch' => [
                        'id' => $batch->id,
                    ],
                    'projects' => ProjectResource::collection($batch->projects()),
                ],
            ];
        }

        return array_values($ordered);
    }

    public function deliveredColumn(Business $business): array
    {
        /*
         * Services
         */
        $batchService = new BatchService;

        $delivered = [];
        //100% order coverage - see the note in orderedColumn() above
        $batchesForDelivering = $business->batches()
            ->active()
            ->get()
            ->filter(fn (Batch $batch) => (new BatchStages)->of($batch) === BatchStages::DELIVERING)
            ->values();


        //Sort
        $batchesForDelivering = $batchService->sortByUserAndLatest($batchesForDelivering, $business);

        foreach ($batchesForDelivering as $batch) {
            /*
             * The card's "everything is in" test has to be the one MarkAsPastProjectController applies,
             * or the "Move to done" button it draws lies about what the post will do.
             *
             * This used to read "delivered rows === unique supplier categories", which compared two
             * different units back when a group held a list of merchants and each of them got a quote
             * of their own: two steel merchants delivered on the one batch read 2 === 1 and the batch
             * could never be closed - and "done" is written nowhere else. One quote per group now, so
             * the two units agree, but the question asked is still the controller's own.
             */
            $allDelivered = ! $batch->orders()
                ->where('order_sent', true)
                ->where('is_delivered', false)
                ->exists();

            /*
             * Only a delivered order can be missing its certs. This matched any steel merchant row
             * with no cert numbers at all, drafts included - so a business with a draft row always had
             * one, and the warning stuck on with the button hidden behind it for good.
             *
             * An attached certificate file settles this as well as a written reference does, which is
             * what missingMaterialCerts() is for - see Order.
             */
            $steelMerchantDeliveredButNoCertsYet = $batch->orders()
                ->where('order_sent', true)
                ->where('is_delivered', true)
                ->whereRelation("quote","supplier_category","=",SupplierGroupEnums::STEEL_MERCHANT->value)
                ->missingMaterialCerts()
                ->exists();

            $delivered[$batch->id] = [
                'info' => [
                    'batch' => [
                        'id' => $batch->id,
                    ],
                    'projects' => ProjectResource::collection($batch->projects()),
                    "allDelivered" => $allDelivered,
                    "steelMerchantDeliveredButNoCertsYet" => $steelMerchantDeliveredButNoCertsYet,
                    /*
                     * The day this card closes itself and becomes a past project.
                     *
                     * Read off DeliveredBatchAutoDone rather than computed here, so the date the board
                     * promises is the date the schedule keeps - and null wherever that sweep holds off:
                     * a delivery still out, a receipt with no date behind it, or missing material certs.
                     * The card says nothing in those cases, which is correct: it is not going anywhere
                     * until somebody presses the button.
                     */
                    "doneDueDate" => (new DeliveredBatchAutoDone)->doneDueDate($batch)?->toDateString(),
                ],
            ];
        }

        return array_values($delivered);
    }
}
