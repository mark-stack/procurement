<?php

namespace App\Notifications;

use App\Models\Business;
use Illuminate\Notifications\Notification;

/**
 * "Your rack is carrying steel that has stopped earning its place."
 *
 * Raised once a quarter by the offcut cleanout, and only when there is actually something on the
 * list - see Console\Commands\OffcutQuarterlyCleanout. It reports, it does not ask: nothing is
 * scrapped until somebody opens the rack and says so.
 *
 * Bell only, like the colleague notifications and for the same reason. This is worth a red dot next
 * time you look at the application; it is not worth an email four times a year to a project manager
 * who will deal with it when they are next in the yard.
 */
class OffcutCleanoutDue extends Notification
{
    public function __construct(
        public Business $business,
        public int $count,
        public float $netDrain,
        public string $quarter,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'business_id' => $this->business->id,
            'count' => $this->count,
            /*
             * The labour these pieces will cost over their life on the rack, over and above what
             * they retain as inventory. A lifetime figure rather than an annual one - that is what
             * NestingCostModel::offcutRackMinutes() measures.
             */
            'net_drain' => round($this->netDrain, 2),
            /*
             * Which quarter raised it. Two of these can be unread at once - a rack nobody cleared
             * last quarter is a rack that still needs clearing - and without this they would read as
             * the same notice twice.
             */
            'quarter' => $this->quarter,
        ];
    }
}
