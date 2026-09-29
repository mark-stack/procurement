<?php

namespace App\Notifications;

use App\Models\Batch;
use App\Models\Project;
use App\Models\User;
use Illuminate\Notifications\Notification;

/**
 * A colleague pressed "Start quoting" and your project went into their batch.
 *
 * This is the notification the multi-staff board most needs, because "Start quoting" is the action
 * that most needs telling. It nests *every* project sitting in the Nesting column into one batch -
 * colleagues' projects included - owned by whoever pressed it, and from that moment the suppliers
 * quoted, the delivery dates asked for and the nesting your pieces were cut into are theirs, not
 * yours. You also lose Edit, Archive and BOM upload on your own project the instant its pieces have
 * a batch (PrerequisiteConditions::editProject and friends).
 *
 * All of that is intended - it is how batching saves money - and the board now confirms it and names
 * whose work is being taken. But the confirmation is shown to the person pressing the button. The
 * owner of the project being swept in was told nothing at all, and would find out by noticing their
 * project had moved column.
 *
 * Bell only. It is worth knowing the next time you look at the board; it is not worth an email.
 */
class ColleagueQuotedYourMaterials extends Notification
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
            /*
             * project_id is not decoration: NotificationService::clearProjectNotifications and the
             * project observer both key off it, so archiving the project takes this out of the bell
             * with everything else about it.
             */
            'project_id' => $this->project->id,
            'project_name' => $this->project->name,
            'batch_id' => $this->batch->id,
            'colleague_name' => $this->colleague->name,
        ];
    }
}
