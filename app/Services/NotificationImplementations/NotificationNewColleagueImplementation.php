<?php

namespace App\Services\NotificationImplementations;

use App\Models\Project;
use App\Models\User;
use App\Notifications\ColleagueJoined;
use App\Services\Interfaces\NotificationInterface;
use Illuminate\Http\RedirectResponse;
use Illuminate\Notifications\DatabaseNotification;

/**
 * "So-and-so joined your business."
 *
 * There is no invitation flow and no staff list: a business is every user whose email domain matched
 * at registration, so a colleague can appear without anybody being told. That makes this the only
 * signal the existing staff get that the headcount changed - and it matters practically, because
 * batching across project managers is the thing that saves money and you cannot batch with somebody
 * you do not know is there.
 *
 * Sent at registration now, not swept for hourly. The sweep asked for every user created in the last
 * two days and re-derived who should have heard about them, which was wrong three ways:
 *
 *  - markPreviousAsRead() matched `data->user_id` against $otherObject, the User model, not its id.
 *    Compared against a JSON scalar that never matched anything, so it cleared nothing, ever.
 *  - hasBeenNotified() was the only thing standing between that and a duplicate. It has no time
 *    window, so it worked - but it meant the sweep queried every colleague of every new user every
 *    hour to decide to do nothing.
 *  - the else branch cleared every NewUserEmail row on the platform whenever nobody had registered
 *    in two days, which included the platform admin's own signup alerts.
 *
 * Registration is a single moment and knows exactly who the colleagues are, so it says so then.
 */
class NotificationNewColleagueImplementation implements NotificationInterface
{
    public function hourlyCheck(): void
    {
        /*
         * Nothing to sweep for. notifyColleaguesOf() is called from registration; there is no state
         * an hourly pass could look at that would tell it anything registration did not already know.
         */
    }

    /**
     * Tell everyone already in the business that someone new turned up.
     */
    public function notifyColleaguesOf(User $newUser): void
    {
        $business = $newUser->business;

        if (! $business) {
            return;
        }

        $colleagues = $business->users()->whereKeyNot($newUser->id)->get();

        foreach ($colleagues as $colleague) {
            if ($this->hasBeenNotified($colleague, $newUser->id)) {
                continue;
            }

            $this->sendNotification($colleague, $newUser);
        }
    }

    public function hasBeenNotified(object $recipient, int $uniqueModelId): bool
    {
        $class = $this->getNotificationClass();
        $classWithPath = "App\Notifications\\".$class;

        return $recipient->notifications()
            ->where('type', $classWithPath)
            ->where('notifiable_type', "App\Models\User")
            ->where('data->colleague_id', $uniqueModelId)
            ->exists();
    }

    public function sendNotification(object $recipient, object $otherObject): void
    {
        $recipient->notify(new ColleagueJoined($otherObject));
    }

    public function checkProjectChanges(Project $project): void
    {
        //Nothing about a project makes a colleague's arrival redundant
    }

    public function getNotificationClass(): string
    {
        return 'ColleagueJoined';
    }

    public function markPreviousAsRead(object $recipient, object $otherObject): void
    {
        /*
         * Each colleague is announced once - hasBeenNotified() has no time window, so there is never
         * a previous one of these to supersede. Kept because the interface asks for it.
         */
    }

    public function trafficLight(DatabaseNotification $notification, string $status): ?RedirectResponse
    {
        if (! $this->isCorrectClass($notification)) {
            return null;
        }

        //There is nothing to decide, so every button is "seen it"
        return $this->markYellow($notification);
    }

    public function markGreen(DatabaseNotification $notification): RedirectResponse
    {
        return $this->markYellow($notification);
    }

    public function markRed(DatabaseNotification $notification): RedirectResponse
    {
        return $this->markYellow($notification);
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
        if (! $this->isCorrectClass($notification)) {
            return null;
        }

        $colleagueName = $notification->data['colleague_name'] ?? null;

        if (! $colleagueName) {
            return null;
        }

        return [
            'id' => $notification->id,
            'message' => $this->message($colleagueName, ''),
            'timestamp' => $notification->created_at->diffForHumans(),
            'trafficLights' => null,
        ];
    }

    public function message(string $string_1, string $string_2): string
    {
        $colleagueName = $string_1;

        return $colleagueName.' joined your business. You can now batch orders together.';
    }
}
