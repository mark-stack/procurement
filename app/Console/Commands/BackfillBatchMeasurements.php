<?php

namespace App\Console\Commands;

use App\Models\Batch;
use App\Models\BatchMeasurement;
use App\Services\BatchMeasurements;
use Illuminate\Console\Command;

/**
 * Read the yield of every batch nested before yields were written down.
 *
 * Nothing is being invented. A nest has always carried its own totals - what was bought, what was
 * drawn off the rack, what became a part, what went back on the rack and what was destroyed - inside
 * the batch's nested_state, and NestingFormatter::usageStats has always been what adds them up. What
 * was missing was a row to add them up ACROSS batches. So this hands each old batch to the same
 * method that runs when a nest is saved today (Services\BatchMeasurements::recordNest), which reads
 * the saved nest rather than being told what to write - which is what makes the backfilled series
 * reconcile against the percentages the Nesting page has been printing all along.
 *
 * Two things it does not fill in, both for the same reason and both reported rather than guessed:
 *
 *  - THE DELIVERY. Whether a batch's steel arrived in time is a comparison against the day it was
 *    wanted, and that day is the earliest fabrication date among its jobs - live, shared, editable
 *    state that any manager on the job may have moved since (see App\Models\Project). Working it out
 *    now would measure an old delivery against a date that may have been set after it landed, which
 *    is not a measurement of anything. On-time delivery starts from the day it began being recorded.
 *
 *  - THE COST COEFFICIENTS. Those are not recorded for an old batch either, so the dollar figure on
 *    a backfilled row is today's valuation of the nest rather than what it was costed at. The row
 *    says so - cost_from_retained_settings is false - and the report prints the count rather than
 *    mixing the two kinds of figure together.
 *
 * Safe to run twice: recordNest() leaves a batch that already has a measurement alone.
 */
class BackfillBatchMeasurements extends Command
{
    protected $signature = 'measures:backfill {--batch= : One batch id, instead of every batch without a measurement}
                                              {--dry-run : Say what would be written and write nothing}';

    protected $description = 'Write yield measurements for batches nested before they were recorded';

    public function handle(BatchMeasurements $measurements): int
    {
        $query = Batch::query()
            /*
             * Batches that nested something. A batch exists from the moment somebody starts quoting,
             * so plenty have no saved nest at all and there is nothing in them to read.
             */
            ->whereNotNull('nested_state')
            ->whereNotIn('id', BatchMeasurement::query()->select('batch_id'))
            ->orderBy('id');

        if ($this->option('batch') !== null) {
            $query->whereKey((int) $this->option('batch'));
        }

        $written = 0;
        $skipped = 0;
        $dryRun = (bool) $this->option('dry-run');

        /*
         * Chunked by id, and load-bearing rather than tidy: this walks every batch in the
         * installation and each one's nested_state is the largest JSON the application stores.
         *
         * The sandbox scope applies and should. Run from the console there is no test mode to be in,
         * so this sees live batches only - a sandbox is thrown away whole (Services\SandboxCleaner)
         * and its yield is a measurement of work that never happened.
         */
        $query->chunkById(50, function ($chunk) use ($measurements, $dryRun, &$written, &$skipped): void {
            foreach ($chunk as $batch) {
                if ($dryRun) {
                    $this->line('Batch '.$batch->id.' would be read for its yield');
                    $written++;

                    continue;
                }

                if ($measurements->recordNest($batch) === null) {
                    //Nothing the meterage algorithm handles - a bolts-only batch has no yield in mm
                    $skipped++;

                    continue;
                }

                $written++;
            }
        });

        $this->info($dryRun
            ? 'Dry run: '.$written.' batches have a saved nest and no measurement.'
            : 'Measurements written: '.$written.', and '.$skipped.' batches had no meterage to measure.');

        return self::SUCCESS;
    }
}
