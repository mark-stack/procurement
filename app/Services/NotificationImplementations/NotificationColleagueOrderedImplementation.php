<?php

namespace App\Services\NotificationImplementations;

use App\Models\Batch;
use App\Models\Project;
use App\Models\User;
use App\Notifications\ColleagueOrderedYourMaterials;
use Illuminate\Notifications\Notification;

/**
 * The A_COLLEAGUE_ORDERED_YOUR_MATERIALS case from NotificationEnums.
 *
 * Fired by OrderSentController, which is "Sent order": it marks the order sent and settles the
 * order approval for every project on the batch on one person's press. See
 * ColleagueOrderedYourMaterials for why this reports the press rather than asking for approval,
 * and ColleagueActionImplementation for the shared machinery.
 */
class NotificationColleagueOrderedImplementation extends ColleagueActionImplementation
{
    public function getNotificationClass(): string
    {
        return 'ColleagueOrderedYourMaterials';
    }

    protected function notification(Project $project, Batch $batch, User $colleague): Notification
    {
        return new ColleagueOrderedYourMaterials($project, $batch, $colleague);
    }

    public function message(string $string_1, string $string_2): string
    {
        $colleagueName = $string_1;
        $projectName = $string_2;

        return $colleagueName.' sent the order for a batch that includes "'.$projectName
            .'", which approved the ordering on your behalf.';
    }
}
