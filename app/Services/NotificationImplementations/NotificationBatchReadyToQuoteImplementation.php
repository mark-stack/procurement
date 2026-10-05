<?php

namespace App\Services\NotificationImplementations;

use App\Actions\Batch\PressStartQuoting;
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
            $this->message($this->materialsRequiredDate($project), $project->name),
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

    /**
     * Press the button this notification exists to ask for, and open what it produced.
     *
     * It used to send the reader to the page the button is on and leave them to find it. That is the
     * one screen in the application they were already being told about, and the press is the whole
     * point of the warning - so the green action is now the press itself, run through the same
     * PressStartQuoting the card's own "Lock batch for quoting" runs: the same gate, the same nesting,
     * the same notifications to the colleagues whose work goes into the batch.
     *
     * And then the order list of the batch it just made, which is what somebody warned that their
     * steel has to go out today actually needs - the materials to put in front of a merchant. They
     * land on the Nesting page with that modal already open and can read or copy the lists from
     * there; closing it leaves them on the page with the new batch on it.
     *
     * Read before the press, because the press is what makes it untrue: the moment the column is
     * nested, FabricationDeadlineQuoting stops naming these projects and would mark this row read on
     * its next sweep anyway. Doing it here means the bell is right immediately rather than within
     * the hour.
     *
     * A refusal leaves the notification read all the same: a warning that cannot be cleared is one
     * people learn to ignore, and every reason the press can refuse is answered by reading the page
     * rather than by pressing this again.
     *
     * Those reasons all held when the row was sent - projectsToWarn asks the same gate, as the same
     * user, and will not chase a read-only business at all - and a row can sit unread in a bell for
     * as long as somebody leaves it there. A colleague quoting the column, or a trial lapsing, is
     * what happens in between.
     */
    public function markGreen(DatabaseNotification $notification): RedirectResponse
    {
        $notification->markAsRead();

        $press = PressStartQuoting::run(auth()->user());

        /*
         * Every way this can fail reads the same way from here: a sentence on the page they land on.
         * The press itself is what tells them apart - a lapsed account, a colleague who got there
         * first, a nesting that threw - and all four are answered by reading that page rather than by
         * pressing this again.
         */
        if ($press['refusal'] !== null) {
            return redirect()->route('dashboard')->with('warning', $press['refusal']);
        }

        /*
         * Which modal to open, rather than a query string on the url. It is a one-off instruction
         * about this arrival - flash is gone by the next request - where ?orderList=12 would sit in
         * the address bar reopening the modal on every refresh and every back button. See
         * NestingIndex.vue.
         */
        return redirect()->route('dashboard')->with('openOrderList', $press['batch']->id);
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

        /*
         * Worked back off the fabrication date the row has carried since this notification existed,
         * rather than stored beside it. Every row already in a bell holds that date and none of them
         * hold the required-by one, so deriving it is what keeps an old row readable - a new field
         * would have left yesterday's warnings printing "received by soon".
         */
        $materialsRequiredDate = Project::materialsRequiredDate(
            $notification->data['date_fabrication_begins'] ?? null
        );

        return [
            'id' => $notification->id,
            'message' => $this->message(
                $materialsRequiredDate ? $materialsRequiredDate->format('j M') : 'soon',
                $projectName,
            ),
            'timestamp' => $notification->created_at->diffForHumans(),
            /*
             * One button, and all it does is take the row out of the bell.
             *
             * This used to offer the press itself - "Start quoting", green, with "Ok" beside it -
             * and nesting a column is a purchase. A bell is read in a hurry, often on a phone, and
             * a pair of boxes where the big friendly one buys steel is the wrong place to put that
             * decision. The warning says what the deadline is; the press lives on the card that
             * names the materials, which is where somebody can see what they are committing to.
             */
            'trafficLights' => [
                'read' => ['Read', null],
            ],
        ];
    }

    /**
     * What the bell and the email both say, in one place.
     *
     * The day named is when the steel has to be at the workshop - one working day before the first
     * cut, Project::materialsRequiredDate - and not the day fabrication begins, which is what this
     * used to print. They are a day apart, and the required-by day is the one the Nesting page's
     * cards carry, so naming the other one had the warning and the card the reader goes to answer it
     * talking about different dates.
     *
     * $string_2 is the project name, and the sentence no longer uses it. The parameter stays because
     * NotificationInterface fixes the arity for every implementation, and both callers go on passing
     * it rather than a placeholder - the name is still on the row (toArray) and still what the
     * clearing works off, so the day this wants it back there is nothing to put back.
     */
    public function message(string $string_1, string $string_2): string
    {
        $materialsRequiredDate = $string_1;

        return 'Your batch materials need to be received by '.$materialsRequiredDate
            .', so the critical path requires the materials to be quoted today';
    }

    protected function notificationClassWithPath(): string
    {
        return "App\Notifications\\".$this->getNotificationClass();
    }

    /**
     * The day this job's steel has to be at the workshop, as the sentence prints it.
     *
     * "soon" where the project names no fabrication date to work it back off. That cannot happen on
     * the way in - FabricationDeadlineQuoting::triggerProject picks the trigger by that very date -
     * but the wording has to hold for the bell, which re-renders rows written long ago.
     */
    private function materialsRequiredDate(Project $project): string
    {
        return Project::materialsRequiredDate($project->date_fabrication_begins)
            ?->format('j M')
            ?? 'soon';
    }
}
