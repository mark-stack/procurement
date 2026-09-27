<?php

namespace App\Http\Requests;

use App\Services\ProductSpec;
use App\Services\ProductUsage;
use Illuminate\Validation\Validator;

class UpdateProductRequest extends ProductRequest
{
    /**
     * Column => what it means, for the refusal message. An admin told only "this field is locked"
     * has no way to know why one measurement is locked and the one beside it is not.
     */
    private const LABELS = [
        'product_category' => 'product category',
        'material' => 'material',
        'grade' => 'grade',
        'surface' => 'surface',
        'nominal_units' => 'measurement unit',
        'nominal_length' => 'nominal length',
        'nominal_width' => 'nominal width',
        'nominal_height' => 'nominal height',
        'precise_length' => 'precise length',
        'precise_width' => 'precise width',
        'precise_height' => 'precise height',
        'wall' => 'wall thickness',
        'kg_per_m' => 'mass per metre',
    ];

    protected function toleratesBlankSpec(): bool
    {
        return true;
    }

    public function after(): array
    {
        return [
            fn (Validator $validator) => $this->guardSpecOfProductInUse($validator),
            ...parent::after(),
        ];
    }

    /**
     * Single purpose: refuse an edit that would detach work already recorded against this product.
     *
     * The spec columns are how pieces, bars and offcuts find their product - none of them holds a
     * product_id. Renaming a grade here does not correct the bars already cut to it; it leaves them
     * matching no product at all, so the nest that produced them can no longer be priced and their
     * offcuts drop out of the inventory the next nest draws on.
     *
     * The honest alternative is offered by the screen rather than guessed at here: deprecate this
     * product, and create the corrected one alongside it. Existing work keeps pointing at the old
     * spec, which is what it was actually built to.
     */
    private function guardSpecOfProductInUse(Validator $validator): void
    {
        $product = $this->existingProduct();

        if (! $product) {
            return;
        }

        $usage = (new ProductUsage)->for($product);

        if (! $usage['specLocked']) {
            return;
        }

        $changed = (new ProductSpec)->changedKeyColumns($product, $this->productAttributes());

        foreach ($changed as $column) {
            $validator->errors()->add($column, sprintf(
                'The %s identifies this product, and it is already in use (%s). Changing it would '
                .'leave that work matching no product at all. Deprecate this product and create a '
                .'corrected one instead.',
                self::LABELS[$column] ?? str_replace('_', ' ', $column),
                lcfirst($usage['summary']),
            ));
        }
    }
}
