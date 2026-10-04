<?php

namespace App\Services\NotificationImplementations;

use App\Models\Project;
use App\Models\User;
use App\Notifications\BatchReadyToQuoteEmail;
use App\Services\Interfaces\NotificationInterface;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Notifications\DatabaseNotification;

/**
 * "Your fabrication date is close, so this column has to be quoted today."
 *
 * Addressed to the manager of the project whose fabrication date ran out of room, and only to them -
 * NotificationColleagueOrderingTodayImplementation is what the other project managers in the column
 * get. See App\Services\FabricationDeadlineQuoting for which project triggers it and why.
 *
 * It used to report a batch this application had already created on the recipient's behalf, and it
 * carried that batch's id. It does not any more: nothing is quoted until somebody presses "Start
 * quoting", so this asks rather than announces. The notification class name is unchanged on purpose -
 * it is the `type` column of every row already sitting in somebody's bell, and renaming it would
 * leave those rendered by nobody (see NotificationService::implementations).
 *
 * hourlyCheck() is empty on purpose. Working out whether a business even has a Nesting column means
 * reading its unbatched pieces through the price book, which is not what the hourly notifications job
 * does, so this one is driven by the quoting:fabrication-deadline command instead.
 */
class NotificationBatchReadyToQuoteImplementation implements NotificationInterface
{
    /**
     * How far back hasBeenNotified() looks. Minutes in test mode, as the hourly reminders do, so a
     * window measured in days can be walked through in a test without travelling a day.
     */
    public string $subInterval;

    public function __construct()
    {
        $this->subInterval = config('env.test_mode') ? 'subMinutes' : 'subDays';
    }

    public function hourlyCheck(): void
    {
        //See the class docblock - the sweep is App\Services\FabricationDeadlineQuoting
    }

    /**
     * Ask the project manager whose fabrication date has run out of room to start quoting.
     */
    public function notifyTrigger(Project $project, User $recipient): void
    {
        if ($this->hasBeenNotified($recipient, $project->id)) {
            return;
        }

        /*
         * Yesterday's copy, superseded. Without this the bell would hold one unread row per day of
         * the window, all saying the same thing about the same project with a different date on it.
         */
        $this->markPreviousAsRead($recipient, $project);

        $recipient->notify(new BatchReadyToQuoteEmail(
            $project,
            $recipient,
            $this->message($this->fabricationDate($project), $project->name),
        ));
    }

    /**
     * Once a day per project, not once ever.
     *
     * The old answer was "once per project per batch", which worked because the sweep emptied the
     * column: the thing being reported had happened, and could not happen again. Nothing happens now
     * until somebody acts, so the condition is still true an hour later and still true tomorrow -
     * this is what makes an hourly command send once a day, and what keeps asking while the column
     * sits there. The same window the hourly quoting reminders use.
     */
    public function hasBeenNotified(object $recipient, int $uniqueModelId): bool
    {
        $subInterval = $this->subInterval;

        return $recipient->notifications()
            ->where('type', $this->notificationClassWithPath())
            ->where('notifiable_type', "App\Models\User")
            ->where('data->project_id', $uniqueModelId)
            ->whereBetween('created_at', [Carbon::now()->$subInterval(1), Carbon::now()])
            ->exists();
    }

    public function sendNotification(object $recipient, object $otherObject): void
    {
        /*
         * Not used. This needs the wording built from the project as well as the recipient, so
         * notifyTrigger() is the way in.
         */
    }

    public function checkProjectChanges(Project $project): void
    {
        /*
         * Nothing to do here. Pushing the fabrication date out does make this untrue - which is
         * exactly what FabricationDeadlineQuoting::clearWarningsNoLongerDue clears on the next run,
         * off the one list that knows which columns are still inside the window. Marking done the
         * project clears it too, via NotificationService::clearProjectNotifications and the
         * project_id this carries.
         */
    }

    public function getNotificationClass(): string
    {
        return 'BatchReadyToQuoteEmail';
    }

    public function markPreviousAsRead(object $recipient, object $otherObject): void
    {
        $recipient->notifications()
            ->where('type', $this->notificationClassWithPath())
            ->where('notifiable_type', "App\Models\User")
            ->where('data->project_id', $otherObject->id)
            ->update(['read_at' => now()]);
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
        //"Start quoting" - the board is where that button is
        $notification->markAsRead();

        return redirect()->route('dashboard');
    }

    public function markRed(DatabaseNotification $notification): RedirectResponse
    {
        //Nothing to refuse. Waiting is still an option, and the board goes on saying so
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

        if (! $projectName) {
            return null;
        }

        $fabricationDate = $notification->data['date_fabrication_begins'] ?? null;

        return [
            'id' => $notification->id,
            'message' => $this->message(
                $fabricationDate ? Carbon::parse($fabricationDate)->format('j M y') : 'soon',
                $projectName,
            ),
            'timestamp' => $notification->created_at->diffForHumans(),
            'trafficLights' => [
                'green' => ['Start quoting', '(Go to board)'],
                'yellow' => ['Ok', '(Dismiss)'],
                'red' => null,
            ],
        ];
    }

    public function message(string $string_1, string $string_2): string
    {
        $fabricationDate = $string_1;
        $projectName = $string_2;

        return 'Fabrication on "'.$projectName.'" begins '.$fabricationDate
            .', and its materials are still waiting to be nested. Start quoting to take them into a'
            .' batch with everything else in the column - this one needs to go out to suppliers today.';
    }

    protected function notificationClassWithPath(): string
    {
        return "App\Notifications\\".$this->getNotificationClass();
    }

    private function fabricationDate(Project $project): string
    {
        return $project->date_fabrication_begins
            ? Carbon::parse($project->date_fabrication_begins)->format('j M y')
            : 'soon';
    }
}
