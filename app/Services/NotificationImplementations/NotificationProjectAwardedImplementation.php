<?php

namespace App\Services\NotificationImplementations;

use App\Models\Project;
use App\Notifications\ProjectAwardedCheckEmail;
use App\Services\Interfaces\NotificationInterface;
use Illuminate\Http\RedirectResponse;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Carbon;

class NotificationProjectAwardedImplementation implements NotificationInterface
{
    public string $subInterval;

    public function __construct()
    {
        $testMode = config("env.test_mode");
        $this->subInterval = $testMode ? 'subMinutes' : 'subDays';
    }

    public function hourlyCheck(): void
    {
        /**
         * Has the project been awarded to you?
         * 1) Project is active (not archived)
         * 2) Project "awarded" = false
         * 3) At least 2 days since creating the project (so it doesn't immediate send)
         * 4) At least 2 days since last reminder
         */
        $subInterval = $this->subInterval;
        $nonAwardedProjects = Project::query()
            ->active()                                                  //1) Project is active (not archived)
            ->where("awarded",false)                                    //2) Project "awarded" = false
            ->whereBetween('created_at', [Carbon::now()->$subInterval(2), Carbon::now()]) //3)
            ->get();

        foreach($nonAwardedProjects as $project) {
            $projectManager = $project->user;

            if (!$this->notifiedAlready($projectManager, $project->id)) {
                //Mark all previous as read
                $this->markPreviousAsRead($projectManager, $project);

                //Send notification
                $this->sendNotification($projectManager,$project);
            }
        }
    }

    public function notifiedAlready(object $recipient, int $uniqueModelId): bool
    {
        $class = $this->getNotificationClass();

        $subInterval = $this->subInterval;
        $classWithPath = "App\Notifications\\".$class;
        return $recipient->notifications()
            ->where("type",$classWithPath)
            ->where("notifiable_type","App\Models\User")
            ->where("data->project_id",$uniqueModelId)
            ->whereBetween('created_at', [Carbon::now()->$subInterval(2), Carbon::now()]) //4)
            ->exists();
    }

    public function sendNotification(object $recipient, object $otherObject): void
    {
        $project = $otherObject;
        $message = $this->message($project->name,"");
        $recipient->notify(new ProjectAwardedCheckEmail($project, $recipient, $message));
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
            $recipient = $project->user()->first();

            $recipient->notifications()
                ->where("type",$classWithPath)
                ->where("notifiable_type","App\Models\User")
                ->where("data->project_id",$project->id)
                ->update(['read_at' => now()]);
        }
    }

    public function getNotificationClass(): string
    {
        return "ProjectAwardedCheckEmail";
    }

    public function markPreviousAsRead(object $recipient, object $otherObject): void
    {
        $class = $this->getNotificationClass();
        $classWithPath = "App\Notifications\\".$class;

        $recipient->notifications()
            ->where("type",$classWithPath)
            ->where("notifiable_type","App\Models\User")
            ->where("data->project_id",$otherObject)
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
                default => back(),
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

        if($this->isCorrectClass($notification)){
            $projectName = $notification->data["project_name"] ?? null;
            $message = $this->message($projectName,"");

            $notificationData = [
                "id" => $notification->id,
                "message" => $message,
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

    public function message(string $string_1, string $string_2): string
    {
        $projectName = $string_1;

        return 'Has the project "'.$projectName.'" been awarded to you?';
    }
}
