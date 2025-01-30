<?php

namespace App\Services\NotificationImplementations;

use App\Models\Project;
use App\Models\User;
use App\Notifications\NewUserEmail;
use App\Services\Interfaces\NotificationInterface;
use App\Services\NotificationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Carbon;

class NotificationNewColleagueImplementation implements NotificationInterface
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
         * A new colleague signed up. Notify existing staff users of the same business
         * 1) User created within 2 day
         * 2) Not yourself
         */
        $subInterval = $this->subInterval;
        $newUsers = User::query()
            ->whereBetween('created_at', [Carbon::now()->$subInterval(2), Carbon::now()])
            ->get();

        //Has notifications
        if ($newUsers->count() > 0) {
            foreach ($newUsers as $newUser) {
                $colleagues = $newUser->business->users()->where('id', '!=', $newUser->id)->get();
                foreach ($colleagues as $colleague) {
                    if (! $this->hasBeenNotified($colleague, $newUser->id)) {
                        //Mark all previous as read
                        $this->markPreviousAsRead($colleague, $newUser);

                        //Send notification
                        $this->sendNotification($colleague, $newUser);
                    }
                }
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
        $classWithPath = "App\Notifications\\".$class;

        return $recipient->notifications()
            ->where('type', $classWithPath)
            ->where('notifiable_type', "App\Models\User")
            ->where('data->user_id', $uniqueModelId)
            ->exists();
    }

    public function sendNotification(object $recipient, object $otherObject): void
    {
        $newColleague = $otherObject;
        $message = $this->message($newColleague->name, '');

        $recipient->notify(new NewUserEmail($newColleague, $message));
    }

    public function checkProjectChanges(Project $project): void
    {
        /**
         * Look for any notifications made redundant by project model update, and mark as read
         */
    }

    public function getNotificationClass(): string
    {
        return 'NewUserEmail';
    }

    public function markPreviousAsRead(object $recipient, object $otherObject): void
    {
        $class = $this->getNotificationClass();
        $classWithPath = "App\Notifications\\".$class;

        $recipient->notifications()
            ->where('type', $classWithPath)
            ->where('notifiable_type', "App\Models\User")
            ->where('data->user_id', $otherObject)
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
        // Not used
        return back();
    }

    public function markRed(DatabaseNotification $notification): RedirectResponse
    {
        // Not used
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
            $userName = $notification->data['new_user_name'] ?? null;
            if ($userName) {
                $message = $this->message($userName, '');

                $notificationData = [
                    'id' => $notification->id,
                    'message' => $message,
                    'timestamp' => $notification->created_at->diffForHumans(),
                    'trafficLights' => null,
                ];
            }
        }

        return $notificationData;
    }

    public function message(string $string_1, string $string_2): string
    {
        $userName = $string_1;

        return $userName.' recently joined. You can now batch orders together.';
    }
}
