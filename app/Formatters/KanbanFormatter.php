<?php

namespace App\Formatters;

use App\Enums\SupplierGroupEnums;
use App\Http\Resources\ProjectResource;
use App\Http\Resources\UnfinishedImportResource;
use App\Models\Business;
use App\Models\Offcut;
use App\Models\Project;
use App\Models\User;
use App\PrerequisiteConditions\PrerequisiteConditions;
use App\Services\BatchService;

class KanbanFormatter
{
    public function readyForNestingColumn(Business $business, User $user): array
    {
        $piecesReadyForBatching = (new NestingFormatter())->piecesReadyForBatching($business);

        /*
         * Your own projects first, then oldest first within each group.
         *
         * This column is the whole business's, drawn as one undifferentiated pile in creation
         * order - so on a board with a few colleagues on it your own work was wherever it
         * happened to land. The three batch columns below already sort this way, through
         * BatchService::sortByUserAndLatest; only the column of projects did not.
         */
        $projects = $business->projectsReadyForBatching($piecesReadyForBatching)
            ->sortBy(fn (Project $project) => [
                $project->user_id === $user->id ? 0 : 1,
                $project->created_at->timestamp,
                //Two projects created in the same second would otherwise order arbitrarily
                $project->id,
            ])
            ->values();

        return [
            'projects' => ProjectResource::collection($projects),
            /*
             * Imports that stopped at a clarification. These are excluded from the column above -
             * and from everywhere else in the app - so without this they are on nobody's board at
             * all. See Business::projectsWithUnfinishedImport().
             */
            'unfinishedImports' => UnfinishedImportResource::collection(
                $business->projectsWithUnfinishedImport()
            ),
        ];
    }

    public function quotedColumn(Business $business, User $user): array
    {
        /*
         * Services
         */
        $batchService = new BatchService;

        $quoted = [];
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
        $batchesForOrdering = [];
        foreach($business->batches()->active()->get() as $batch){
            //Less than 100% order coverage
            $all100Percent = true;
            foreach($batch->projects() as $project){
                if($project->percentageOfMaterialsOrdered() !== 100){
                    $all100Percent = false;
                }
            }

            $sentOrdersQty = $batch->orders()->where('order_sent', true)->count();
            if(($sentOrdersQty > 0) && !$all100Percent){
                $batchesForOrdering[] = $batch;
            }
        }
        $batchesForOrdering = collect($batchesForOrdering);

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
        $batchesForDelivering = [];
        foreach($business->batches()->active()->get() as $batch){
            //100% order coverage
            $all100Percent = true;
            foreach($batch->projects() as $project){
                if($project->percentageOfMaterialsOrdered() !== 100){
                    $all100Percent = false;
                }
            }

            if($all100Percent){
                $batchesForDelivering[] = $batch;
            }
        }
        $batchesForDelivering = collect($batchesForDelivering);


        //Sort
        $batchesForDelivering = $batchService->sortByUserAndLatest($batchesForDelivering, $business);

        foreach ($batchesForDelivering as $batch) {
            /*
             * The card's "everything is in" test has to be the one MarkAsPastProjectController applies,
             * or the "Move to done" button it draws lies about what the post will do.
             *
             * This used to read "delivered rows === unique supplier categories", which compares two
             * different units. Orders are created one per quote and quotes one per supplier in a group
             * (QuoteFormatter), so two steel merchants delivered on the one batch read 2 === 1 and the
             * batch could never be closed - and "done" is written nowhere else.
             */
            $allDelivered = ! $batch->orders()
                ->where('order_sent', true)
                ->where('is_delivered', false)
                ->exists();

            /*
             * Only a delivered order can be missing its certs. This matched any steel merchant row with
             * no cert numbers at all, and a draft order exists for every supplier in the group from the
             * first time the quote screen is opened - so a business with two steel merchants always had
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
                ],
            ];
        }

        return array_values($delivered);
    }
}
