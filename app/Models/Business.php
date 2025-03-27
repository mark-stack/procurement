<?php

namespace App\Models;

use App\Enums\SupplierGroupEnums;
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
         * No filter by supplier group. e.g "steel merchant". Filter by algo instead like "meterage"
         */
        return true;

//        //Upgraded has all supplier groups
//        if ($this->upgraded) {
//            $supplierGroupIsCurrentPlan = true;
//        }
//        //Lite plan is 'steel merchant' only
//        else {
//            if ($supplierGroup === SupplierGroupEnums::STEEL_MERCHANT->value) {
//                $supplierGroupIsCurrentPlan = true;
//            }
//        }
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
        $businessBatchesIds = [];
        foreach($this->users as $user){
            foreach($user->batches as $batch){
                $businessBatchesIds[] = $batch->id;
            }
        }

        return Offcut::query()
            ->whereIn("batch_from_id",$businessBatchesIds)
            ->where("batch_to_id",null);
    }
}
