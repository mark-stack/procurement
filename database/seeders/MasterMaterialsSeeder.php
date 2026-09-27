<?php

namespace Database\Seeders;

use App\Models\Product;
use App\Services\MasterMaterialsParser;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Loads the platform product catalogue into an empty database.
 *
 * The catalogue used to be authored in a spreadsheet and re-imported over itself from an admin
 * button. That is gone: the products table is the source of truth now and admins edit it directly,
 * so the CSV's only remaining job is putting 1,150 rows into a database that has none. A BOM import
 * against an empty products table silently extracts nothing, which is a confusing way to discover
 * that this never ran.
 *
 * Idempotent by refusal rather than by reconcile. Re-running it over a catalogue an admin has since
 * edited would resurrect every row they deprecated and undo every correction they made, so it
 * declines instead and says so.
 */
class MasterMaterialsSeeder extends Seeder
{
    public const FILE = 'master_materials.csv';

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

        if (! Storage::exists(self::FILE)) {
            throw new RuntimeException(sprintf(
                '%s not found on the %s disk. The platform product catalogue cannot be seeded '
                .'without it, and a BOM import against an empty catalogue extracts nothing.',
                self::FILE,
                config('filesystems.default'),
            ));
        }

        $handle = Storage::readStream(self::FILE);

        if (! is_resource($handle)) {
            throw new RuntimeException(self::FILE.' could not be read from the configured disk.');
        }

        try {
            /*
             * Still parsed rather than read straight in. The parser is what refuses a reordered
             * sheet (the columns are mapped by position), skips the blank separator rows, tolerates
             * Excel's byte order mark and casts TRUE/FALSE to a boolean the app can query.
             */
            $result = (new MasterMaterialsParser)->parse($handle);
        } finally {
            fclose($handle);
        }

        if ($result->rows === []) {
            throw new RuntimeException('No usable rows found in '.self::FILE.'.');
        }

        $now = now();

        foreach (array_chunk($result->rows, self::CHUNK) as $chunk) {
            Product::insert(array_map(fn (array $row) => [
                ...$row,
                //Platform catalogue, available to every business
                'business_id' => null,
                'deprecated' => false,
                //insert() bypasses the model, so the timestamps are not filled in for us
                'created_at' => $now,
                'updated_at' => $now,
            ], $chunk));
        }

        foreach ($result->messages() as $message) {
            $this->command?->info($message);
        }

        $this->command?->info(sprintf('%d products seeded.', count($result->rows)));
    }
}
