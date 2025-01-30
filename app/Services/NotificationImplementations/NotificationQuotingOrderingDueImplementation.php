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
         * 2) Project is awarded
         * 3) Between [critical path + 1 day] and [critical path] days before planned project material received date
         * 4) At least 1 day since last reminder
         * 5) Order coverage < 100%
         * 6) Not notified already
         */
        $quoteDueProjects = Project::query()
            ->active()                       //1) Project is active (not archived)
            ->awarded()                      //2) Project "awarded" = true
            ->dueForQuotingAndOrdering()     //3) Between [critical path + 1 day] and [critical path] days before planned project material received date
            ->get();

        //Has notifications
        if ($quoteDueProjects->count() > 0) {
            foreach ($quoteDueProjects as $project) {
                //Prerequisite variables
                $projectManager = $project->user;

                //5) Order coverage < 100%
                if ($project->percentageOfMaterialsOrdered() === 100) {
                    break;
                }

                //6) Not notified already
                if ($this->hasBeenNotified($projectManager, $project->id)) {
                    break;
                }

                //Mark all previous as read
                $this->markPreviousAsRead($projectManager, $project);

                //Send notification
                $this->sendNotification($projectManager, $project);
            }
        }
        //NO notifications
        else {
            //Clear old notifications
            $class = $this->getNotificationClass();
            (new NotificationService)->clearPreviousNotifications($class);
        }
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
        $message = $this->message($project->date_materials_required, $project->name);
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

    public function message(string $string_1, string $string_2): string
    {
        $materialsDate = $string_1;
        $projectName = $string_2;

        return 'The materials for "'.$projectName.'" are due to be quoted so they can be received before '.$materialsDate;
    }
}
