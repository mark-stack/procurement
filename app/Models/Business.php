<?php

namespace App\Models;

use App\Enums\SupplierGroupEnums;
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
    public function projectsReadyForBatching(Collection $piecesReadyForBatching): Collection
    {
        return Project::query()
            ->whereIn("id",$piecesReadyForBatching->pluck("project_id")->toArray())
            ->get();
    }

    //Boolean
    public function supplierGroupIsCurrentPlan($supplierGroup): bool
    {
        $supplierGroupIsCurrentPlan = false;

        //Upgraded has all supplier groups
        if ($this->upgraded) {
            $supplierGroupIsCurrentPlan = true;
        }
        //Lite plan is 'steel merchant' only
        else {
            if ($supplierGroup === SupplierGroupEnums::STEEL_MERCHANT->value) {
                $supplierGroupIsCurrentPlan = true;
            }
        }

        return $supplierGroupIsCurrentPlan;
    }
}
