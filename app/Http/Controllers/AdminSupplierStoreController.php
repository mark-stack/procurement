<?php

namespace App\Http\Controllers;

use App\Actions\Supplier\AttachSupplierToBusiness;
use App\Http\Requests\StoreSupplierRequest;
use App\Models\Business;
use Illuminate\Http\RedirectResponse;

class AdminSupplierStoreController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(StoreSupplierRequest $request, Business $business): RedirectResponse
    {
        /**
         * The admin suppliers page is the one place a supplier is added to a
         * business other than your own, so the business is a route parameter
         * here. AdminMiddleware is what makes that safe.
         */
        $validated = $request->validated();

        AttachSupplierToBusiness::run(
            $business,
            $validated['name'],
            $validated['supplier_categories'],
        );

        return back();
    }
}
