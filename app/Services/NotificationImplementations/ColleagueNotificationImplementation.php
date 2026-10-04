<?php

namespace App\Services\NotificationImplementations;

use App\Models\Project;
use App\Services\Interfaces\NotificationInterface;
use Illuminate\Http\RedirectResponse;
use Illuminate\Notifications\DatabaseNotification;

/**
 * Shared shape of the "this is about a colleague and one of your projects" notifications.
 *
 * All of them are told at the moment something happens rather than found by the hourly sweep, all are
 * addressed to the owner of a project somebody else's work has taken in, and all carry the same two
 * facts the bell renders from: which project, and who. Everything here is the part that would
 * otherwise be copied once per notification; the subclass supplies the class name, the wording and
 * how it is sent.
 *
 * Split out from ColleagueActionImplementation, which is now the half of this that is specifically
 * about a batch - a colleague quoting or ordering one. NotificationColleagueOrderingTodayImplementation
 * no longer has a batch to name: since the fabrication deadline stopped quoting for people, what it
 * reports is a column that is about to be quoted by somebody who has not pressed the button yet.
 *
 * Abstract, so it never appears on NotificationService::implementations() - that list is explicit,
 * which is what lets a base class exist here at all.
 */
abstract class ColleagueNotificationImplementation implements NotificationInterface
{
    public function hourlyCheck(): void
    {
        /*
         * Deliberately nothing. The moment being reported is a button press or a scheduled warning,
         * and whatever did it says so at the time. There is no column that reads "a colleague's batch
         * swept this project in and nobody has been told", so an hourly pass would have nothing to
         * look at.
         */
    }

    protected function notificationClassWithPath(): string
    {
        return "App\Notifications\\".$this->getNotificationClass();
    }

    public function hasBeenNotified(object $recipient, int $uniqueModelId): bool
    {
        /*
         * Once per project, ever. The interface's single-id version cannot express the narrower
         * question a subclass may really be asking - "this project on this batch", "this project
         * today" - so this is the safe reading of it: it never sends a second time.
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
         * Not used. These need three things - project, colleague and whatever the colleague did - so
         * each subclass has its own way in.
         */
    }

    public function checkProjectChanges(Project $project): void
    {
        /*
         * Nothing an edit can do makes one of these untrue on its own. Marking the project done does
         * clear it, via NotificationService::clearProjectNotifications and the project_id these
         * carry.
         */
    }

    public function markPreviousAsRead(object $recipient, object $otherObject): void
    {
        //One per project per event, so there is normally never a previous one to supersede
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

        return redirect()->route('dashboard');
    }

    public function markRed(DatabaseNotification $notification): RedirectResponse
    {
        //Nothing to refuse. This reports somebody else's work, not a decision of the reader's
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
