<?php

namespace App\Console\Commands;

use App\Models\Batch;
use App\Models\Scrap;
use App\Services\ScrapLedger;
use Illuminate\Console\Command;

/**
 * Write down the scrap of every batch that was nested before scrap was a record.
 *
 * The figures are not being invented. A nest has always recorded, per bar, how much of it nothing
 * was cut out of and whether that remainder reached the threshold to be banked - see
 * NestingFormatter::summariseBars and the scrap_threshold_mm saved on each bar's result. What was
 * missing was a row carrying the weight and the money, and somewhere to add it up from. So this
 * hands each old batch to exactly the same method that runs when a nest is saved today
 * (Services\ScrapLedger::recordNest), which reads the saved nest rather than being told what to
 * write - and that is what makes the backfilled history reconcile against the nesting screens
 * instead of being a second, slightly different account of the same steel.
 *
 * Two things it cannot recover, both reported rather than papered over:
 *
 *  - The bar a drop came off, for batches older than bars.batch_id (the 2026_09_26 migration). The
 *    weight is right and the row says it does not know the bar, which means it is counted in the
 *    monthly total and not against a job.
 *  - The business's cost coefficients as they were then. A row is priced at today's material cost
 *    and today's recovery rate, because the rates in force on the day are not recorded anywhere.
 *    The kilograms are history; the dollars beside them are today's valuation of that history.
 *
 * Safe to run twice: recordNest() leaves a batch that already has scrap rows alone.
 */
class BackfillNestScrap extends Command
{
    protected $signature = 'scrap:backfill {--batch= : One batch id, instead of every batch without scrap}
                                           {--dry-run : Say what would be written and write nothing}';

    protected $description = 'Write scrap rows for batches nested before scrap was recorded';

    public function handle(ScrapLedger $ledger): int
    {
        $query = Batch::query()
            /*
             * Only batches that actually nested something. A batch with no saved nest has no bars, no
             * drops and nothing to reconstruct - and there are plenty of those, because a batch exists
             * from the moment somebody starts quoting.
             */
            ->whereNotNull('nested_state')
            ->whereNotIn('id', Scrap::query()->select('batch_id'))
            ->orderBy('id');

        if ($this->option('batch') !== null) {
            $query->whereKey((int) $this->option('batch'));
        }

        $written = 0;
        $batches = 0;
        $dryRun = (bool) $this->option('dry-run');

        /*
         * Chunked by id, and the chunking is load-bearing rather than tidy: this walks every batch in
         * the installation and each one's nested_state is the largest JSON blob the application
         * stores.
         *
         * Note that the sandbox scope applies, and should. Run from the console there is no test mode
         * to be in, so this sees live batches only - which is the right answer, because a sandbox is
         * thrown away whole (Services\SandboxCleaner) and its scrap would be writing history for steel
         * that never existed.
         */
        $query->chunkById(50, function ($chunk) use ($ledger, $dryRun, &$written, &$batches): void {
            foreach ($chunk as $batch) {
                if ($dryRun) {
                    $this->line('Batch '.$batch->id.' would be read for scrap');
                    $batches++;

                    continue;
                }

                $rows = $ledger->recordNest($batch);

                if ($rows === 0) {
                    continue;
                }

                $written += $rows;
                $batches++;
            }
        });

        $this->info($dryRun
            ? 'Dry run: '.$batches.' batches have a saved nest and no scrap rows.'
            : 'Scrap rows written: '.$written.' across '.$batches.' batches.');

        return self::SUCCESS;
    }
}
