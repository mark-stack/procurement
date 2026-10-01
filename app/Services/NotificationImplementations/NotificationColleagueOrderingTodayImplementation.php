<?php

namespace App\Services\NotificationImplementations;

use App\Models\Batch;
use App\Models\Project;
use App\Models\User;
use App\Notifications\ColleagueOrderingBatchTodayEmail;
use Illuminate\Notifications\Notification;

/**
 * "A colleague is ordering this batch today" - the third of the colleague notifications.
 *
 * Same shape as the quoted and ordered ones, so it inherits all of ColleagueActionImplementation:
 * one per project per batch, addressed to the owner of every project on the batch except the person
 * who is doing the ordering, cleared when the project is archived.
 *
 * What is different is who acted. The other two report a colleague pressing a button. This one
 * reports App\Services\FabricationDeadlineQuoting pressing it for them, because a project on the
 * batch runs out of critical path today - so the "colleague" named is the manager of the project
 * that forced it, who is about to spend everybody else's material money.
 */
class NotificationColleagueOrderingTodayImplementation extends ColleagueActionImplementation
{
    public function getNotificationClass(): string
    {
        return 'ColleagueOrderingBatchTodayEmail';
    }

    protected function notification(Project $project, Batch $batch, User $colleague): Notification
    {
        return new ColleagueOrderingBatchTodayEmail($project, $batch, $colleague);
    }

    public function message(string $string_1, string $string_2): string
    {
        $colleagueName = $string_1;
        $projectName = $string_2;

        return $colleagueName.' will be ordering this batch today, and it includes "'.$projectName
            .'" - its materials were moved into quoting to meet a fabrication start date on the batch.';
    }
}
