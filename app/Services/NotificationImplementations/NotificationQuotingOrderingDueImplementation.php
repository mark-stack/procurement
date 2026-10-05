<?php

namespace App\Services\NotificationImplementations;

use App\Models\Project;
use App\Notifications\QuoteDueEmail;
use App\Services\Interfaces\NotificationInterface;
use App\Services\NotificationService;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Notifications\DatabaseNotification;

class NotificationQuotingOrderingDueImplementation implements NotificationInterface
{
    public string $subInterval;

    public string $addInterval;

    public function __construct()
    {
        $testMode = config('env.test_mode');
        $this->subInterval = $testMode ? 'subMinutes' : 'subDays';
        $this->addInterval = $testMode ? 'addMinutes' : 'addDays';
    }

    public function hourlyCheck(): void
    {
        /**
         * Quoting/Ordering "Due"
         *
         * The critical path = quote time + delivery time.
         * "Due" is when there's [critical path + 1 day] until planned project material received date
         *
         * 1) Project is active
         * 2)
         * 3) Between [critical path + 1 day] and [critical path] days before planned project material received date
         * 4) At least 1 day since last reminder
         * 5) Order coverage < 100%
         * 6) Not notified already
         *
         * (3) used to be a query scope, and it asked the database for a window counted on the
         * platform's default lead times rather than on each business's own - and only ever against
         * date_materials_required, a column no project created since October carries, so this
         * reminder had quietly stopped selecting anybody at all. It is a question about a project and
         * its business now; see Project::isDueForQuotingAndOrdering.
         */
        $quoteDueProjects = Project::query()
            ->active()                       //1) Project is active (not done)
            ->datedAndChaseable()            //Cheap half of (3) - a project with no date is never due
            ->get()
            ->filter(fn (Project $project) => $project->isDueForQuotingAndOrdering()); //3)

        //Projects that still deserve a reminder, whether or not one is sent this run
        $stillDue = [];

        foreach ($quoteDueProjects as $project) {
            //Prerequisite variables
            $projectManager = $project->user;

            /*
             * continue, not break. Both of these are per-project questions, and breaking on
             * them abandoned the whole run: the first project that happened to be fully
             * ordered - or that had already been reminded about today - silently cancelled
             * this reminder for every other project manager behind it in the list. It only
             * shows up once a business has more than one live project, which is to say
             * exactly when the reminders start mattering.
             */

            //5) Order coverage < 100% - see Project::everyOrderableRowOrdered for why it is not the
            //percentage, which a single unmatched BOM row held below 100 forever
            if ($project->everyOrderableRowOrdered()) {
                continue;
            }

            $stillDue[] = $project->id;

            //6) Not notified already
            if ($this->hasBeenNotified($projectManager, $project->id)) {
                continue;
            }

            //Mark all previous as read
            $this->markPreviousAsRead($projectManager, $project);

            //Send notification
            $this->sendNotification($projectManager, $project);
        }

        /*
         * A project that has since been fully ordered, or whose materials date moved out of the
         * window, keeps no unread reminder. See NotificationService::clearStaleProjectReminders for
         * why this is not the else branch it used to be.
         */
        (new NotificationService)->clearStaleProjectReminders($this->getNotificationClass(), $stillDue);
    }

    public function hasBeenNotified(object $recipient, int $uniqueModelId): bool
    {
        $class = $this->getNotificationClass();

        $subInterval = $this->subInterval;
        $classWithPath = "App\Notifications\\".$class;

        return $recipient->notifications()
            ->where('type', $classWithPath)
            ->where('notifiable_type', "App\Models\User")
            ->where('data->project_id', $uniqueModelId)
            ->whereBetween('created_at', [Carbon::now()->$subInterval(1), Carbon::now()]) //At least 1 day since last reminder
            ->exists();
    }

    public function sendNotification(object $recipient, object $otherObject): void
    {
        $project = $otherObject;
        //The day the steel is wanted however this project records it, spelled the way the bell
        //spells it - see Project::materialsRequiredOn and notificationData() below
        $message = $this->message(
            $project->materialsRequiredOn()?->format('j M y'),
            $project->name,
        );
        $recipient->notify(new QuoteDueEmail($project, $recipient, $message));
    }

    public function checkProjectChanges(Project $project): void
    {
        /**
         * Look for any notifications made redundant by project model update, and mark as read
         */
    }

    public function getNotificationClass(): string
    {
        return 'QuoteDueEmail';
    }

    public function markPreviousAsRead(object $recipient, object $otherObject): void
    {
        $class = $this->getNotificationClass();
        $classWithPath = "App\Notifications\\".$class;

        $recipient->notifications()
            ->where('type', $classWithPath)
            ->where('notifiable_type', "App\Models\User")
            ->where('data->project_id', $otherObject->id)
            ->update(['read_at' => now()]);
    }

    public function trafficLight(DatabaseNotification $notification, string $status): ?RedirectResponse
    {
        $return = null;
        if ($this->isCorrectClass($notification)) {
            $return = match ($status) {
                'GREEN' => $this->markGreen($notification),
                'YELLOW' => $this->markYellow($notification),
                'RED' => $this->markRed($notification),
                default => back(),
            };
        }

        return $return;
    }

    public function markGreen(DatabaseNotification $notification): RedirectResponse
    {
        //Mark as read
        $notification->markAsRead();

        return back();
    }

    public function markRed(DatabaseNotification $notification): RedirectResponse
    {
        //Not used
        return back();
    }

    public function markYellow(DatabaseNotification $notification): RedirectResponse
    {
        $notification->markAsRead();

        return back();
    }

    public function isCorrectClass(DatabaseNotification $notification): bool
    {
        $class = $this->getNotificationClass();
        $classWithPath = "App\Notifications\\".$class;

        return $notification->type === $classWithPath;
    }

    public function notificationData(DatabaseNotification $notification): ?array
    {
        $notificationData = null;

        if ($this->isCorrectClass($notification)) {
            $materialsDate = $notification->data['date_materials_required']
                ? Carbon::parse($notification->data['date_materials_required'])->format('j M y')
                : null;

            $projectName = $notification->data['project_name'] ?? null;

            $message = $this->message($materialsDate, $projectName);

            $notificationData = [
                'id' => $notification->id,
                'message' => $message,
                'timestamp' => $notification->created_at->diffForHumans(),
                'trafficLights' => [
                    'green' => ['Ok', '(Go to)'],
                    'yellow' => ['Wait', '(Ask later)'],
                    'red' => null,
                ],
            ];
        }

        return $notificationData;
    }

    /**
     * Nullable, because a notification row can outlive the date it was sent about.
     *
     * The bell re-renders every message from the row's own data each time it is drawn, and a row
     * written before the stored date was resolved - or about a project whose date has since been
     * cleared - hands this a null. Declared as a plain string, that is a TypeError thrown while
     * rendering somebody's bell, which takes the whole page with it rather than one line of it.
     */
    public function message(?string $string_1, ?string $string_2): string
    {
        $materialsDate = $string_1;
        $projectName = $string_2;

        return 'The materials for "'.$projectName.'" are due to be quoted so they can be received before '
            .($materialsDate ?? 'the date this job needs them');
    }
}
