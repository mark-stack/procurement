<?php

namespace App\Http\Resources;

use App\Enums\GradeEnums;
use App\Enums\MaterialEnums;
use App\Enums\ProductEnums;
use App\Enums\SurfaceEnums;
use App\Services\ProductRules;
use App\Services\ProductService;
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

            'usage' => $usage,

            /*
             * Values the catalogue holds that are no longer legal to save. Flagged rather than
             * hidden: an unflagged row looks correct, so nobody would ever find the anchor rod whose
             * nominal length is "`50" - a backtick typed for a 1, which matches no piece spec, makes
             * it unpurchasable, and had been sitting in the spreadsheet unnoticed.
             */
            'invalidValues' => $this->invalidValues(),
        ];
    }

    /**
     * Every column holding something the rules would now refuse.
     *
     * Kept in step with ProductRules on purpose: whatever a JSON import would reject, this screen
     * already says out loud, so a bad row is never only discoverable by trying to import one.
     *
     * @return array<int, string>
     */
    private function invalidValues(): array
    {
        $enums = [
            'product_category' => ProductEnums::class,
            'material' => MaterialEnums::class,
            'grade' => GradeEnums::class,
            'surface' => SurfaceEnums::class,
        ];

        $invalid = [];

        foreach ($enums as $column => $enum) {
            $value = (string) $this->{$column};

            //A blank is inherited, not invalid - the spreadsheet stored one for every unanswered cell
            if ($value === '') {
                continue;
            }

            if ($enum::tryFrom($value) === null) {
                $invalid[] = $column;
            }
        }

        foreach (ProductRules::MEASUREMENTS as $column) {
            $value = $this->{$column};

            if ($value === null || $value === '') {
                continue;
            }

            if (! is_numeric($value)) {
                $invalid[] = $column;
            }
        }

        return $invalid;
    }
}
