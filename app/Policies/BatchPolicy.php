<?php

namespace App\Policies;

use App\Models\Batch;
use App\Models\User;

class BatchPolicy
{
    public function owned(User $user, Batch $batch): bool
    {
        /**
         * Is a user of this business
         */
        return $batch->user->business->id === $user->business->id;
    }
}
