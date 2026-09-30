<?php

namespace App\Services\NotificationImplementations;

use App\Models\Project;
use App\Notifications\OffcutCleanoutDue;
use App\Services\Interfaces\NotificationInterface;
use Illuminate\Http\RedirectResponse;
use Illuminate\Notifications\DatabaseNotification;

/**
 * The bell's half of the quarterly offcut cleanout.
 *
 * Sending is the command's job (Console\Commands\OffcutQuarterlyCleanout), not hourlyCheck()'s -
 * this runs four times a year and re-deriving the whole rack's economics every hour to decide to do
 * nothing would be the most expensive no-op in the application. hourlyCheck() is therefore empty,
 * and NotificationService::hourlyImplementations() leaves this one out.
 *
 * Listed in implementations() all the same, because that list is what the bell can RENDER. A type
 * that is not on it has its rows sit unread and invisible, with no wording and no way to clear them.
 */
class NotificationOffcutCleanoutImplementation implements NotificationInterface
{
    public function hourlyCheck(): void
    {
        /*
         * Quarterly, and driven by the scheduled command. There is no cheap state an hourly pass
         * could read: deciding whether the rack needs clearing means costing every aged offcut
         * against the floor for its own section.
         */
    }

    /**
     * One notice per business per quarter.
     *
     * The command also keeps a notification_logs mark, which is what actually makes it idempotent
     * across a re-run. This is the second guard, and the one that survives the log being cleared.
     */
    public function hasBeenNotified(object $recipient, int $uniqueModelId): bool
    {
        $classWithPath = "App\Notifications\\".$this->getNotificationClass();

        return $recipient->notifications()
            ->where('type', $classWithPath)
            ->where('notifiable_type', "App\Models\User")
            ->where('data->business_id', $uniqueModelId)
            ->whereNull('read_at')
            ->exists();
    }

    public function sendNotification(object $recipient, object $otherObject): void
    {
        $recipient->notify($otherObject);
    }

    public function checkProjectChanges(Project $project): void
    {
        //Nothing about a project makes the rack's dead stock any less dead
    }

    public function getNotificationClass(): string
    {
        return 'OffcutCleanoutDue';
    }

    public function markPreviousAsRead(object $recipient, object $otherObject): void
    {
        /*
         * Deliberately not superseded. A cleanout nobody did last quarter is still outstanding, and
         * silently replacing it with this quarter's would make an ignored rack look freshly noticed.
         */
    }

    public function trafficLight(DatabaseNotification $notification, string $status): ?RedirectResponse
    {
        if (! $this->isCorrectClass($notification)) {
            return null;
        }

        return match ($status) {
            'GREEN' => $this->markGreen($notification),
            'RED' => $this->markRed($notification),
            default => $this->markYellow($notification),
        };
    }

    /**
     * Go and look at it. Marked read on the way, because the list itself is now in front of them and
     * a red dot pointing at the page they are on says nothing.
     */
    public function markGreen(DatabaseNotification $notification): RedirectResponse
    {
        $notification->markAsRead();

        return redirect()->route('offcuts.index', ['tab' => 'cleanout']);
    }

    public function markRed(DatabaseNotification $notification): RedirectResponse
    {
        //Nothing to refuse - the notice reports, it does not ask
        return $this->markYellow($notification);
    }

    public function markYellow(DatabaseNotification $notification): RedirectResponse
    {
        $notification->markAsRead();

        return back();
    }

    public function isCorrectClass(DatabaseNotification $notification): bool
    {
        $classWithPath = "App\Notifications\\".$this->getNotificationClass();

        return $notification->type === $classWithPath;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function notificationData(DatabaseNotification $notification): ?array
    {
        if (! $this->isCorrectClass($notification)) {
            return null;
        }

        $count = (int) ($notification->data['count'] ?? 0);

        //A notice naming no offcuts is a row that should never have been written; it is not rendered
        if ($count < 1) {
            return null;
        }

        return [
            'id' => $notification->id,
            'message' => $this->message(
                (string) $count,
                number_format((float) ($notification->data['net_drain'] ?? 0), 2),
            ),
            'timestamp' => $notification->created_at->diffForHumans(),
            'trafficLights' => [
                'green' => ['Review the rack', 'See the list'],
                'yellow' => ['Not now', null],
            ],
        ];
    }

    public function message(string $string_1, string $string_2): string
    {
        $count = (int) $string_1;
        $netDrain = $string_2;

        return $count.($count === 1 ? ' offcut has' : ' offcuts have')
            .' sat unused past the shelf life and '.($count === 1 ? 'is' : 'are')
            .' too short to pay for '.($count === 1 ? 'its' : 'their').' keep - about $'
            .$netDrain.' of handling that the steel will not earn back.';
    }
}
