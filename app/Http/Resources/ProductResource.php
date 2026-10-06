<?php

namespace App\Http\Resources;

use App\Enums\MaterialEnums;
use App\Services\CatalogueTrust;
use App\Services\ProductService;
use App\Services\SupplierGroupCosts;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One row of the admin catalogue screen.
 *
 * @property \App\Models\Product $resource
 */
class ProductResource extends JsonResource
{
    /**
     * Usage for every product in the response, keyed by product id.
     *
     * It cannot be read off the model: pieces, bars and offcuts carry no product_id, so answering
     * "what uses this?" per row would be three queries per row. ProductUsage answers it for a whole
     * page in three queries per category, which leaves nowhere on the model to hang the result.
     *
     * Set once by the controller, for one response. collection() builds each resource itself, so
     * there is no constructor to pass it to without giving up the paginator's meta and links.
     *
     * @var array<int, array<string, mixed>>
     */
    public static array $usage = [];

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $usage = static::$usage[$this->id] ?? null;
        $trust = new CatalogueTrust;

        return [
            'id' => $this->id,
            'description' => $this->description,

            /*
             * What the rest of the app calls this product. Not a column - the implementation builds
             * it from the spec, so it is the one label that cannot drift from the row it describes.
             */
            'label' => (new ProductService)->getDerivedProductLabel($this->resource->toArray()),

            'product_category' => $this->product_category,
            'material' => $this->material,
            'grade' => $this->grade,
            'surface' => $this->surface,

            /*
             * The material as a person says it - "Steel", "Timber" - and whether it is steel at all.
             *
             * Worded here rather than in the page, because what the column holds is a join key
             * written the way a database wants it. Null for the one inherited row whose material is
             * not a value the enum knows: it is already flagged as an invalid value below, and
             * inventing a label for it would hide the thing the flag is for.
             */
            'material_label' => MaterialEnums::tryFrom((string) $this->material)?->label(),
            'is_steel' => MaterialEnums::tryFrom((string) $this->material)?->isSteel(),

            //Which merchant this is bought from, which is what decides the price per tonne it is
            //costed at - see Services\SupplierGroupCosts
            'supplier_group' => SupplierGroupCosts::forCategory($this->product_category),

            //Derived from the category, shown so the consequence of the chosen category is visible
            'nesting_algo' => $this->nesting_algo,
            'nominal_units' => $this->nominal_units,

            'certificates' => $this->certificates,
            'nominal_length' => $this->nominal_length,
            'precise_length' => $this->precise_length,
            'nominal_width' => $this->nominal_width,
            'precise_width' => $this->precise_width,
            'nominal_height' => $this->nominal_height,
            'precise_height' => $this->precise_height,
            'wall' => $this->wall,
            'pack_size_1' => $this->pack_size_1,
            'pack_size_2' => $this->pack_size_2,
            'pack_size_3' => $this->pack_size_3,
            'kg_per_m' => $this->kg_per_m,
            'baseline_supplier' => $this->baseline_supplier,
            'deprecated' => $this->deprecated,

            //Why this row is flagged and being kept anyway, when somebody has decided that
            'accepted_reason' => $this->accepted_reason,

            'usage' => $usage,

            /*
             * Why this row cannot be relied on, if it cannot. A deprecated product is never flagged
             * - no nest resolves a mass from one - so this is empty for every retired row.
             *
             * A new CatalogueTrust per row rather than one for the page, unlike $usage above: these
             * checks read only the row's own columns and ask the database nothing, so there is
             * nothing to share and nothing to amortise.
             */
            'trust' => $this->deprecated ? [] : $trust->reasonsFor($this->resource),

            /*
             * Which columns those are, when the reason is a value the catalogue should no longer
             * hold. Named rather than merely counted: an unflagged row looks correct, so nobody
             * would ever find the anchor rod whose nominal length was "`50" - a backtick typed for
             * a 1, which matched no piece spec, made it unpurchasable, and had been sitting in the
             * spreadsheet unnoticed.
             */
            'invalidValues' => $trust->invalidValues($this->resource),
        ];
    }
}
