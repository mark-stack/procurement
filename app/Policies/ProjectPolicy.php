<?php

namespace App\Policies;

use App\Models\Project;
use App\Models\User;

class ProjectPolicy
{
    public function owned(User $user, Project $project): bool
    {
        /**
         * Is a user of this business
         */
        return $project->user->business->id === $user->business->id;
    }
}
