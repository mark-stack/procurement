<?php

namespace App\Services;

use App\Models\Business;
use Illuminate\Support\Collection;

class BatchService
{
    public function sortByUserAndLatest(Collection $batches, Business $business): Collection
    {
        /**
         * Put cards related to current user on top and in latest order.
         * Note it's not who created the batch, but who's a PM of a project inside the batch
         */

        $user = auth()->user();

        /*
         * batch ID's in this query
         */
        $batchIdsThisQuery = $batches->pluck("id")->toArray();

        /*
         * Array of project user vs batch
         */
        $allInternalProjects = [];
        foreach($business->batches as $batch){
            foreach($batch->projects() as $project){
                //In this query
                if(in_array($batch->id,$batchIdsThisQuery)){
                    $allInternalProjects[] = [
                        "batch_id" => $batch->id, //The order of this is like 'created_at'
                        "thisUser" => $project->user_id === $user->getKey(),
                    ];
                }
            }
        }

        /*
         * Sort this array prioritising this user, THEN batch id (highest batch ID = latest)
         */
        usort($allInternalProjects, function ($a, $b) {
            // Sort by `thisUser` descending (true comes first)
            if ($a['thisUser'] !== $b['thisUser']) {
                return $b['thisUser'] <=> $a['thisUser'];
            }

            // Secondary sort by `batch_id` descending order
            return $b['batch_id'] <=> $a['batch_id'];
        });

        /*
         * Get the unique batch ID in order from top to bottom.
         */
        $batchIdsInOrder = [];
        foreach($allInternalProjects as $item){
            $batchId = $item["batch_id"];
            if(!in_array($batchId,$batchIdsInOrder)){
                $batchIdsInOrder[] = $batchId;
            }
        }

        return $batchIdsInOrder
            //Has batch IDs for this user (order by user and batch)
            ? $batches->sortBy(function ($item) use ($batchIdsInOrder) {
                return array_search($item->id, $batchIdsInOrder);
            })->values()

            //No batch IDs for this user (order by batch only)
            : $batches->sortByDesc("id");
    }
}

