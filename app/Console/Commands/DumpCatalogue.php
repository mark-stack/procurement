<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Services\ProductRules;
use Illuminate\Console\Command;

/**
 * Write the committed catalogue file from this database.
 *
 * A RESCUE TOOL, AND DELIBERATELY NOT PART OF THE WORKFLOW. The normal direction is one way: edit
 * database/seeders/Data/MasterMaterials.php, run catalogue:sync, commit. Making a habit of dumping
 * the other way puts the source of truth back in two places, which is the arrangement that made
 * "is the file or the database right?" unanswerable in the first place.
 *
 * It exists for the cases where the database is genuinely ahead of the file and nobody wants to
 * retype it:
 *
 *  - somebody edited the catalogue through Admin > Master Materials and wants it committed
 *  - a production catalogue needs capturing after being changed there directly
 *  - the file has to be rebuilt from a known-good database
 *
 * In every one of those the output is a DIFF TO READ, not a result to trust. Run it, look at what
 * git says changed, and keep the parts that were meant.
 *
 * Deprecated products are included, because leaving them out would lose the difference between a
 * product that was retired and one that never existed - and catalogue:sync reads absence as
 * "remove this".
 */
class DumpCatalogue extends Command
{
    protected $signature = 'catalogue:dump {--write : Overwrite the committed file. Without this it only says what would change}';

    protected $description = 'Rebuild database/seeders/Data/MasterMaterials.php from this database';

    private const PATH = 'database/seeders/Data/MasterMaterials.php';

    /**
     * Columns that are never written to the file: the two the category decides, and the one no
     * product has ever carried.
     */
    private const SKIP = ['nesting_algo', 'nominal_units', 'accepted_reason'];

    public function handle(): int
    {
        /*
         * Insertion order, which for a database seeded from the file IS the file's order - so a
         * dump of an untouched catalogue is a no-op rather than a 1,100-line reordering. Sorting by
         * category instead would look tidier and would make every dump an unreadable diff, which
         * defeats the only thing this is for. A product added through the admin screen has a higher
         * id and lands at the end, where it is easy to see.
         */
        $products = Product::query()
            ->platformCreated()
            ->orderBy('id')
            ->get();

        if ($products->isEmpty()) {
            $this->error('This database holds no platform products. Refusing to write an empty catalogue.');

            return self::FAILURE;
        }

        $rendered = $this->render($products);
        $path = base_path(self::PATH);
        $current = is_file($path) ? file_get_contents($path) : '';

        if ($rendered === $current) {
            $this->info(sprintf('No change. The file already describes all %d products.', $products->count()));

            return self::SUCCESS;
        }

        $this->warn(sprintf(
            'The database holds %d products and the file would change. Read the diff before keeping it.',
            $products->count(),
        ));

        if (! $this->option('write')) {
            $this->info('Nothing written - pass --write to overwrite '.self::PATH.'.');

            return self::SUCCESS;
        }

        file_put_contents($path, $rendered);

        $this->info('Written. Now read `git diff '.self::PATH.'` and keep only what was meant.');

        return self::SUCCESS;
    }

    /**
     * The whole file, in the shape MasterMaterials already has.
     *
     * Rebuilt here rather than templated from the existing file, because a dump that preserved the
     * old text would also preserve rows the database no longer holds.
     *
     * @param  \Illuminate\Support\Collection<int, Product>  $products
     */
    private function render($products): string
    {
        $existing = file_get_contents(base_path(self::PATH));
        //Everything up to and including the opening of the array, which is prose worth keeping
        $header = substr($existing, 0, strpos($existing, "        return [\n") + strlen("        return [\n"));

        $lines = [];
        $previous = null;

        foreach ($products as $product) {
            $signature = implode(' ', array_filter([
                $product->product_category,
                $product->grade,
                $product->surface,
            ]));

            if ($signature !== $previous) {
                if ($previous !== null) {
                    $lines[] = '';
                }

                $lines[] = '            //'.$signature;
                $previous = $signature;
            }

            $lines[] = '            ['.implode(', ', $this->fields($product)).'],';
        }

        return $header.implode("\n", $lines)."\n        ];\n    }\n}\n";
    }

    /**
     * One product as its non-empty fields, so a row says only what it sets.
     *
     * @return array<int, string>
     */
    private function fields(Product $product): array
    {
        $fields = [];

        foreach (ProductRules::EDITABLE as $column) {
            if (in_array($column, self::SKIP, true)) {
                continue;
            }

            $value = $product->{$column};

            //'' and null are the same absence in the file; the seeder puts the right one back
            if ($value === null || $value === '' || ($column === 'deprecated' && ! $value)) {
                continue;
            }

            $fields[] = sprintf(
                "'%s' => %s",
                $column,
                match (true) {
                    is_bool($value) => $value ? 'true' : 'false',
                    is_float($value) => (string) $value,
                    default => "'".str_replace("'", "\\'", (string) $value)."'",
                },
            );
        }

        return $fields;
    }
}
