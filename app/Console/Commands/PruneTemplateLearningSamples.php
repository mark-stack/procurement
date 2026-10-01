<?php

namespace App\Console\Commands;

use App\Models\TemplateLearningAttempt;
use Illuminate\Console\Command;

class PruneTemplateLearningSamples extends Command
{
    protected $signature = 'templates:prune-samples';

    protected $description = 'Delete the stored spreadsheets of learning attempts nobody has picked up';

    /**
     * A failed learning attempt keeps a copy of the customer's spreadsheet for one reason: so an
     * admin can reproduce the failure and finish the template by hand. That reason has a shelf life.
     *
     * An attempt nobody has opened in a month is not about to be opened, and what is sitting there in
     * the meantime is a customer's bill of materials - their parts, their quantities, their project -
     * held by us because of something that went wrong once. So the file goes and the row stays: the
     * account of what failed and why is small, says everything an audit of the feature would ask, and
     * contains none of their data.
     *
     * Resolving an attempt deletes its sample immediately, so everything this finds is by definition
     * something nobody dealt with.
     */
    public function handle(): int
    {
        $cutoff = now()->subDays((int) config('templates.learning.sample_retention_days'));

        $pruned = 0;

        TemplateLearningAttempt::query()
            ->whereNotNull('sample_path')
            ->where('created_at', '<', $cutoff)
            //Chunked by id: the whole point is not to hold every stored sample's row in memory at once
            ->chunkById(100, function ($attempts) use (&$pruned) {
                foreach ($attempts as $attempt) {
                    $attempt->discardSample();
                    $attempt->save();
                    $pruned++;
                }
            });

        $this->info($pruned === 0
            ? 'No learning-attempt samples were old enough to prune.'
            : "Deleted {$pruned} learning-attempt sample(s).");

        return self::SUCCESS;
    }
}
