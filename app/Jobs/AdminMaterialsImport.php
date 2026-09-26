<?php

namespace App\Jobs;

use App\Models\Product;
use App\Models\User;
use App\Notifications\AdminImportFinalised;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * ShouldBeUnique: one import at a time. Nothing used to stop a second click queueing a second
 * full reconcile of the same catalogue against a different read of the products table.
 */
class AdminMaterialsImport implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /**
     * Must stay below the queue connection's retry_after (90s for the database queue). An
     * untimed job that outran retry_after was handed to a second worker while the first was
     * still inside its transaction, so two imports reconciled the same catalogue at once.
     */
    public int $timeout = 60;

    /**
     * One attempt. A retry would re-run a reconcile whose failure has already been emailed.
     */
    public int $tries = 1;

    /**
     * A worker killed mid-import must not wedge the button for good.
     */
    public int $uniqueFor = 3600;

    /**
     * The columns that identify a product. Two rows agreeing on all of these are the same
     * product, so a corrected pack size or weight updates the existing row instead of
     * stranding it as a deprecated duplicate.
     */
    private const NATURAL_KEY = [
        'description', 'product_category', 'material', 'grade', 'surface',
        'nominal_units', 'nominal_length', 'nominal_width', 'nominal_height', 'wall',
    ];

    /**
     * Everything the spreadsheet is allowed to revise on a product it already created.
     */
    private const MUTABLE = [
        'nesting_algo', 'certificates', 'precise_length', 'precise_width', 'precise_height',
        'pack_size_1', 'pack_size_2', 'pack_size_3', 'kg_per_m', 'baseline_supplier',
    ];

    /**
     * @param  array<int, string>  $parseMessages  What the parser made of the file
     */
    public function __construct(
        public Collection $dataCollection,
        public array $parseMessages = [],
    ) {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        try {
            /*
             * One transaction. The import used to deprecate the whole platform catalogue up
             * front and un-deprecate it row by row, so any failure part way through left every
             * material deprecated and the app with nothing to nest, quote or price.
             */
            $summary = DB::transaction(fn () => $this->import());
        } catch (Throwable $exception) {
            $this->notifyAdmin('The master materials import FAILED. No changes were saved.', [
                $exception->getMessage(),
            ]);

            throw $exception;
        }

        $this->notifyAdmin('The master materials import finished.', [
            ...$this->parseMessages,
            ...$summary,
        ]);
    }

    /**
     * Single purpose: reconcile the products table against the spreadsheet.
     *
     * @return array<int, string>
     */
    private function import(): array
    {
        /*
         * The whole platform catalogue is read once and matched in memory. Looking each row up
         * with its own query meant two queries per row against a table with no index covering
         * the lookup, which grows quadratically with the catalogue.
         */
        $existing = Product::query()
            ->platformCreated()
            ->get()
            ->keyBy(fn (Product $product) => $this->naturalKey($product));

        $seenIds = [];
        $seenKeys = [];
        $created = 0;
        $updated = 0;
        $collisions = 0;

        foreach ($this->dataCollection as $row) {
            $key = $this->naturalKey($row);

            /*
             * Two sheet rows with the same identity are deliberately collapsed into one product
             * rather than creating twice, but the later row's pack sizes and weight overwrite
             * the earlier one's. That is silent data loss unless it is counted and reported.
             */
            if (isset($seenKeys[$key])) {
                $collisions++;
            }

            $seenKeys[$key] = true;

            $product = $existing->get($key);

            // Known product: revise it in place
            if ($product) {
                $product->fill($this->mutableAttributes($row));
                $product->deprecated = false;

                if ($product->isDirty()) {
                    $product->save();
                    $updated++;
                }

                $seenIds[$product->id] = true;

                continue;
            }

            // New product
            $product = Product::create([
                ...$row,
                'business_id' => null,
                'deprecated' => false,
            ]);
            $created++;

            // Keep the index in step so a repeated row in one sheet does not create twice
            $existing->put($key, $product);
            $seenIds[$product->id] = true;
        }

        /*
         * Deprecate only what the sheet no longer lists, so the catalogue is never wholly
         * deprecated, not even for an instant.
         */
        $staleIds = $existing
            ->reject(fn (Product $product) => isset($seenIds[$product->id]))
            ->pluck('id')
            ->all();

        $deprecated = $staleIds === []
            ? 0
            : Product::query()
                ->whereIn('id', $staleIds)
                ->where('deprecated', false)
                ->update(['deprecated' => true]);

        $summary = [
            sprintf('%d products created.', $created),
            sprintf('%d products updated.', $updated),
            sprintf('%d products deprecated (no longer in the spreadsheet).', $deprecated),
        ];

        if ($collisions > 0) {
            $summary[] = sprintf(
                '%d duplicate rows collapsed: two or more rows describe the same product, so only the last one\'s values were kept.',
                $collisions,
            );
        }

        return $summary;
    }

    /**
     * Single purpose: the identity of a product as a single comparable string.
     *
     * @param  Product|array<string, mixed>  $source
     */
    private function naturalKey(Product|array $source): string
    {
        $parts = [];

        foreach (self::NATURAL_KEY as $attribute) {
            $value = is_array($source)
                ? ($source[$attribute] ?? null)
                : $source->{$attribute};

            $parts[] = (string) $value;
        }

        // Unit separator - a description can legitimately contain most printable characters
        return implode("\x1F", $parts);
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function mutableAttributes(array $row): array
    {
        return array_intersect_key($row, array_flip(self::MUTABLE));
    }

    /**
     * @param  array<int, string>  $lines
     */
    private function notifyAdmin(string $message, array $lines): void
    {
        $adminUser = User::query()->where('email', config('env.admin_email'))->first();

        if (! $adminUser) {
            return;
        }

        Notification::send($adminUser, new AdminImportFinalised($message, $lines));
    }
}
