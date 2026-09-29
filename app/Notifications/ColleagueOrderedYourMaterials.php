<?php

namespace App\Notifications;

use App\Models\Batch;
use App\Models\Project;
use App\Models\User;
use Illuminate\Notifications\Notification;

/**
 * A colleague pressed "Sent order" on a batch carrying your project, which settled your approval.
 *
 * The second action that deliberately crosses the ownership line. UpdateOrderApprovalStatus flips
 * project_manager_approved to true for every project on the batch on one person's press, and records
 * approved_by_user_id so the row no longer claims an approval nobody gave. That record exists so the
 * truth is auditable after the fact - but nothing told the project manager whose approval had just
 * been given for them.
 *
 * This is the closest thing to the ORDER_APPROVAL_REQUIRED case in NotificationEnums that is honest
 * to build. Asking for approval implies a workflow where the order waits for each manager's answer,
 * and per-manager approval was explicitly not built - it is a workflow, not a column. So this tells
 * you after the fact, which is what actually happened, rather than asking for something the app will
 * not then wait for.
 *
 * Bell only.
 */
class ColleagueOrderedYourMaterials extends Notification
{
    public function __construct(
        public Project $project,
        public Batch $batch,
        public User $colleague,
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
            //See ColleagueQuotedYourMaterials on why project_id has to be here
            'project_id' => $this->project->id,
            'project_name' => $this->project->name,
            'batch_id' => $this->batch->id,
            'colleague_name' => $this->colleague->name,
        ];
    }
}
