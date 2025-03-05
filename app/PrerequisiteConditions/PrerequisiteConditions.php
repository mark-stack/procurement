<?php

namespace App\PrerequisiteConditions;

use App\Models\Batch;
use App\Models\Business;
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
        $condition_4 = $batch->orders()->where("order_sent")->count() === 0;

        //5) OFFCUT: Are your offcuts being released
        $condition_5 = true;
        foreach($offcutsAssignedToThisBatch as $offcut){
            $businessOwnership = $offcut->batchTo()->user->business->id === $user->business->id;
            if(!$businessOwnership){
                $condition_5 = false;
            }
        }

        //6) OFFCUT: "allocated_to" is this batch (released from)
        $condition_6 = true;
        foreach($offcutsAssignedToThisBatch as $offcut){
            $batchOwnership = $offcut->batchTo()->id === $batch->id;
            if(!$batchOwnership){
                $condition_6 = false;
            }
        }

        //7) All materials are assigned to this batch
        $condition_7 = true;
        foreach($batch->pieces as $piece){
            if($piece->batch->id !== $batch->id){
                $condition_4 = false;
            }
        }

        return
            $condition_1 &&
            $condition_2 &&
            $condition_3 &&
            $condition_4 &&
            $condition_5 &&
            $condition_6 &&
            $condition_7;
    }

    public function uploadMaterials(User $user, Project $project): bool
    {
        /**
         * 1) BUSINESS: Your business
         * 2) PROJECT: Your project
         * 3) PROJECT: Not archived
         * 4A) BATCH: No Batch exists for this project
         * 4B) No quote for this project (requires batch)
         * 4C) No order for this project (requires batch)
         */

        //1) BUSINESS: Your business
        $condition_1 = $project->user->business->id === $user->business->id;

        //2) PROJECT: Your project
        $condition_2 = $project->user->id === $user->id;

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
    public function archiveProject(): bool
    {

    }

    public function undoArchiveProject(): bool
    {

    }

    public function markQuoteAsSent(User $user, Quote $quote): bool
    {
        /**
         * 1) BUSINESS: is your business
         * 2) USER: you're a PM on at least 1 project
         * 3) PROJECT: project is not archived
         * 4) QUOTE: quote is not sent
         * 5) ORDER: order not sent
         * 6) ORDER: order not delivered
         */

        //1) BUSINESS: is your business
        $condition_1 = $quote->user->business->id === $user->business->id;

        //2) USER: You're a PM on at least 1 project
        $condition_2 = true;
        foreach($quote->batch->projects() as $project){
            if($project->user->id !== $user->id){
                $condition_2 = false;
            }
        }

        //3) PROJECT: all projects not archived
        $condition_3 = true;
        foreach($quote->batch->projects() as $project){
            if($project->archive){
                $condition_3 = false;
            }
        }

        //4) QUOTE: quote is not sent
        $condition_4 = !$quote->quote_sent;

        //5) ORDER: order not sent
        $condition_5 = !$quote->order->order_sent;

        //6) ORDER: order not delivered
        $condition_6 = !$quote->order->is_delivered;

        return
            $condition_1 &&
            $condition_2 &&
            $condition_3 &&
            $condition_4 &&
            $condition_5 &&
            $condition_6;
    }

    public function undoMarkQuoteAsSent(User $user, Quote $quote): bool
    {
        /**
         * 1) BUSINESS: is your business
         * 2) USER: you're a PM on at least 1 project
         * 3) PROJECT: project is not archived
         * 4) QUOTE: quote is sent
         * 5) ORDER: order not sent
         * 6) ORDER: order not delivered
         */

        //1) BUSINESS: is your business
        $condition_1 = $quote->user->business->id === $user->business->id;

        //2) USER: You're a PM on at least 1 project
        $condition_2 = true;
        foreach($quote->batch->projects() as $project){
            if($project->user->id !== $user->id){
                $condition_2 = false;
            }
        }

        //3) PROJECT: all projects not archived
        $condition_3 = true;
        foreach($quote->batch->projects() as $project){
            if($project->archive){
                $condition_3 = false;
            }
        }

        //4) QUOTE: quote is sent
        $condition_4 = $quote->quote_sent;

        //5) ORDER: order not sent
        $condition_5 = !$quote->order->order_sent;

        //6) ORDER: order not delivered
        $condition_6 = !$quote->order->is_delivered;

        return
            $condition_1 &&
            $condition_2 &&
            $condition_3 &&
            $condition_4 &&
            $condition_5 &&
            $condition_6;
    }

    public function markOrderAsSent(): bool
    {

    }

    public function undoMarkOrderAsSent(): bool
    {

    }

    public function markOrderAsDelivered(): bool
    {

    }

    public function markBatchAsComplete(): bool
    {

    }
}
