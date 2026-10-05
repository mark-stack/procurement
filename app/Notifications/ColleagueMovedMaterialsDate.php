<?php

namespace App\Notifications;

use App\Models\Batch;
use App\Models\Project;
use App\Models\User;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;

/**
 * A colleague moved the fabrication date on another job, and your batch is now wanted on a new day.
 *
 * A batch is quoted, bought and delivered as one, so the day its steel has to be at the workshop is
 * the earliest fabrication start among the jobs on it, less a working day. That makes the date a
 * shared fact with a single owner: whoever manages the job that happens to be earliest sets the
 * deadline the whole batch is held to, and PrerequisiteConditions::editProject quite rightly lets
 * them move it without asking anybody.
 *
 * What was missing is the other half. Their edit re-dates the card for every colleague on that batch
 * - its required-by pill, the critical path it is measured against, whether it draws the red "Action
 * required" footer - and nobody else was told anything at all. A project manager would find out by
 * noticing their batch had gone red, with no way to see why, when, or who. The two batch actions a
 * colleague can take are already reported (ColleagueQuotedYourMaterials and the ordering one); this
 * is the third, and it is the one that can move a deadline without anything being pressed.
 *
 * Bell only, like the other two: it is worth knowing the next time you look at the board, and it is
 * not worth an email.
 */
class ColleagueMovedMaterialsDate extends Notification
{
    public function __construct(
        public Project $project,
        public Batch $batch,
        public User $colleague,
        /**
         * The batch's new required-by day, which is the news - not the date the colleague typed.
         *
         * Theirs is a fact about their job; this is what it costs yours. Null where the batch is left
         * with no dated job on it at all, which the bell words as the deadline having gone away.
         */
        public ?Carbon $requiredBy,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            /*
             * project_id is not decoration: NotificationService::clearProjectNotifications and the
             * project observer both key off it, so marking the project done takes this out of the
             * bell with everything else about it.
             */
            'project_id' => $this->project->id,
            'project_name' => $this->project->name,
            'batch_id' => $this->batch->id,
            'colleague_name' => $this->colleague->name,
            //What the bell prints, and what stops the same move being reported twice
            'required_by' => $this->requiredBy?->toDateString(),
        ];
    }
}
