<?php

namespace App\Policies;

use App\Models\Batch;
use App\Models\Business;
use App\Models\User;

class BusinessPolicy
{
    public function owned(User $user, Business $business): bool
    {
        /**
         * Is a user of this business
         */
        return $business->id === $user->business->id;
    }
}
