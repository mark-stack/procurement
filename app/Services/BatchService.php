<?php

namespace App\Services;

use App\Models\Batch;
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
        $batchIdsThisQuery = $batches->pluck('id')->toArray();

        /*
         * Array of project user vs batch
         */
        $allInternalProjects = [];
        foreach ($business->batches as $batch) {
            foreach ($batch->projects() as $project) {
                //In this query
                if (in_array($batch->id, $batchIdsThisQuery)) {
                    $allInternalProjects[] = [
                        'batch_id' => $batch->id, //The order of this is like 'created_at'
                        'thisUser' => $project->user_id === $user->getKey(),
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
        foreach ($allInternalProjects as $item) {
            $batchId = $item['batch_id'];
            if (! in_array($batchId, $batchIdsInOrder)) {
                $batchIdsInOrder[] = $batchId;
            }
        }

        return $batchIdsInOrder
            //Has batch IDs for this user (order by user and batch)
            ? $batches->sortBy(function ($item) use ($batchIdsInOrder) {
                return array_search($item->id, $batchIdsInOrder);
            })->values()

            //No batch IDs for this user (order by batch only)
            : $batches->sortByDesc('id');
    }

    public function projectManagerApprovalMessage(Batch $batch): string
    {
        /**
         * What the confirmation in front of "Sent order" is entitled to say.
         *
         * It used to ask "Do Bruce,Matt and yourself approve ordering materials?" - a question put
         * to one person about three people's intentions, answered by that one person, after which
         * every project on the batch was recorded as approved by its manager. Bruce and Matt were
         * neither asked nor told. The only honest reading of the button is that the person
         * pressing it is ordering on their colleagues' behalf, so that is what it now says.
         *
         * (The list also read "Bruce,Matt" against a docblock promising "Bruce, Matt, and
         * yourself" - implode() with no space.)
         */

        //Get all projects for this batch
        $otherProjectManagers = [];
        $projects = $batch->projects();
        foreach ($projects as $project) {
            //Not yourself
            if ($project->user->id !== auth()->user()->id) {
                //Not already in array
                if (! in_array($project->user->name, $otherProjectManagers)) {
                    $otherProjectManagers[] = $project->user->name;
                }
            }

        }

        //Just you - nobody else's project is on this batch, so there is nobody to speak for
        if (count($otherProjectManagers) === 0) {
            return 'This marks the order as placed. Do you want to go ahead?';
        }

        //Has others
        $names = collect($otherProjectManagers)->sort()->values()->join(', ', ' and ');

        return "This batch also carries {$names}'s work, and marking the order placed commits it "
            .'on their behalf. They will not be asked. Go ahead?';
    }

    public function totalOrdersQty(Batch $batch): int
    {
        /**
         * Count total orders required for this batch.
         * Note: not all order objects are actually ordered, so count the unique supplier categories. e,g steel merchant, fasteners
         *
         * quote_id is nullable, so an order without a quote carries no supplier category and is not
         * counted - reading through it unguarded was a fatal waiting for the first such order.
         */
        return $batch->orders()
            ->with('quote:id,supplier_category')
            ->get()
            ->map(fn ($order) => $order->quote?->supplier_category)
            ->filter()
            ->unique()
            ->count();
    }
}
