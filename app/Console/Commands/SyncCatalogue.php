<?php

namespace App\Console\Commands;

use App\Services\MaterialsJsonImport;
use App\Services\ProductRules;
use Database\Seeders\Data\MasterMaterials;
use Illuminate\Console\Command;
use Throwable;

/**
 * Reconcile this database's platform catalogue to the committed one.
 *
 * The committed file is the catalogue. This is the only thing that writes it into a database that
 * already has products - MasterMaterialsSeeder refuses that case on purpose, because re-seeding
 * over an edited catalogue would resurrect every deprecated row.
 *
 * ## Why a command and not a seeder
 *
 * Production blocks seeding, and rightly: DatabaseSeeder creates the SAMPLE business, users and
 * projects alongside the catalogue, which is the whole reason the deploy instructions had to say
 * "never plain --seed". A plain console command carries no ConfirmableTrait, so it runs on a Forge
 * deploy with no --force and no prompt, and production stops touching the seeder mechanism at all.
 *
 * ## What "absent from the file" means
 *
 * Removal is decided HERE, against THIS database, and is never recorded in the file. A product
 * nothing refers to is deleted; one with pieces, bars, offcuts, quotes or keywords against it is
 * deprecated instead. That asymmetry is not a nicety - nothing holds a foreign key to products, so
 * deleting one that work is matched to orphans that work silently.
 *
 * It follows that the same file produces different outcomes in different environments, and that is
 * correct rather than a bug: a product unused on a laptop is very likely used in production, and
 * those are exactly the ones a laptop would wrongly decide to delete.
 *
 * Be precise about what "unused" means, because it is narrower than it sounds. Services\ProductUsage
 * matches work to a product BY SPEC, field by field, so "deletable" means no work is MATCHED to it -
 * not that no work mentions its category. A piece whose spec matches no product at all is invisible
 * here, and deleting products cannot orphan it further because it was never attached to one.
 *
 * ## Safety
 *
 * A dry run by default, like scrap:revalue, because this restates a catalogue. --apply writes, in
 * one transaction. Deletes are capped, because a file that wants to remove hundreds of products is
 * a bad file rather than an intention, and "everything is deletable" is exactly what a mis-generated
 * one looks like.
 */
class SyncCatalogue extends Command
{
    protected $signature = 'catalogue:sync
                            {--apply : Write the changes. Without this nothing is written}
                            {--max-deletes=25 : Refuse a plan that would delete more than this}';

    protected $description = 'Reconcile the platform catalogue to database/seeders/Data/MasterMaterials.php';

    public function handle(): int
    {
        $rows = MasterMaterials::rows();

        /*
         * Replace, because the file is the WHOLE catalogue - that is what makes absence meaningful.
         * A merge could only ever add, so a product deleted from the file would live forever.
         */
        $payload = [
            'mode' => 'replace',
            'products' => array_map(fn (array $row): array => [
                ...array_fill_keys(ProductRules::EDITABLE, null),
                'deprecated' => false,
                ...$row,
            ], $rows),
        ];

        $import = new MaterialsJsonImport;
        $plan = $import->plan($payload, deleteUnused: true);

        $this->table(
            ['create', 'update', 'unchanged', 'deprecate', 'delete', 'errors'],
            [[
                $plan['counts']['create'],
                $plan['counts']['update'],
                $plan['counts']['unchanged'],
                $plan['counts']['deprecate'],
                $plan['counts']['delete'],
                $plan['counts']['errors'],
            ]],
        );

        if ($plan['errors'] !== []) {
            $this->error(sprintf('%d rows in the committed catalogue are invalid. Nothing written.', count($plan['errors'])));

            foreach (array_slice($plan['errors'], 0, 10) as $error) {
                $this->line(sprintf('  row %d  %s  %s', $error['row'], $error['label'] ?? '', $error['messages'][0] ?? ''));
            }

            return self::FAILURE;
        }

        /*
         * Listed, not just counted, and the two are listed separately. A deprecation is reversible
         * and a deletion is not, so a deploy log that ran them together would be the one place
         * somebody could not tell what had happened.
         */
        $this->report('Deleting (nothing refers to these)', $plan['delete']);
        $this->report('Deprecating (still in use here)', $plan['deprecate']);

        $cap = (int) $this->option('max-deletes');

        if (count($plan['delete']) > $cap) {
            $this->error(sprintf(
                'Refusing to delete %d products in one run; the cap is %d. Raise it with '
                .'--max-deletes if that is really the intention.',
                count($plan['delete']),
                $cap,
            ));

            return self::FAILURE;
        }

        if (! $this->option('apply')) {
            $this->info('Dry run. Nothing was written - pass --apply to make these changes.');

            return self::SUCCESS;
        }

        try {
            foreach ($import->apply($plan) as $line) {
                $this->info($line);
            }
        } catch (Throwable $e) {
            $this->error('Nothing was written: '.$e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * @param  array<int, array<string, mixed>>  $entries
     */
    private function report(string $heading, array $entries): void
    {
        if ($entries === []) {
            return;
        }

        $this->line('');
        $this->line($heading.':');

        foreach ($entries as $entry) {
            $this->line(sprintf(
                '  %s%s',
                $entry['description'] !== '' ? $entry['description'] : $entry['label'],
                isset($entry['usage']) && is_string($entry['usage']) ? '  - '.lcfirst($entry['usage']) : '',
            ));
        }
    }
}
