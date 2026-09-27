<?php

namespace App\Http\Controllers;

use App\Enums\GradeEnums;
use App\Enums\MaterialEnums;
use App\Enums\ProductEnums;
use App\Enums\SurfaceEnums;
use App\Http\Resources\ProductResource;
use App\Models\Product;
use App\Services\ProductService;
use App\Services\ProductSpec;
use App\Services\ProductUsage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The master materials catalogue, as something to read and edit rather than re-upload.
 */
class AdminMaterialIndexController extends Controller
{
    /**
     * The catalogue is 1,150 rows. Small enough that nobody needs to page through it blind, large
     * enough that sending it all would put every row's usage in one response.
     */
    private const PER_PAGE = 50;

    public function __invoke(Request $request): Response
    {
        $filters = [
            'search' => trim((string) $request->query('search', '')),
            'category' => (string) $request->query('category', ''),
            'status' => (string) $request->query('status', 'active'),
        ];

        $products = Product::query()
            ->platformCreated()
            ->when($filters['search'] !== '', fn (Builder $query) => $query
                ->where(fn (Builder $any) => $any
                    ->where('description', 'like', '%'.$filters['search'].'%')
                    ->orWhere('product_category', 'like', '%'.$filters['search'].'%')
                    ->orWhere('grade', 'like', '%'.$filters['search'].'%')
                    ->orWhere('material', 'like', '%'.$filters['search'].'%')
                ))
            ->when($filters['category'] !== '', fn (Builder $query) => $query
                ->where('product_category', $filters['category']))
            ->when($filters['status'] === 'active', fn (Builder $query) => $query->active())
            ->when($filters['status'] === 'deprecated', fn (Builder $query) => $query
                ->where('deprecated', true))
            /*
             * Category first, then size. ProductUsage groups the spec tables per category, so a page
             * spanning one category asks three questions rather than three per category - and it is
             * also the order an admin looking for "the 200 PFCs" expects.
             */
            ->orderBy('product_category')
            ->orderByRaw('CAST(nominal_height AS DECIMAL(10,2))')
            ->orderByRaw('CAST(nominal_width AS DECIMAL(10,2))')
            ->orderByRaw('CAST(nominal_length AS DECIMAL(10,2))')
            ->orderBy('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        //One computation for the whole page. See ProductResource::$usage.
        ProductResource::$usage = (new ProductUsage)->forMany(collect($products->items()));

        return Inertia::render('AdminMaterialsIndex', [
            'products' => ProductResource::collection($products),
            'filters' => $filters,
            'options' => $this->options(),
            'categoryDefinitions' => $this->categoryDefinitions(),
            'totals' => [
                'active' => Product::query()->platformCreated()->active()->count(),
                'deprecated' => Product::query()->platformCreated()->where('deprecated', true)->count(),
            ],
        ]);
    }

    /**
     * Every value the form is allowed to offer. Typing these by hand is how the one row holding a
     * grade in its material column got there.
     *
     * @return array<string, array<int, string>>
     */
    private function options(): array
    {
        return [
            'categories' => array_column(ProductEnums::cases(), 'value'),
            'materials' => array_column(MaterialEnums::cases(), 'value'),
            'grades' => array_column(GradeEnums::cases(), 'value'),
            'surfaces' => array_column(SurfaceEnums::cases(), 'value'),
        ];
    }

    /**
     * What each product category means for the form: which columns identify a product of that
     * category, and the nesting algorithm and measurement unit it fixes.
     *
     * The screen reads this rather than hardcoding a second copy of the rules. A category added as
     * a new implementation appears here without this file being touched.
     *
     * @return array<string, array<string, mixed>>
     */
    private function categoryDefinitions(): array
    {
        $spec = new ProductSpec;
        $definitions = [];

        foreach ((new ProductService)->getImplementations() as $className) {
            $implementation = new $className;
            $config = $implementation->config();
            $category = $config['productCategory'];

            $definitions[$category] = [
                //The columns that cannot be edited once the product is in use
                'specColumns' => $spec->keyFor($category),
                'nesting_algo' => $config['algorithm']->value,
                'nominal_units' => $config['measurementUnit']->value,
                'isFastener' => $config['isFastener'],
                //Which nominal sizes this category is described by, and what to call them
                'nominalSizes' => $implementation->getNominalSizeData(),
            ];
        }

        /*
         * ProductEnums holds categories with no implementation of their own (SHS). They fall back to
         * a definition naming nearly every column, which the form has to be able to show.
         */
        foreach (ProductEnums::cases() as $case) {
            if (! isset($definitions[$case->value])) {
                $definitions[$case->value] = [
                    'specColumns' => $spec->keyFor($case->value),
                    'nesting_algo' => null,
                    'nominal_units' => null,
                    'isFastener' => false,
                    'nominalSizes' => null,
                    /*
                     * Named plainly rather than left to be discovered: without an implementation
                     * there is nothing to classify a BOM line into this category, nothing to build
                     * its label, and no algorithm to nest it by.
                     */
                    'unsupported' => true,
                ];
            }
        }

        ksort($definitions);

        return $definitions;
    }
}
