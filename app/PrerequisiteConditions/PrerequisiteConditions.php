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

        return
            $condition_1 &&
            $condition_2 &&
            $condition_3 &&
            $condition_4 &&
            $condition_5 &&
            $condition_6 &&
            $condition_7 &&
            $condition_8;
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
