<?php

namespace App\Services\NotificationImplementations;

use App\Models\Batch;
use App\Models\Project;
use App\Models\User;
use App\Notifications\ColleagueQuotedYourMaterials;
use Illuminate\Notifications\Notification;

/**
 * The A_COLLEAGUE_QUOTED_YOUR_MATERIALS case from NotificationEnums, finally built.
 *
 * Fired by QuoteController::store, which is "Start quoting": it takes every project in the Nesting
 * column into one batch owned by whoever pressed it. See ColleagueQuotedYourMaterials for what that
 * costs the owner of a project that gets swept in, and ColleagueActionImplementation for the parts
 * this shares with the ordering one.
 */
class NotificationColleagueQuotedImplementation extends ColleagueActionImplementation
{
    public function getNotificationClass(): string
    {
        return 'ColleagueQuotedYourMaterials';
    }

    protected function notification(Project $project, Batch $batch, User $colleague): Notification
    {
        return new ColleagueQuotedYourMaterials($project, $batch, $colleague);
    }

    public function message(string $string_1, string $string_2): string
    {
        $colleagueName = $string_1;
        $projectName = $string_2;

        return $colleagueName.' started quoting a batch that includes "'.$projectName
            .'", so its suppliers and delivery dates are now set by that batch.';
    }
}
