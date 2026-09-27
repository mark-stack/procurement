<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Services\ProductRules;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The catalogue as a JSON file the importer accepts back.
 *
 * This is how two environments are kept in step. Export from whichever one is right, import into the
 * other, and the preview says exactly what would change before anything does. A database dump would
 * carry every project, quote and order with it; this carries the catalogue and nothing else.
 *
 * mode "replace" on purpose: the export is the whole catalogue, so importing it should deprecate
 * whatever the source no longer lists. The importer still lists every one of those before applying.
 */
class AdminMaterialExportController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            /*
             * Only what has been touched since this moment. This is the everyday case: a few products
             * added or corrected here that need to reach the other environment, without dragging
             * 1,107 unrelated rows along to be diffed.
             */
            'since' => ['nullable', 'date'],
        ]);

        $since = isset($validated['since']) ? Carbon::parse($validated['since']) : null;

        $products = $this->products($since);

        /*
         * The safety property that makes a partial export safe to hand to the importer: a file that
         * is NOT the whole catalogue must never be a "replace" file. Replace deprecates everything
         * absent from the file, so importing a three-product subset as a replace would retire the
         * entire rest of the catalogue. The mode is derived here rather than chosen, so that cannot
         * be got wrong by whoever opens the file later.
         */
        $mode = $since === null ? 'replace' : 'merge';

        $filename = sprintf(
            'master-materials-%s-%s.json',
            $mode === 'replace' ? 'full' : 'since-'.$since->format('Y-m-d'),
            now()->format('Y-m-d-His'),
        );

        return response()
            ->json([
                //So a file found on a disk in six months says what it is and where it came from
                'exported_from' => config('app.url'),
                'exported_at' => now()->toIso8601String(),
                'changed_since' => $since?->toIso8601String(),
                'product_count' => count($products),
                'mode' => $mode,
                'products' => $products,
            ], options: JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
            ->withHeaders([
                'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            ]);
    }

    /**
     * Exactly the columns the importer reads, in the order the form shows them.
     *
     * nesting_algo and nominal_units are deliberately absent: the importer derives them from the
     * category and refuses a file that tries to set them, so exporting them would produce a file
     * that could not be imported.
     *
     * Deprecated products are included. They are part of the catalogue's state - leaving them out
     * would mean a round trip through export and import lost the difference between a product that
     * was deprecated and one that never existed.
     *
     * @return array<int, array<string, mixed>>
     */
    private function products(?Carbon $since): array
    {
        return Product::query()
            ->platformCreated()
            /*
             * updated_at alone is enough: a product created and never touched again carries the same
             * value in both columns, and every write path goes through Eloquent - the form, the JSON
             * importer's create and update, and the deprecate sweep - so all of them maintain it.
             */
            ->when($since, fn ($query) => $query->where('updated_at', '>=', $since))
            ->orderBy('product_category')
            ->orderBy('id')
            ->get()
            ->map(function (Product $product) {
                $row = [];

                foreach (ProductRules::EDITABLE as $column) {
                    $value = $product->{$column};

                    /*
                     * Blank and null are the same absence here, and the table holds both.
                     * Normalising on the way out means diffing two environments' exports shows real
                     * differences rather than which of them happened to store '' that day.
                     */
                    $row[$column] = $value === '' ? null : $value;
                }

                return $row;
            })
            ->all();
    }
}
