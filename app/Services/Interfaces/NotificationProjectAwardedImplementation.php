<?php

namespace App\Services\Interfaces;

use App\Models\Project;
use App\Models\User;
use App\Notifications\NewUserEmail;
use App\Notifications\ProjectAwardedCheckEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

class NotificationProjectAwardedImplementation implements NotificationInterface
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
         * Has the project been awarded to you?
         * 1) Project is active (not archived)
         * 2) Project "awarded" = false
         * 3) At least 2 days since creating the project (so it doesn't immediate send)
         * 4) At least 2 days since last message
         */
        $interval = $this->interval;
        $nonAwardedProjects = Project::query()
            ->active()                                                  //1) Project is active (not archived)
            ->where("awarded",false)                                    //2) Project "awarded" = false
            ->where('created_at', '<=', Carbon::now()->$interval(2))    //3) At least 2 days since creating the project (so it doesn't immediate send)
            ->get();

        foreach($nonAwardedProjects as $project) {
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
        $recipient->notify(new ProjectAwardedCheckEmail($project, $recipient));
    }

    public function checkProjectChanges(Project $project): void
    {
        /**
         * Look for any notifications made redundant by project model update, and mark as read
         */

        /*
         * Has the project been awarded to you?
         * Condition: awarded=true
         * enum: HAS_THE_PROJECT_BEEN_AWARDED_TO_YOU
         */
        if($project->awarded){
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
        return "ProjectAwardedCheckEmail";
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

        //Go to project index
        return redirect()->route('projects.index');
    }

    public function markRed(DatabaseNotification $notification): RedirectResponse
    {
        //Mark as read
        $notification->markAsRead();

        //Archive project
        if(isset($notification->data["project_id"])){
            $project = Project::findOrFail($notification->data["project_id"]);
            $project->archive = true;
            $project->save();
        }

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

    public function notificationData(DatabaseNotification $notification): null|array
    {
        $notificationData = null;

        if($notification->type === "App\Notifications\ProjectAwardedCheckEmail"){
            $project_name = $notification->data["project_name"] ?? null;

            $notificationData = [
                "id" => $notification->id,
                "message" => "Has the project '".$project_name."' been awarded to you?",
                "timestamp" => $notification->created_at->diffForHumans(),
                "trafficLights" => [
                    "green" => ["Yes","(Edit)"],
                    "yellow" => ["Not yet","(Ask later)"],
                    "red" => ["Lost it","(Archive)"],
                ],
            ];
        }

        return $notificationData;
    }
}
