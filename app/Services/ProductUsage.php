<?php

namespace App\Services;

use App\Models\Product;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * What already depends on a product, and therefore what may still be done to it.
 *
 * Two different kinds of dependency, and the difference decides what is protected:
 *
 *  - Work recorded against the product's SPEC, with no foreign key: pieces, bars, offcuts.
 *    Editing a spec column detaches this work rather than correcting it, so the spec locks.
 *  - Rows that name the product by id: quotes, orders, suppliers, keywords. These survive a spec
 *    edit, but the product cannot be deleted out from under them.
 */
class ProductUsage
{
    /**
     * Relations counted by id. quotes and orders are what a spec edit would misrepresent after the
     * fact, so they lock the spec too - a sent order records what was bought.
     */
    private const LOCKING_RELATIONS = ['quotes', 'orders'];

    private const RELATIONS = ['quotes', 'orders', 'suppliers', 'keywords'];

    public function __construct(private ProductSpec $spec = new ProductSpec) {}

    /**
     * Single purpose: usage for one product.
     *
     * @return array<string, mixed>
     */
    public function for(Product $product): array
    {
        return $this->forMany(collect([$product]))[$product->id];
    }

    /**
     * Single purpose: usage for a whole page of products, without a query per row.
     *
     * The spec tables are grouped by (table, category) rather than scanned per product. One group
     * query answers every product of that category on the page, and the group-by is exactly that
     * category's key - typically five or six columns - rather than every column that might be one.
     *
     * @param  Collection<int, Product>  $products
     * @return array<int, array<string, mixed>>
     */
    public function forMany(Collection $products): array
    {
        if ($products->isEmpty()) {
            return [];
        }

        $relationCounts = $this->relationCounts($products);
        $specCounts = $this->specCounts($products);

        $usage = [];

        foreach ($products as $product) {
            $counts = [
                ...$specCounts[$product->id],
                ...$relationCounts[$product->id],
            ];

            $locking = array_sum(array_map(
                fn (string $key) => $counts[$key],
                [...array_keys(ProductSpec::USAGE_TABLE_COLUMNS), ...self::LOCKING_RELATIONS],
            ));

            $usage[$product->id] = [
                'counts' => $counts,
                /*
                 * The spec columns cannot be edited: doing so would detach the work above rather
                 * than correct it. Everything else about the product stays editable.
                 */
                'specLocked' => $locking > 0,
                'lockedColumns' => $locking > 0
                    ? $this->spec->keyFor($product->product_category)
                    : [],
                //Nothing anywhere refers to it, by spec or by id, so removing it loses nothing
                'deletable' => array_sum($counts) === 0,
                'summary' => $this->summarise($counts),
            ];
        }

        return $usage;
    }

    /**
     * Single purpose: counts for everything that names a product by id.
     *
     * @param  Collection<int, Product>  $products
     * @return array<int, array<string, int>>
     */
    private function relationCounts(Collection $products): array
    {
        $counted = Product::query()
            ->whereIn('id', $products->pluck('id'))
            ->withCount(self::RELATIONS)
            ->get()
            ->keyBy('id');

        $counts = [];

        foreach ($products as $product) {
            $row = $counted->get($product->id);

            foreach (self::RELATIONS as $relation) {
                $counts[$product->id][$relation] = (int) ($row->{$relation.'_count'} ?? 0);
            }
        }

        return $counts;
    }

    /**
     * Single purpose: counts for the tables that record a product's spec without referring to it.
     *
     * @param  Collection<int, Product>  $products
     * @return array<int, array<string, int>>
     */
    private function specCounts(Collection $products): array
    {
        $counts = [];

        foreach ($products as $product) {
            foreach (array_keys(ProductSpec::USAGE_TABLE_COLUMNS) as $table) {
                $counts[$product->id][$table] = 0;
            }
        }

        foreach ($products->groupBy('product_category') as $category => $ofCategory) {
            foreach (array_keys(ProductSpec::USAGE_TABLE_COLUMNS) as $table) {
                $columns = $this->spec->keyForUsageTable($category, $table);

                /*
                 * A category whose key survives the intersection with nothing but the category
                 * itself would match every row in the table. Nothing in the catalogue is
                 * identified that loosely, but counting it as usage would be wrong rather than
                 * merely cautious.
                 */
                if (count($columns) < 2) {
                    continue;
                }

                $groups = DB::table($table)
                    ->select([...$columns, DB::raw('count(*) as rows_matching')])
                    ->where('product_category', $category)
                    ->groupBy($columns)
                    ->get();

                $byFingerprint = [];
                foreach ($groups as $group) {
                    $fingerprint = $this->spec->fingerprint($group, $columns);
                    $byFingerprint[$fingerprint] = ($byFingerprint[$fingerprint] ?? 0)
                        + (int) $group->rows_matching;
                }

                foreach ($ofCategory as $product) {
                    $fingerprint = $this->spec->fingerprint($product, $columns);
                    $counts[$product->id][$table] = $byFingerprint[$fingerprint] ?? 0;
                }
            }
        }

        return $counts;
    }

    /**
     * Single purpose: say what is using the product in words an admin can act on, rather than
     * leaving them to read seven zeroes.
     *
     * @param  array<string, int>  $counts
     */
    private function summarise(array $counts): string
    {
        $labels = [
            'pieces' => 'cut list item',
            'bars' => 'nested bar',
            'offcuts' => 'offcut',
            'quotes' => 'quote',
            'orders' => 'order',
            'suppliers' => 'supplier',
            'keywords' => 'keyword',
        ];

        $parts = [];

        foreach ($labels as $key => $label) {
            $count = $counts[$key] ?? 0;

            if ($count > 0) {
                $parts[] = $count.' '.$label.($count === 1 ? '' : 's');
            }
        }

        return $parts === []
            ? 'Not used anywhere'
            : 'Used by '.implode(', ', $parts);
    }
}
