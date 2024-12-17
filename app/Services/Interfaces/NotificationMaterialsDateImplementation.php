<?php

namespace App\Services\Interfaces;

use App\Models\Project;
use App\Notifications\ProjectTentativeDateCheckEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Carbon;

class NotificationMaterialsDateImplementation implements NotificationInterface
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
         * Is the materials date still correct?
         * 1) Project is active (not archived)
         * 2) Project tentative = true
         * 3) At least 2 days since creating the project (so it doesn't immediate send)
         * 4) At least 2 days since last reminder
         */
        $subInterval = $this->subInterval;

        $tentativeProjects = Project::query()
            ->active()                             //1) Project is active (not archived)
            ->where("tentative",true)              //2) Project tentative = true
            ->whereBetween('created_at', [Carbon::now()->$subInterval(2), Carbon::now()]) //3)
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

        $subInterval = $this->subInterval;

        return $recipient->notifications()
            ->where("type","App\Notifications\{$class}")
            ->where("notifiable_type","App\Models\User")
            ->whereBetween('created_at', [Carbon::now()->$subInterval(2), Carbon::now()])
            ->exists();
    }

    public function sendNotification(object $recipient, object $otherObject): void
    {
        $project = $otherObject;
        $message = $this->message($project->date_materials_required, $project->name);
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
        /**
         * "Lock it in"
         * tentative=true
         */
        $project = Project::findOrFail($notification->data["project_id"]);
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
        return redirect()->route('projects.index');
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

        if($this->isCorrectClass($notification)){
            $materialsDate = $notification->data["date_materials_required"] ?? null;
            $projectName = $notification->data["project_name"] ?? null;

            $message = $this->message($materialsDate,$projectName);

            $notificationData = [
                "id" => $notification->id,
                "message" => $message,
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

    public function message(string $string_1, string $string_2): string
    {
        $materialsDate = $string_1;
        $projectName = $string_2;

        return "Is the tentative materials date of ".$materialsDate." for '".$projectName."' still correct?";
    }
}
