<?php

namespace App\Actions\Supplier;

use App\Models\Business;
use App\Models\Supplier;
use Lorisleiva\Actions\Concerns\AsAction;

class AttachSupplierToBusiness
{
    use AsAction;

    public function handle(Business $business, string $name, array $categories): Supplier
    {
        /**
         * Suppliers are shared across businesses, so find before create -
         * the business is then attached to whichever one it is.
         */
        $supplier = Supplier::query()->firstOrCreate(
            [
                'name' => $name,
                'supplier_categories' => serialize($categories),
            ],
        );

        $business->suppliers()->syncWithoutDetaching([$supplier->id]);

        return $supplier;
    }
}
