<?php

namespace App\Console\Commands;

use App\Services\FabricationDeadlineQuoting;
use Illuminate\Console\Command;

/**
 * Move the Nesting column into Quoting for any business whose shop is about to start cutting.
 *
 * Its own command rather than a line in HourlyNotificationsJob: that job's contract is "send the
 * reminders nobody has had yet", and every implementation on it is a read followed by a notify. This
 * creates batches, nests steel and consumes offcut inventory. Those are not the same risk, they do
 * not want the same failure handling, and an operator disabling one should not be disabling the
 * other.
 *
 * See App\Services\FabricationDeadlineQuoting for which project triggers a sweep and why the whole
 * column goes with it.
 */
class StartQuotingBeforeFabrication extends Command
{
    protected $signature = 'quoting:fabrication-deadline';

    protected $description = 'Start quoting the Nesting column for any business with a project whose fabrication begins within '
        .FabricationDeadlineQuoting::DAYS_BEFORE_FABRICATION.' days';

    public function handle(): int
    {
        $batches = (new FabricationDeadlineQuoting)->sweep();

        $this->info('Batches started by a fabrication deadline: '.count($batches));

        return self::SUCCESS;
    }
}
