<?php

namespace App\Services\Interfaces;

use App\Models\Project;
use App\Models\User;
use App\Notifications\NewUserEmail;
use App\Notifications\ProjectAwardedCheckEmail;
use App\Notifications\ProjectTentativeDateCheckEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

class NotificationMaterialsDateImplementation implements NotificationInterface
{
    public string $interval;
    public function __construct()
    {
        $testMode = env("TEST_MODE");
        $this->interval = $testMode ? 'subMinutes' : 'subDays';
    }

    public function hourlyCheck(): void
    {
        /**
         * Is the materials date still correct?
         * 1) Project is active (not archived)
         * 2) Project tentative = true
         * 3) At least 2 days since creating the project (so it doesn't immediate send)
         * 4) At least 2 days since last message
         */
        $interval = $this->interval;

        $tentativeProjects = Project::query()
            ->active()                                                  //1) Project is active (not archived)
            ->where("tentative",true)                                   //2) Project tentative = true
            ->where('created_at', '<=', Carbon::now()->$interval(2))    //3) At least 2 days since creating the project (so it doesn't immediate send)
            ->get();

        foreach($tentativeProjects as $project) {
            $projectManager = $project->user;

            if (!$this->notifiedAlready($projectManager)) {
                //Mark all previous as read
                $this->markPreviousAsRead($projectManager);

                //Send notification
                $this->sendNotification($projectManager,$project);
            }
        }
    }

    public function notifiedAlready(object $recipient): bool
    {
        $class = $this->getNotificationClass();

        $interval = $this->interval;

        return $recipient->notifications()
            ->where("type","App\Notifications\{$class}")
            ->where("notifiable_type","App\Models\User")
            ->where('created_at', '<=', Carbon::now()->$interval(2))  //4) At least 2 days since last message
            ->exists();
    }

    public function sendNotification(object $recipient, object $otherObject): void
    {
        $project = $otherObject;
        $recipient->notify(new ProjectTentativeDateCheckEmail($project, $recipient));
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
        if($project->tentative === false){
            $class = $this->getNotificationClass();
            $classWithPath = "App\Notifications\\".$class;
            $recipient = $project->user;

            $recipient->notifications()
                ->where("type",$classWithPath)
                ->where("notifiable_type","App\Models\User")
                ->update(['read_at' => now()]);
        }
    }

    public function getNotificationClass(): string
    {
        return "ProjectTentativeDateCheckEmail";
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
        //"Lock it in"
        //todo: change tentative=true
        return back();
    }

    public function markRed(DatabaseNotification $notification): RedirectResponse
    {
        //"No"
        //todo: redirect to project edit
        return back();
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

    public function notificationData(DatabaseNotification $notification): null|array
    {
        $notificationData = null;

        if($notification->type === "App\Notifications\ProjectTentativeDateCheckEmail"){
            $project_name = $notification->data["project_name"] ?? null;
            $tentative_date = $notification->data["date_materials_required"] ?? null;

            $notificationData = [
                "id" => $notification->id,
                "message" => "Is the tentative materials date of ".$tentative_date." for '".$project_name."' still correct?",
                "timestamp" => $notification->created_at->diffForHumans(),
                "trafficLights" => [
                    "green" => ["Lock it in","(Update)"],
                    "yellow" => ["Same","(Ask later)"],
                    "red" => ["No","(Edit)"],
                ],
            ];
        }

        return $notificationData;
    }
}
