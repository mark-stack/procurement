<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateProductRequest;
use App\Models\Product;
use Illuminate\Http\RedirectResponse;

class AdminMaterialUpdateController extends Controller
{
    public function __invoke(UpdateProductRequest $request, Product $product): RedirectResponse
    {
        /*
         * UpdateProductRequest has already refused any change to a column this product is matched on
         * if anything is using it. What arrives here is safe to write.
         */
        $product->fill($request->productAttributes());

        if (! $product->isDirty()) {
            return back()->with('materials', [
                'ok' => true,
                'message' => 'Nothing changed.',
            ]);
        }

        $changed = array_keys($product->getDirty());
        $product->save();

        return back()->with('materials', [
            'ok' => true,
            'message' => sprintf(
                'Saved product #%d (%s).',
                $product->id,
                implode(', ', array_map(fn (string $column) => str_replace('_', ' ', $column), $changed)),
            ),
        ]);
    }
}
