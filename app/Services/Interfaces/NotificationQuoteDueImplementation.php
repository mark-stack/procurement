<?php

namespace App\Services\Interfaces;

use App\Models\Project;
use App\Models\User;
use App\Notifications\NewUserEmail;
use App\Notifications\ProjectAwardedCheckEmail;
use App\Notifications\QuoteDueEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

class NotificationQuoteDueImplementation implements NotificationInterface
{
    public string $subInterval;
    public string $addInterval;

    public function __construct()
    {
        $testMode = env("TEST_MODE");
        $this->subInterval = $testMode ? 'subMinutes' : 'subDays';
        $this->addInterval = $testMode ? 'addMinutes' : 'addDays';
    }

    public function hourlyCheck(): void
    {
        /**
         * Quote due
         * 1) Project is active
         * 2) Project is awarded
         * 3) Expected or default lead time + 2 days before material received date
         * 4) At least 1 day since last reminder
         * 5) Not all materials quotes todo
         */

        $expectedOrderLeadTime = null; //todo: derived from material data
        $orderLeadTime = $expectedOrderLeadTime ?? 3;
        $quoteLeadTime = 2;
        $totalTime = $orderLeadTime + $quoteLeadTime;

        $quoteDueProjects = Project::query()
            ->active()                                              //1) Project is active (not archived)
            ->awarded()                                             //2) Project "awarded" = true
            ->whereBetween('date_materials_required', [             //3) Expected or default lead time + 2 days before material received date
                Carbon::now(),
                Carbon::now()->addDays($totalTime)->toDateString()
            ])
            ->get();

        foreach($quoteDueProjects as $project) {
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

        //Go to quote index
        return redirect()->route('quotes.index');
    }

    public function markRed(DatabaseNotification $notification): RedirectResponse
    {
        //Not used
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
            $materialsDate = $notification->data["date_materials_required"] ?? null;
            $projectName = $notification->data["project_name"] ?? null;

            $message = $this->message($materialsDate,$projectName);

            $notificationData = [
                "id" => $notification->id,
                "message" => $message,
                "timestamp" => $notification->created_at->diffForHumans(),
                "trafficLights" => [
                    "green" => ["Ok","(Go to)"],
                    "yellow" => ["Wait","(Ask later)"],
                    "red" => null,
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
