<?php

namespace App\Services\NotificationImplementations;

use App\Models\Batch;
use App\Models\Project;
use App\Models\User;
use App\Sandbox\Sandbox;
use App\Services\Interfaces\NotificationInterface;
use Illuminate\Http\RedirectResponse;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Notifications\Notification;

/**
 * Shared shape of the two "a colleague did this to your project" notifications.
 *
 * Both are told by the controller that did the thing rather than found by a sweep, both are bell
 * only, both are addressed to the owner of a project somebody else's batch has taken in, and both
 * carry the same three facts: which project, which batch, and who. Everything below is the part that
 * would otherwise be copied twice; the subclass supplies the notification class and the wording.
 *
 * This is the base class the old directory scan was trying to exclude by name. Now that
 * NotificationService::implementations() is an explicit list there is nothing to exclude it from -
 * an abstract class simply never appears on the list.
 */
abstract class ColleagueActionImplementation implements NotificationInterface
{
    /**
     * The notification to send, built for one recipient.
     */
    abstract protected function notification(Project $project, Batch $batch, User $colleague): Notification;

    public function hourlyCheck(): void
    {
        /*
         * Deliberately nothing. The moment being reported is a button press, and the controller
         * reports it then. There is no column that says "this batch swept in a colleague's project
         * and nobody has been told", so an hourly pass would have nothing to look at.
         */
    }

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

    protected function notificationClassWithPath(): string
    {
        return "App\Notifications\\".$this->getNotificationClass();
    }

    public function hasBeenNotified(object $recipient, int $uniqueModelId): bool
    {
        /*
         * The interface's single-id version cannot express "this project on this batch", which is the
         * only question worth asking here - see hasBeenNotifiedAbout(). Answering by project alone is
         * the safe reading of it: it never sends a second time.
         */
        return $recipient->notifications()
            ->where('type', $this->notificationClassWithPath())
            ->where('notifiable_type', "App\Models\User")
            ->where('data->project_id', $uniqueModelId)
            ->exists();
    }

    public function sendNotification(object $recipient, object $otherObject): void
    {
        /*
         * Not used. These need three objects - project, batch and actor - so
         * notifyAffectedProjectManagers() is the way in.
         */
    }

    public function checkProjectChanges(Project $project): void
    {
        /*
         * Nothing an edit can do makes this untrue: it reports something that happened. Archiving the
         * project does clear it, via NotificationService::clearProjectNotifications and the
         * project_id these carry.
         */
    }

    public function markPreviousAsRead(object $recipient, object $otherObject): void
    {
        //One per project per batch, so there is never a previous one to supersede
    }

    public function trafficLight(DatabaseNotification $notification, string $status): ?RedirectResponse
    {
        if (! $this->isCorrectClass($notification)) {
            return null;
        }

        return match ($status) {
            'GREEN' => $this->markGreen($notification),
            'RED' => $this->markRed($notification),
            default => $this->markYellow($notification),
        };
    }

    public function markGreen(DatabaseNotification $notification): RedirectResponse
    {
        //"Show me" - the board is where the batch and its quotes are
        $notification->markAsRead();

        return redirect()->route('projects.index');
    }

    public function markRed(DatabaseNotification $notification): RedirectResponse
    {
        //Nothing to refuse. The action has already happened; this only reports it
        return $this->markYellow($notification);
    }

    public function markYellow(DatabaseNotification $notification): RedirectResponse
    {
        $notification->markAsRead();

        return back();
    }

    public function isCorrectClass(DatabaseNotification $notification): bool
    {
        return $notification->type === $this->notificationClassWithPath();
    }

    public function notificationData(DatabaseNotification $notification): ?array
    {
        if (! $this->isCorrectClass($notification)) {
            return null;
        }

        $projectName = $notification->data['project_name'] ?? null;
        $colleagueName = $notification->data['colleague_name'] ?? null;

        if (! $projectName || ! $colleagueName) {
            return null;
        }

        return [
            'id' => $notification->id,
            'message' => $this->message($colleagueName, $projectName),
            'timestamp' => $notification->created_at->diffForHumans(),
            'trafficLights' => [
                'green' => ['Show me', '(Go to board)'],
                'yellow' => ['Ok', '(Dismiss)'],
                'red' => null,
            ],
        ];
    }
}
