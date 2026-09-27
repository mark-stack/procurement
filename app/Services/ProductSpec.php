<?php

namespace App\Services;

use App\Enums\NestingEnums;
use App\Models\Product;

/**
 * Which of a product's columns are its identity, and how two of them are compared.
 *
 * Nesting never looks a product up by id. It builds a piece spec from the mandatory fields of the
 * product category's implementation, then queries the products table field by field
 * (NestingFormatter::wherePieceSpecField) to find the purchasable stock lengths and the mass per
 * metre. pieces, bars and offcuts all record the same spec on their own rows and carry no
 * product_id at all.
 *
 * So these columns are a join key, not just data. Editing one on a product that already has work
 * against it does not correct that work - it silently detaches it, and the pieces, bars and offcuts
 * that used to resolve to the product resolve to nothing.
 */
class ProductSpec
{
    /**
     * Columns pieces, bars and offcuts each actually have. The key is intersected with these
     * before a row on one of them is compared to a product.
     */
    public const USAGE_TABLE_COLUMNS = [
        'pieces' => [
            'product_category', 'material', 'grade', 'surface', 'nominal_units',
            'nominal_length', 'nominal_width', 'nominal_height',
            'precise_length', 'precise_width', 'precise_height', 'wall', 'kg_per_m',
        ],
        //Neither records the measurement unit or the mass per metre
        'bars' => [
            'product_category', 'material', 'grade', 'surface',
            'nominal_length', 'nominal_width', 'nominal_height',
            'precise_length', 'precise_width', 'precise_height', 'wall',
        ],
        'offcuts' => [
            'product_category', 'material', 'grade', 'surface',
            'nominal_length', 'nominal_width', 'nominal_height',
            'precise_length', 'precise_width', 'precise_height', 'wall',
        ],
    ];

    /**
     * Columns compared as numbers rather than as text.
     *
     * The same measurement is a varchar on products and pieces but a float on bars, offcuts and
     * products.wall, so "2.0", "2" and 2.0 all describe one wall thickness. Comparing them as
     * text would report a product as unused and let its spec be edited out from under the bars
     * already cut to it.
     */
    private const NUMERIC = [
        'nominal_length', 'nominal_width', 'nominal_height',
        'precise_length', 'precise_width', 'precise_height', 'wall', 'kg_per_m',
    ];

    /**
     * generalProductDefinition() reaches for every implementation in the directory and reflects
     * over each one, so it is far too expensive to call per product row.
     *
     * @var array<string, array<int, string>>
     */
    private array $keys = [];

    /**
     * Same reason. A JSON import asks for this once per row.
     *
     * @var array<string, array<int, string>>
     */
    private array $duplicateKeys = [];

    /**
     * Single purpose: the columns that identify a product of this category.
     *
     * mandatory is what a piece spec is built from, and purchasableVariations is what one spec
     * offers a choice of - a PFC piece spec fixes the section and asks the catalogue which stock
     * lengths it comes in. Both halves have to match for a product to be the one in use.
     *
     * An unrecognised category falls through to DataClassificationService's fallback, which names
     * nearly every column. That is the safe direction: nothing is editable that might be a key.
     *
     * @return array<int, string>
     */
    public function keyFor(?string $productCategory): array
    {
        $category = (string) $productCategory;

        if (isset($this->keys[$category])) {
            return $this->keys[$category];
        }

        $definition = (new ProductService)->generalProductDefinition($category);

        return $this->keys[$category] = array_values(array_unique([
            ...$definition['mandatory'],
            ...$definition['purchasableVariations'],
        ]));
    }

    /**
     * Single purpose: the columns that make two products genuinely indistinguishable.
     *
     * Wider than the spec key, and only for BUNDLE products. A bundled product's purchasable
     * options are its pack sizes, and getPurchasableVariations gathers them across every matching
     * product on purpose - "every usable pack size across the matching products, not just the first
     * row's". The catalogue uses that: "M12x30 GR8.8 ZINC" in packs of 25 and "M12x30 GR8.8 ZINC
     * Assembly" in packs of 200 are one bolt offered two ways, sharing a spec key by design.
     *
     * So two BUNDLE rows are only a duplicate when their pack sizes agree too. For METERAGE and
     * AREA the purchasable options are nominal dimensions, which purchasableVariations already puts
     * in the spec key - two of those sharing a key really do offer the same thing twice, and
     * resolveKgPerM would silently take the heavier of their two weights.
     *
     * @return array<int, string>
     */
    public function duplicateKeyFor(?string $productCategory): array
    {
        $category = (string) $productCategory;

        if (isset($this->duplicateKeys[$category])) {
            return $this->duplicateKeys[$category];
        }

        $key = $this->keyFor($category);

        $implementation = (new DataClassificationService)
            ->findImplementationFromProductCategory($category);

        return $this->duplicateKeys[$category] = $implementation?->config()['algorithm'] === NestingEnums::BUNDLE
            ? [...$key, 'pack_size_1', 'pack_size_2', 'pack_size_3']
            : $key;
    }

    /**
     * Single purpose: the key columns a given usage table can be compared on.
     *
     * @return array<int, string>
     */
    public function keyForUsageTable(?string $productCategory, string $usageTable): array
    {
        return array_values(array_intersect(
            $this->keyFor($productCategory),
            self::USAGE_TABLE_COLUMNS[$usageTable],
        ));
    }

    /**
     * Single purpose: a product's key as a comparable string, so two of them can be matched
     * without comparing column by column at every call site.
     *
     * @param  array<string, mixed>|object  $source  A product, a parsed row, or a group-by result
     * @param  array<int, string>  $columns
     */
    public function fingerprint(array|object $source, array $columns): string
    {
        $parts = [];

        foreach ($columns as $column) {
            $parts[] = $this->canonical($column, $this->read($source, $column));
        }

        //Unit separator - a grade or surface can legitimately contain most printable characters
        return implode("\x1F", $parts);
    }

    /**
     * Single purpose: name the key columns whose value differs between two versions of a product.
     *
     * The key is taken from the category being moved TO as well as the one being moved FROM, so
     * changing the category itself is always caught even when the new category's key is narrower.
     *
     * @param  array<string, mixed>  $changes
     * @return array<int, string>
     */
    public function changedKeyColumns(Product $product, array $changes): array
    {
        $columns = array_unique([
            ...$this->keyFor($product->product_category),
            ...$this->keyFor($changes['product_category'] ?? $product->product_category),
        ]);

        $changed = [];

        foreach ($columns as $column) {
            if (! array_key_exists($column, $changes)) {
                continue;
            }

            $before = $this->canonical($column, $product->{$column});
            $after = $this->canonical($column, $changes[$column]);

            if ($before !== $after) {
                $changed[] = $column;
            }
        }

        return $changed;
    }

    /**
     * Single purpose: one value reduced to the form both sides of a comparison agree on.
     *
     * A blank cell and a null are the same absence - the spreadsheet stored '' for every blank
     * and the products table has held both ever since.
     */
    public function canonical(string $column, mixed $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        if (in_array($column, self::NUMERIC, true)) {
            return (string) (float) $value;
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        return strtoupper(trim((string) $value));
    }

    /**
     * @param  array<string, mixed>|object  $source
     */
    private function read(array|object $source, string $column): mixed
    {
        return is_array($source)
            ? ($source[$column] ?? null)
            : ($source->{$column} ?? null);
    }
}
