<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Services\ProductUsage;
use Illuminate\Http\RedirectResponse;

class AdminMaterialDestroyController extends Controller
{
    public function __invoke(Product $product): RedirectResponse
    {
        $usage = (new ProductUsage)->for($product);

        /*
         * Checked here and not only in the screen. Deleting a product that pieces, bars or offcuts
         * are matched on does not raise a foreign key error - none of those tables has one - it just
         * leaves that work matching nothing, which is the failure this whole screen exists to stop.
         *
         * Deprecating is what "remove it from the catalogue" means for a product with history:
         * availableForBusiness stops offering it, and everything already built on it still resolves.
         */
        if (! $usage['deletable']) {
            return back()->with('materials', [
                'ok' => false,
                'message' => sprintf(
                    'Product #%d was not deleted: %s. Deprecate it instead - it then stops being '
                    .'offered for new work while everything already built on it keeps resolving.',
                    $product->id,
                    lcfirst($usage['summary']),
                ),
            ]);
        }

        $product->delete();

        return back()->with('materials', [
            'ok' => true,
            'message' => sprintf('Deleted product #%d. Nothing was using it.', $product->id),
        ]);
    }
}
