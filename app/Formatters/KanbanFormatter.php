<?php

namespace App\Formatters;

use App\Enums\SupplierGroupEnums;
use App\Http\Resources\ProjectResource;
use App\Models\Business;
use App\Models\Offcut;
use App\Models\Project;
use App\Models\User;
use App\PrerequisiteConditions\PrerequisiteConditions;
use App\Services\BatchService;
use App\Services\OrderService;
use App\Services\QuoteService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class KanbanFormatter
{
    public function newProjectsColumn(Business $business): AnonymousResourceCollection
    {
        return ProjectResource::collection(Project::query()
            //Clarifications required OR no rawMaterialQuotes
            ->where(function($q) use($business){
                $q->whereIn("id",$business->projectsRequiringClarification()->pluck("id")->toArray())
                    ->orWhere(function($qq){
                        $qq->doesntHave('rawMaterialQuotes');
                    });
            })
            ->thisBusiness($business)
            ->where("archive",false)
            ->sortByUserAndLatest()
            ->get());
    }

    public function readyForNestingColumn(Business $business): array
    {
        $piecesReadyForBatching = (new NestingFormatter())->piecesReadyForBatching($business);

        return [
            'projects' => ProjectResource::collection($business
                ->projectsReadyForBatching($piecesReadyForBatching,$business)
                ->sortBy('created_at')),
        ];
    }

    public function quotedColumn(Business $business, User $user): array
    {
        /*
         * Services
         */
        $batchService = new BatchService;
        $quoteFormatter = new QuoteFormatter();
        $quoteService = new QuoteService;

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

            /**
             * "Quotes and orders"
             * 1) Assign a letter to each project. A, B, C, etc
             * 2) Get all nested pieces
             * 3) Group nested pieces by nesting algorithm. e.g "meterage"
             * 4) get list of supplier categories available to the business
             * 5) filter out categories not features in the nesting list
             */
            $quotesData = $quoteFormatter->quotesData($business, $batch, $user);

            $quoted[$batch->id] = [
                'info' => [
                    'batch' => [
                        'id' => $batch->id,
                        'totalPurchasedMaterial' => 999, //todo
                        'totalUsage' => 999, //todo
                        'totalWaste' => 999, //todo
                    ],
                    'projects' => ProjectResource::collection($batch->projects()),
                    'quotes' => $batch->quotes,
                    'totalQuotesQty' => $batch->quotes()->count(),
                    'sentQuotesQty' => $batch->quotes()->where('quote_sent', true)->count(),
                    'batchQuotingDeadline' => $quoteService->batchQuotingDeadline($batch),
                    "prerequisiteUndoStartQuoting" => $prerequisiteUndoStartQuoting,
                    "quotesData" => $quotesData,
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
            //Total orders qty
            $orders = $batch->orders;
            $totalOrdersQty = $batchService->totalOrdersQty($batch);

            $ordered[$batch->id] = [
                'info' => [
                    'batch' => [
                        'id' => $batch->id,
                        'totalPurchasedMaterial' => 999, //todo
                        'totalUsage' => 999, //todo
                        'totalWaste' => 999, //todo
                    ],
                    'projects' => ProjectResource::collection($batch->projects()),
                    'orders' => $orders,
                    'approxDueDate' => null, //todo actual - derived from earliest project
                    'totalOrdersQty' => $totalOrdersQty,
                    'sentOrdersQty' => $batch->orders()->where('order_sent', true)->count(),
                    'all_project_manager_approvals' => (new OrderService)->allProjectManagersApproved($batch),
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
            //Total orders qty
            $orders = $batch->orders;
            $totalOrdersQty = $batchService->totalOrdersQty($batch);
            $totalDeliveredQty = $batch->orders()->where('is_delivered', true)->count();
            $steelMerchantDeliveredButNoCertsYet = $batch->orders()
                ->whereRelation("quote","supplier_category","=",SupplierGroupEnums::STEEL_MERCHANT->value)
                ->where("material_cert_numbers",null)
                ->exists();

            $delivered[$batch->id] = [
                'info' => [
                    'batch' => [
                        'id' => $batch->id,
                        'totalPurchasedMaterial' => 999, //todo
                        'totalUsage' => 999, //todo
                        'totalWaste' => 999, //todo
                    ],
                    'projects' => ProjectResource::collection($batch->projects()),
                    'orders' => $orders,
                    'approxDueDate' => null, //todo actual - derived from earliest project
                    'totalOrdersQty' => $totalOrdersQty,
                    'sentOrdersQty' => $batch->orders()->where('order_sent', true)->count(),
                    "totalDeliveredQty" => $totalDeliveredQty,
                    "allDelivered" => $totalDeliveredQty === $totalOrdersQty,
                    "steelMerchantDeliveredButNoCertsYet" => $steelMerchantDeliveredButNoCertsYet,
                    'all_project_manager_approvals' => (new OrderService)->allProjectManagersApproved($batch),
                ],
            ];
        }

        return array_values($delivered);
    }
}
