<?php

namespace App\Services\Interfaces;

use App\Models\Project;
use App\Models\User;
use App\Notifications\NewUserEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Carbon;

class NotificationNewColleagueImplementation implements NotificationInterface
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
         * A new colleague signed up. Notify existing staff users of the same business
         * 1) User created within 2 day
         * 2) Not yourself
         */
        $subInterval = $this->subInterval;
        $newUsers = User::query()
            ->whereBetween('created_at', [Carbon::now()->$subInterval(2), Carbon::now()])
            ->get();

        foreach($newUsers as $newUser){
            $colleagues = $newUser->business->users()->where("id","!=",$newUser->id)->get();
            foreach($colleagues as $colleague){
                if(!$this->notifiedAlready($colleague)){
                    //Mark all previous as read
                    $this->markPreviousAsRead($colleague);

                    //Send notification
                    $this->sendNotification($colleague,$newUser);
                }
            }
        }
    }

    public function notifiedAlready(object $recipient): bool
    {
        $class = $this->getNotificationClass();

        return $recipient->notifications()
            ->where("type","App\Notifications\{$class}")
            ->where("notifiable_type","App\Models\User")
            ->exists();
    }

    public function sendNotification(object $recipient, object $otherObject): void
    {
        $newColleague = $otherObject;
        $message = $this->message($newColleague->name,"");

        $recipient->notify(new NewUserEmail($newColleague,$message));
    }

    public function checkProjectChanges(Project $project): void
    {
        /**
         * Look for any notifications made redundant by project model update, and mark as read
         */
    }

    public function getNotificationClass(): string
    {
        return "NewUserEmail";
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
        // Not used
    }

    public function markRed(DatabaseNotification $notification): RedirectResponse
    {
        // Not used
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
            $userName = $notification->data["new_user_name"] ?? null;
            $message = $this->message($userName,"");

            $notificationData = [
                "id" => $notification->id,
                "message" => $message,
                "timestamp" => $notification->created_at->diffForHumans(),
                "trafficLights" => null,
            ];
        }

        return $notificationData;
    }

    public function message(string $string_1, string $string_2): string
    {
        $userName = $string_1;

        return $userName." recently joined. You can now batch orders together.";
    }
}
