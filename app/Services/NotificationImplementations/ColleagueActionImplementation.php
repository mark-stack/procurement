<?php

namespace App\Services\NotificationImplementations;

use App\Models\Batch;
use App\Models\Project;
use App\Models\User;
use App\Sandbox\Sandbox;
use Illuminate\Notifications\Notification;

/**
 * Shared shape of the two "a colleague did this to your batch" notifications.
 *
 * Both are told by the controller that did the thing, both are bell only, both are addressed to the
 * owner of a project somebody else's batch has taken in, and both carry the same three facts: which
 * project, which batch, and who. ColleagueNotificationImplementation above holds everything that is
 * not about the batch; this is the batch part - who to tell, and once per project per batch.
 */
abstract class ColleagueActionImplementation extends ColleagueNotificationImplementation
{
    /**
     * The notification to send, built for one recipient.
     */
    abstract protected function notification(Project $project, Batch $batch, User $colleague): Notification;

    /**
     * Tell the owner of every project on this batch that is not the person who acted.
     */
    public function notifyAffectedProjectManagers(Batch $batch, User $actor): void
    {
        /*
         * Test mode, not the real board. Sandbox rows are scoped to one user and invisible to
         * everybody else, so notifying a colleague about one would put a project in their bell that
         * they cannot open, cannot act on and did not know existed. The hourly checks avoid this by
         * running with no authenticated user; these run inside a request, where the actor may well be
         * in their own sandbox, so the guard has to be explicit.
         */
        if (Sandbox::isActive()) {
            return;
        }

        foreach ($batch->projects() as $project) {
            $owner = $project->user;

            //Not the person who pressed the button, and not a duplicate
            if (! $owner || $owner->id === $actor->id) {
                continue;
            }

            if ($this->hasBeenNotifiedAbout($owner, $project->id, $batch->id)) {
                continue;
            }

            $owner->notify($this->notification($project, $batch, $actor));
        }
    }

    /**
     * Once per project per batch. The same batch can be ordered, un-ordered and ordered again.
     */
    protected function hasBeenNotifiedAbout(User $recipient, int $projectId, int $batchId): bool
    {
        return $recipient->notifications()
            ->where('type', $this->notificationClassWithPath())
            ->where('notifiable_type', "App\Models\User")
            ->where('data->project_id', $projectId)
            ->where('data->batch_id', $batchId)
            ->exists();
    }
}
