<?php

namespace App\Services\NotificationImplementations;

use App\Models\Project;
use App\Notifications\ProjectTentativeDateCheckEmail;
use App\Services\Interfaces\NotificationInterface;
use App\Services\NotificationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Carbon;

class NotificationMaterialsDateImplementation implements NotificationInterface
{
    public string $subInterval;

    public function __construct()
    {
        $testMode = config('env.test_mode');
        $this->subInterval = $testMode ? 'subMinutes' : 'subDays';
    }

    public function hourlyCheck(): void
    {
        /**
         * Is the materials date still correct?
         * 1) Project is active (not done)
         * 2) Project tentative = true
         * 3) At least 2 days since creating the project (so it doesn't immediate send)
         * 4) At least 2 days since last reminder
         */
        $subInterval = $this->subInterval;

        $tentativeProjects = Project::query()
            ->active()                             //1) Project is active (not done)
            ->where('tentative', true)              //2) Project tentative = true
            ->whereBetween('created_at', [Carbon::now()->$subInterval(2), Carbon::now()]) //3)
            ->get();

        foreach ($tentativeProjects as $project) {
            $projectManager = $project->user;

            if (! $this->hasBeenNotified($projectManager, $project->id)) {
                //Mark all previous as read
                $this->markPreviousAsRead($projectManager, $project);

                //Send notification
                $this->sendNotification($projectManager, $project);
            }
        }

        /*
         * Anything still unread about a project that has dropped out of the query above - the date
         * was locked in, the project was marked done - no longer has a question behind it. This ran in
         * an else branch, so it only happened when no project anywhere was tentative; see
         * NotificationService::clearStaleProjectReminders.
         */
        (new NotificationService)->clearStaleProjectReminders(
            $this->getNotificationClass(),
            $tentativeProjects->pluck('id')->all(),
        );
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
            ->whereBetween('created_at', [Carbon::now()->$subInterval(2), Carbon::now()])
            ->exists();
    }

    public function sendNotification(object $recipient, object $otherObject): void
    {
        $project = $otherObject;
        /*
         * The day the steel is wanted however this project records it - the typed-in column, or the
         * fabrication date less a working day (Project::materialsRequiredOn).
         *
         * It was the column alone, and a tentative project created since the upload form stopped
         * asking for one carries null there. message() declared both arguments as plain strings, so
         * that null was a TypeError - thrown inside HourlyNotificationsJob, out of the first
         * implementation on the list, which took the two deadline checks behind it down with it for
         * every business on the platform until the project aged out of the two-day window.
         */
        $message = $this->message(
            $project->materialsRequiredOn()?->format('j M y'),
            $project->name,
        );
        $recipient->notify(new ProjectTentativeDateCheckEmail($project, $recipient, $message));
    }

    public function checkProjectChanges(Project $project): void
    {
        /**
         * Look for any notifications made redundant by project model update, and mark as read
         * 1) Changed to tentative=false
         */

        /*
         * Is the tentative materials date still correct?
         * Condition: tentative=false
         * enum: TENTATIVE_MATERIALS_DATE_CORRECT
         */
        if ($project->tentative === false) {
            $class = $this->getNotificationClass();
            $classWithPath = "App\Notifications\\".$class;
            $recipient = $project->user;

            $recipient->notifications()
                ->where('type', $classWithPath)
                ->where('notifiable_type', "App\Models\User")
                ->where('data->project_id', $project->id)
                ->update(['read_at' => now()]);
        }
    }

    public function getNotificationClass(): string
    {
        return 'ProjectTentativeDateCheckEmail';
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
        /**
         * "Lock it in"
         * tentative=true
         */
        $project = Project::findOrFail($notification->data['project_id']);
        $project->tentative = false;
        $project->save();

        //Mark as read
        $notification->markAsRead();

        return back();
    }

    public function markRed(DatabaseNotification $notification): RedirectResponse
    {
        /**
         * "No"
         * redirect to project edit
         */

        //Mark as read
        $notification->markAsRead();

        //Go to project index
        return redirect()->route('dashboard');
    }

    public function markYellow(DatabaseNotification $notification): RedirectResponse
    {
        //"same"
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
            //Spelled the way the other two reminders spell it, rather than as the raw column value
            $materialsDate = ($notification->data['date_materials_required'] ?? null)
                ? Carbon::parse($notification->data['date_materials_required'])->format('j M y')
                : null;
            $projectName = $notification->data['project_name'] ?? null;

            $message = $this->message($materialsDate, $projectName);

            $notificationData = [
                'id' => $notification->id,
                'message' => $message,
                'timestamp' => $notification->created_at->diffForHumans(),
                'trafficLights' => [
                    'green' => ['Lock it in', '(Update)'],
                    'yellow' => ['Same', '(Ask later)'],
                    'red' => ['No', '(Edit)'],
                ],
            ];
        }

        return $notificationData;
    }

    /**
     * Nullable on both sides - see sendNotification above for what a null used to cost, and
     * NotificationQuotingOrderingDueImplementation::message for why the bell can hand one over.
     *
     * A project with no date at all is asked about differently rather than asked about with a hole in
     * the sentence: "the tentative materials date of  for 'Job'" is a question nobody can answer.
     */
    public function message(?string $string_1, ?string $string_2): string
    {
        $materialsDate = $string_1;
        $projectName = $string_2;

        if ($materialsDate === null) {
            return "Is the date materials are needed for '".$projectName."' still open? Set it on the project.";
        }

        return 'Is the tentative materials date of '.$materialsDate." for '".$projectName."' still correct?";
    }
}
