<?php

namespace App\Policies;

use App\Models\Project;
use App\Models\RawMaterialQuote;
use App\Models\User;

class RawMaterialQuotePolicy
{
    public function owned(User $user, RawMaterialQuote $rawMaterialQuote): bool
    {
        /**
         * Is a user of this business
         */
        return $rawMaterialQuote->project->user->business->id === $user->business->id;
    }
}
