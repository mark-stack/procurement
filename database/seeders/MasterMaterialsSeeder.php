<?php

namespace Database\Seeders;

use App\Models\Product;
use App\Services\DataClassificationService;
use App\Services\ProductRules;
use Database\Seeders\Data\MasterMaterials;
use Illuminate\Database\Seeder;

/**
 * Loads the platform product catalogue into an empty database.
 *
 * The catalogue was authored in a spreadsheet and re-imported over itself from an admin button.
 * That is long gone: the products table is edited in the app, and this only has to put rows into a
 * database that has none. A BOM import against an empty products table silently extracts nothing,
 * which is a confusing way to discover that this never ran.
 *
 * The rows themselves moved out of storage/app/private/master_materials.csv and into
 * Data\MasterMaterials on 2026-10-08 - see that class for why, and for what editing it costs. What
 * went with the CSV: MasterMaterialsParser, its positional column map, its parse result, and the
 * exception to the storage/ ignore rule that kept the file in version control at all.
 *
 * Idempotent by refusal rather than by reconcile. Re-running it over a catalogue an admin has since
 * edited would resurrect every row they deprecated and undo every correction they made, so it
 * declines instead and says so.
 */
class MasterMaterialsSeeder extends Seeder
{
    /**
     * Rows per insert. The whole catalogue in one statement exceeds MySQL's default
     * max_allowed_packet on a machine that has not been tuned.
     */
    private const CHUNK = 250;

    public function run(): void
    {
        if (Product::query()->platformCreated()->exists()) {
            $this->command?->warn(sprintf(
                'The platform catalogue already holds %d products - skipping. It is edited in the app '
                .'now (Admin > Master Materials), so re-seeding would undo those edits.',
                Product::query()->platformCreated()->count(),
            ));

            return;
        }

        $rows = MasterMaterials::rows();
        $derived = $this->derivedByCategory($rows);
        $blank = $this->blankRow();
        $now = now();

        foreach (array_chunk($rows, self::CHUNK) as $chunk) {
            Product::insert(array_map(function (array $row) use ($blank, $derived, $now): array {
                /*
                 * Every editable column present, because a ragged row is one that leaves a column
                 * out rather than one that wants the previous row's value - and insert() writes
                 * exactly what it is handed.
                 */
                return [
                    ...$blank,
                    ...$row,
                    ...$derived[$row['product_category']],
                    //Platform catalogue, available to every business
                    'business_id' => null,
                    'deprecated' => false,
                    //insert() bypasses the model, so the timestamps are not filled in for us
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }, $chunk));
        }

        $this->command?->info(sprintf('%d products seeded.', count($rows)));
    }

    /**
     * What a column holds when a row does not mention it.
     *
     * NOT null for most of them. The products table has held '' for a blank string column since the
     * spreadsheet days, and the spec columns are an unenforced join key - pieces, bars and offcuts
     * find their product by comparing these values field by field, so a product holding null where
     * a piece holds '' matches nothing at all. The CSV parser wrote '' for every blank and that is
     * the convention being preserved here, not an accident being copied.
     *
     * The exceptions are the three the parser also treated specially: wall and kg_per_m were cast
     * to float, so a blank one is genuinely absent rather than empty, and accepted_reason is a
     * later column the catalogue has never filled in.
     *
     * @return array<string, mixed>
     */
    private function blankRow(): array
    {
        return [
            ...array_fill_keys(ProductRules::EDITABLE, ''),
            'wall' => null,
            'kg_per_m' => null,
            'accepted_reason' => null,
        ];
    }

    /**
     * nesting_algo and nominal_units for each category present, resolved once rather than per row.
     *
     * They are ProductRules::DERIVED - the category's implementation decides both, and a row that
     * carried them could disagree with it. Resolving a category means constructing its
     * implementation and reading its config, so this asks fifteen times rather than 1,117.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, array<string, string>>
     */
    private function derivedByCategory(array $rows): array
    {
        $classifier = new DataClassificationService;
        $derived = [];

        foreach ($rows as $row) {
            $category = (string) $row['product_category'];

            if (isset($derived[$category])) {
                continue;
            }

            $config = $classifier->findImplementationFromProductCategory($category)?->config();

            $derived[$category] = [
                'nesting_algo' => $config['algorithm']->value,
                'nominal_units' => $config['measurementUnit']->value,
            ];
        }

        return $derived;
    }
}
