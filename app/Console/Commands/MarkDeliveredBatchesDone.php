<?php

namespace App\Console\Commands;

use App\Services\DeliveredBatchAutoDone;
use Illuminate\Console\Command;

/**
 * Close any batch whose steel has all been in long enough that the card is only in the way.
 *
 * Its own command rather than a line in HourlyNotificationsJob, for the reason
 * WarnBeforeFabricationDeadline is: that job sends the reminders nobody has had yet, and every
 * implementation on it is a read followed by a notify. This one writes, and what it writes cannot be
 * undone by anything in the application - so it wants its own failure handling and its own switch.
 *
 * See App\Services\DeliveredBatchAutoDone for what holds a card on the board instead.
 */
class MarkDeliveredBatchesDone extends Command
{
    protected $signature = 'batches:mark-delivered-done';

    protected $description = 'Move batches whose orders were all delivered more than '
        .DeliveredBatchAutoDone::DAYS_AFTER_DELIVERY.' days ago into past batches';

    public function handle(): int
    {
        $batches = (new DeliveredBatchAutoDone)->sweep();

        $this->info('Batches moved to past batches: '.count($batches));

        return self::SUCCESS;
    }
}
