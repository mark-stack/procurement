<?php

namespace Templates;

use App\Models\Project;
use App\Notifications\QuoteDueEmail;
use App\Services\Interfaces\NotificationInterface;
use Illuminate\Http\RedirectResponse;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Carbon;

class TemplateImplementation implements NotificationInterface
{
    public string $subInterval;

    public function __construct()
    {
        $testMode = env("TEST_MODE");
        $this->subInterval = $testMode ? 'subMinutes' : 'subDays';
    }

    public function hourlyCheck(): void
    {
        /**
         * xxx
         * 1) xxx
         * 2) xxx
         */

        $things = []; //todo
        foreach($things as $thing) {
            $recipient = 999; //todo

            if (!$this->notifiedAlready($recipient)) {
                //Mark all previous as read
                $this->markPreviousAsRead($recipient);

                //Send notification
                $otherObject = 999; //todo
                $this->sendNotification($recipient,$otherObject);
            }
        }
    }

    public function notifiedAlready(object $recipient): bool
    {
        $class = $this->getNotificationClass();

        $subInterval = $this->subInterval;
        return $recipient->notifications()
            ->where("type","App\Notifications\{$class}")
            ->where("notifiable_type","App\Models\User")
            ->whereBetween('created_at', [Carbon::now()->$subInterval(1), Carbon::now()]) //todo At least 1 day since last reminder
            ->exists();
    }

    public function sendNotification(object $recipient, object $otherObject): void
    {
        $project = $otherObject; //todo
        $message = $this->message($project->date_materials_required, $project->name); //todo

        //todo change "QuoteDueEmail"
        $recipient->notify(new QuoteDueEmail($project, $recipient, $message));
    }

    public function checkProjectChanges(Project $project): void
    {
        /**
         * Look for any notifications made redundant by project model update, and mark as read
         */
        //todo if affected by any project model changes
    }

    public function getNotificationClass(): string
    {
        //todo change "QuoteDueEmail"
        return "QuoteDueEmail";
    }

    public function markPreviousAsRead(object $recipient): void
    {
        $class = $this->getNotificationClass();
        $classWithPath = "App\Notifications\\".$class;

        $recipient->notifications()
            ->where("type",$classWithPath)
            ->where("notifiable_type","App\Models\User")
            ->update(['read_at' => now()]);
    }

    public function trafficLight(DatabaseNotification $notification, string $status): null|RedirectResponse
    {
        $return = null;
        if($this->isCorrectClass($notification)){
            $return = match ($status) {
                "GREEN" => $this->markGreen($notification),
                "YELLOW" => $this->markYellow($notification),
                "RED" => $this->markRed($notification),
            };
        }

        return $return;
    }

    public function markGreen(DatabaseNotification $notification): RedirectResponse
    {
        //Mark as read
        $notification->markAsRead();

        //todo actions here

        //todo return response
    }

    public function markRed(DatabaseNotification $notification): RedirectResponse
    {
        //todo actions here

        //todo return response
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

    public function notificationData(DatabaseNotification $notification): null|array
    {
        $notificationData = null;

        if($this->isCorrectClass($notification)){
            $materialsDate = $notification->data["date_materials_required"] ?? null; //todo
            $projectName = $notification->data["project_name"] ?? null; //todo

            $message = $this->message($materialsDate,$projectName);

            $notificationData = [
                "id" => $notification->id,
                "message" => $message,
                "timestamp" => $notification->created_at->diffForHumans(),
                "trafficLights" => [
                    "green" => ["Ok","(Go to)"], //todo
                    "yellow" => ["Wait","(Ask later)"], //todo
                    "red" => null,
                ],
            ];
        }

        return $notificationData;
    }

    public function message(string $string_1, string $string_2): string
    {
        $materialsDate = $string_1; //todo
        $projectName = $string_2; //todo

        //todo change this
        return 'The materials for "'.$projectName.'" are due to be quoted so they can be received before '.$materialsDate;
    }
}
