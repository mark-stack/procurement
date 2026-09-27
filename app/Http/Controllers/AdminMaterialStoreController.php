<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreProductRequest;
use App\Models\Product;
use Illuminate\Http\RedirectResponse;

class AdminMaterialStoreController extends Controller
{
    public function __invoke(StoreProductRequest $request): RedirectResponse
    {
        $product = Product::create([
            ...$request->productAttributes(),
            /*
             * Platform catalogue. A product handed a business_id would disappear from this screen
             * into one business's private price book, and availableForBusiness would hide it from
             * everybody else.
             */
            'business_id' => null,
        ]);

        return back()->with('materials', [
            'ok' => true,
            'message' => sprintf('Added product #%d.', $product->id),
        ]);
    }
}
