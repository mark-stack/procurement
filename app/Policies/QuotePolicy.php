<?php

namespace App\Policies;

use App\Models\Quote;
use App\Models\User;

class QuotePolicy
{
    public function owned(User $user, Quote $quote): bool
    {
        /**
         * Is a user of this business
         */
        return $quote->user->business->id === $user->business->id;
    }
}
