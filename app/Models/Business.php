<?php

namespace App\Models;

use App\Enums\SupplierGroupEnums;
use App\Formatters\SupplierFormatter;
use App\Services\ProductService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Support\Collection;

class Business extends Model
{
    protected $guarded = [];

    /*
     * These four are boolean columns in the migration, but without a cast the type that
     * reaches json is whatever the driver hands back - int on mysql, the string "0" on
     * sqlite. The admin users page compares admin_setup_complete with ===, so its Ready
     * column silently emptied out under the test connection while it worked in production.
     */
    protected function casts(): array
    {
        return [
            'admin_setup_complete' => 'boolean',
            'cap_12m_stock' => 'boolean',
            'meterage_only' => 'boolean',
            'allow_custom_products' => 'boolean',
        ];
    }

    //Relationships
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function suppliers(): BelongsToMany
    {
        return $this->belongsToMany(Supplier::class);
    }

    public function templates(): HasMany
    {
        return $this->hasMany(Template::class);
    }

    public function quotes(): HasManyThrough
    {
        return $this->hasManyThrough(Quote::class, User::class);
    }

    public function projects(): HasManyThrough
    {
        return $this->hasManyThrough(Project::class, User::class);
    }

    public function batches(): HasManyThrough
    {
        return $this->hasManyThrough(Batch::class, User::class);
    }

    //Local scopes


    //Boolean
    public function supplierGroupIsCurrentPlan($supplierGroup): bool
    {
        /**
         * Filter out supplier groups
         */

        $supplierGroupIsCurrentPlan = true;
        if ($supplierGroup === SupplierGroupEnums::PROFILE_CUTTING->value) {
            $supplierGroupIsCurrentPlan = false;
        }

        return $supplierGroupIsCurrentPlan;
    }

    //Collection
    public function projectsReadyForBatching(Collection $piecesReadyForBatching): Collection
    {
        $requiringClarification = $this->projectsRequiringClarification()->pluck("id")->toArray();

        return Project::query()
            ->has("rawMaterialQuotes")
            ->whereNotIn("id",$requiringClarification)
            ->whereIn("id",$piecesReadyForBatching->pluck("project_id")->toArray())
            ->get();
    }

    public function projectsRequiringClarification(): Collection
    {
        $projectsRequiringClarification = [];

        //Services
        $productService = new ProductService();

        foreach($this->projects as $project){
            $partialProductMatches = [];
            foreach ($project->rawMaterialQuotes as $rawMaterialQuote) {
                $getProductMatchOptions = $productService->getProductMatchOptions($this, $rawMaterialQuote);

                if ($getProductMatchOptions) {
                    /*
                     * Price book partial match (requires confirmation)
                     */
                    if ($getProductMatchOptions['status'] === 'PARTIAL') {
                        $partialProductMatches[] = [
                            'selected' => null,
                            'data' => $rawMaterialQuote,
                            'options' => $getProductMatchOptions['decodedOptions'],
                            'custom' => $getProductMatchOptions['custom'],
                        ];
                    }
                }
            }

            if (count($partialProductMatches) > 0) {
                $projectsRequiringClarification[] = $project;
            }
        }

        return collect($projectsRequiringClarification);
    }

    public function currentProjects(): Collection
    {
        /**
         * Projects without batch (pre-nesting) + Active batches (not 'done')
         */

        //Projects without batch yet
        $projectsWithoutBatchIds = $this->projects()
            ->where("archive",false)
            ->withoutBatch()
            ->get()
            ->pluck("id")
            ->toArray();

        //Projects in Active batches
        $activeBatches = $this->batches()
            ->active()
            ->get();
        $projectsInActiveBatchesIds = [];
        foreach($activeBatches as $activeBatch){
            foreach($activeBatch->projects() as $project){
                $projectsInActiveBatchesIds[] = $project->id;
            }
        }

        $combinedCurrentProjectIds = array_merge($projectsWithoutBatchIds,$projectsInActiveBatchesIds);
        $combinedCurrentProjectIds = array_unique($combinedCurrentProjectIds);

        return Project::query()
            ->whereIn("id",$combinedCurrentProjectIds)
            ->get();
    }

    public function pastProjects(): Collection
    {
        /**
         * Projects which have batch, and batch = "done"
         */
        $inactiveBatches = $this->batches()
            ->inactive()
            ->get();

        $pastProjects = [];
        foreach($inactiveBatches as $inactiveBatch){
            foreach($inactiveBatch->projects() as $project){
                $pastProjects[] = $project->id;
            }
        }

        return Project::query()
            ->whereIn("id",$pastProjects)
            ->get();
    }

    public function availableOffcuts(): Builder
    {
        /**
         * Offcuts from this business's batches that are still unassigned, and whose source batch has a
         * delivered order from the supplier category that stocks the offcut's product.
         *
         * Resolved in SQL. This used to hydrate every unassigned offcut and call Offcut::deliveredOrder()
         * on each one - a batch lookup, a full supplierGroups() rebuild and an orders query per offcut -
         * then throw the models away and re-query by id.
         *
         * Ordered, because nesting consumes this list and an unordered query made the nest depend on
         * whatever order the database happened to return rows in. The same pieces could be nested twice
         * (once for the suggestion, once by Actions/Batch/SaveNesting) and produce different cut plans.
         */

        //Supplier categories with the products they stock. e.g "STEEL_MERCHANT" contains "PFC, UB, etc"
        $categories = (new SupplierFormatter)->supplierGroups($this);

        if (count($categories) === 0) {
            return Offcut::query()->whereRaw('1 = 0');
        }

        return Offcut::query()
            ->whereIn('batch_from_id', $this->batches()->select('batches.id'))
            ->whereNull('batch_to_id')
            ->where(function (Builder $query) use ($categories) {
                foreach ($categories as $supplierCategory => $productCategories) {
                    $query->orWhere(function (Builder $query) use ($supplierCategory, $productCategories) {
                        $query->whereIn('product_category', $productCategories)
                            ->where(function (Builder $query) use ($supplierCategory) {
                                $query
                                    //Cut from new stock: it is only in the yard once that order lands
                                    ->whereExists(function ($query) use ($supplierCategory) {
                                        $query->selectRaw('1')
                                            ->from('orders')
                                            ->whereColumn('orders.batch_id', 'offcuts.batch_from_id')
                                            ->where('orders.is_delivered', true)
                                            ->whereExists(function ($query) use ($supplierCategory) {
                                                $query->selectRaw('1')
                                                    ->from('quotes')
                                                    ->whereColumn('quotes.id', 'orders.quote_id')
                                                    ->where('quotes.supplier_category', $supplierCategory);
                                            });
                                    })
                                    /*
                                     * Cut from an offcut that was already in the yard. The material was
                                     * delivered against the SOURCE offcut, not against this batch - and a
                                     * batch that nested entirely out of inventory places no order at all,
                                     * so requiring a delivered order here hid these offcuts forever.
                                     */
                                    ->orWhereNotNull('offcut_from_id');
                            });
                    });
                }
            })
            //Shortest first, then by id, so the list a nest is built from is always the same list
            ->orderBy('length')
            ->orderBy('id');
    }
}
