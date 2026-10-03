<?php

namespace App\Console\Commands;

use App\Services\FabricationDeadlineQuoting;
use Illuminate\Console\Command;

/**
 * Warn the Nesting column for any business whose shop is about to start cutting.
 *
 * It used to start the quoting as well, and was named for it. It does not any more: creating a batch
 * nests steel, consumes offcut inventory and commits a business to a purchase, and that is a decision
 * for whoever presses "Start quoting". All this does now is make sure they know it is time.
 *
 * Still its own command rather than a line in HourlyNotificationsJob. That job's contract is "send
 * the reminders nobody has had yet" and it decides who is due off a project's own dates; this has to
 * read a whole business's unbatched pieces through the price book to know whether there is a column
 * at all, and an operator switching one of them off should not be switching the other off with it.
 *
 * See App\Services\FabricationDeadlineQuoting for which project triggers a warning and who hears it.
 */
class WarnBeforeFabricationDeadline extends Command
{
    protected $signature = 'quoting:fabrication-deadline';

    protected $description = 'Warn the Nesting column for any business with a project whose fabrication begins within '
        .FabricationDeadlineQuoting::DAYS_BEFORE_FABRICATION.' days';

    public function handle(): int
    {
        $warned = (new FabricationDeadlineQuoting)->warnAll();

        $this->info('Columns warned about a fabrication deadline: '.count($warned));

        return self::SUCCESS;
    }
}
