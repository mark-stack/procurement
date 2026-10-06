<?php

namespace App\Http\Controllers;

use App\Enums\GradeEnums;
use App\Enums\MaterialEnums;
use App\Enums\ProductEnums;
use App\Enums\SurfaceEnums;
use App\Http\Resources\ProductResource;
use App\Models\CatalogueReview;
use App\Models\Product;
use App\Models\User;
use App\Services\CatalogueTrust;
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

    /**
     * How many past reviews the panel carries. Enough to see a cadence - or the absence of one -
     * without turning the top of the catalogue screen into a log.
     */
    private const REVIEWS_SHOWN = 5;

    public function __invoke(Request $request): Response
    {
        $filters = [
            'search' => trim((string) $request->query('search', '')),
            'category' => (string) $request->query('category', ''),
            'status' => (string) $request->query('status', 'active'),

            /*
             * One reason off the trust report, or 'any' for every flagged row. The counts on the
             * panel are the links that set it, so the number somebody read and the list they land
             * on are the same question asked twice.
             */
            'trust' => (string) $request->query('trust', ''),
        ];

        /*
         * One pass over the active catalogue, shared by the panel's counts and the filter below -
         * see Services\CatalogueTrust, which memoises it for the life of the instance.
         */
        $trust = new CatalogueTrust;

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
             * whereIn on a list of ids rather than a set of predicates, because one of the four
             * reasons is not portably expressible in SQL - see CatalogueTrust::idsFlaggedAs. The
             * flagged set is a few dozen rows out of 1,100.
             *
             * whereKey([]) and not a no-op when nothing is flagged: "show me the rows with no mass"
             * against a catalogue where every row has one is an empty list, not the whole catalogue.
             */
            ->when($filters['trust'] !== '', fn (Builder $query) => $query
                ->whereKey($trust->idsFlaggedAs($filters['trust'] === 'any' ? null : $filters['trust'])))
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

            /*
             * Whether this catalogue can be relied on, and whether anybody has said so lately.
             *
             * At the top of the screen rather than on a page of its own, because the two halves are
             * the same job: the findings are the list to work through, and recording the review is
             * what you do once you have. A report nobody passes on the way to the catalogue is a
             * report nobody reads.
             */
            'trust' => $trust->report(),
            'reviews' => $this->reviews(),
        ]);
    }

    /**
     * The last few times somebody read the catalogue.
     *
     * A handful rather than the latest one alone. "When was it last reviewed" is answered by the
     * first row; "is it reviewed regularly" is the question 7.1.5 is really asking, and that one
     * needs to see the gaps between them.
     *
     * @return array<int, array<string, mixed>>
     */
    private function reviews(): array
    {
        return CatalogueReview::query()
            ->latestFirst()
            ->with('user:id,name')
            ->limit(self::REVIEWS_SHOWN)
            ->get()
            ->map(fn (CatalogueReview $review): array => [
                'id' => $review->id,
                'reviewed_at' => $review->reviewed_at->toIso8601String(),
                'reviewed_at_label' => $review->reviewed_at->format('j M Y'),
                /*
                 * A review by an admin who has since left still happened, and the date is the half
                 * of it being asserted - see the migration, where the foreign key nulls on delete.
                 *
                 * Named through the relation's own nullability rather than a nullsafe read: static
                 * analysis types a belongsTo as always returning a model, so "?->name ?? ..." reads
                 * to it as a coalesce that can never fire.
                 */
                'by' => $review->user instanceof User ? $review->user->name : 'A deleted account',
                'note' => $review->note,
                'products_reviewed' => $review->products_reviewed,
                /*
                 * What was STILL wrong when they looked, counted then. Shown beside the date so a
                 * review reads as a finding rather than as an attendance record, and so a run of
                 * them shows whether the list is shrinking.
                 */
                'outstanding' => array_sum($review->untrusted),
            ])
            ->all();
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
