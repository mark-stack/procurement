<?php

namespace App\Http\Requests;

use App\Models\Product;
use App\Services\ProductRules;
use App\Services\ProductSpec;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * What the admin catalogue screen may write to a product, shared by creating and editing.
 *
 * The rules themselves live in ProductRules, because the JSON importer has to enforce exactly the
 * same ones - otherwise the importer becomes the way to get past them.
 */
abstract class ProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        //AdminMiddleware already guards the route group this reaches
        return true;
    }

    /**
     * True when a blank is acceptable in a column that identifies the product. See ProductRules.
     */
    abstract protected function toleratesBlankSpec(): bool;

    public function rules(): array
    {
        return ProductRules::rules($this->toleratesBlankSpec());
    }

    protected function prepareForValidation(): void
    {
        $this->merge(ProductRules::blanksToNull($this->all()));
    }

    public function after(): array
    {
        return [
            fn (Validator $validator) => $this->guardBlankSpecColumns($validator),
            fn (Validator $validator) => $this->guardDuplicate($validator),
        ];
    }

    /**
     * Single purpose: refuse a new product with a blank in a column it will be matched on.
     *
     * A PFC with no nominal height is matched by no piece spec ever built, so it sits in the
     * catalogue looking complete while being unreachable - it offers no stock length to any nest and
     * contributes no mass per metre. Which columns those are depends on the category, so this cannot
     * be a static rule.
     *
     * Only for new products. The catalogue inherited seven rows with no grade at all, and refusing to
     * save one of those would mean its weight could never be corrected without first rewriting a
     * column the product is matched on.
     */
    private function guardBlankSpecColumns(Validator $validator): void
    {
        if ($this->toleratesBlankSpec() || $validator->errors()->isNotEmpty()) {
            return;
        }

        $attributes = $this->productAttributes();
        $category = $attributes['product_category'] ?? null;

        foreach ((new ProductSpec)->keyFor($category) as $column) {
            if (($attributes[$column] ?? null) !== null && $attributes[$column] !== '') {
                continue;
            }

            $validator->errors()->add($column, sprintf(
                'A %s identifies a %s, so one without it would be matched by no cut list line at all.',
                str_replace('_', ' ', $column),
                $category,
            ));
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function productAttributes(): array
    {
        return ProductRules::attributes($this->safe()->all());
    }

    /**
     * Single purpose: refuse a second product that nesting could not tell apart from the first.
     *
     * Two products sharing an identity are not a duplicate row to be tidied up later - they both
     * match every piece of that spec, so getPurchasableVariations returns each of their stock
     * lengths and resolveKgPerM silently takes the heavier of their two weights. The spreadsheet
     * importer collapsed such rows and only counted how many it had lost.
     *
     * duplicateKeyFor, not keyFor: a bundled product is deliberately offered in several pack sizes,
     * and three such pairs are in the catalogue already.
     */
    private function guardDuplicate(Validator $validator): void
    {
        if ($validator->errors()->isNotEmpty()) {
            return;
        }

        $spec = new ProductSpec;
        $attributes = $this->productAttributes();
        $category = $attributes['product_category'];
        $columns = $spec->duplicateKeyFor($category);
        $fingerprint = $spec->fingerprint($attributes, $columns);

        $clash = Product::query()
            ->platformCreated()
            ->where('product_category', $category)
            ->when($this->existingProduct(), fn ($query, Product $product) => $query->whereKeyNot($product->id))
            ->get()
            ->first(fn (Product $other) => $spec->fingerprint($other, $columns) === $fingerprint);

        if (! $clash) {
            return;
        }

        $validator->errors()->add('product_category', sprintf(
            'Product #%d already has this exact spec%s. Two products nesting cannot tell apart both '
            .'match every piece, so one of them has to change or be deprecated.',
            $clash->id,
            ($clash->description ?? '') === '' ? '' : ' ("'.$clash->description.'")',
        ));
    }

    /**
     * The product being edited, or null when one is being created.
     */
    protected function existingProduct(): ?Product
    {
        $product = $this->route('product');

        return $product instanceof Product ? $product : null;
    }
}
