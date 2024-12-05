<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Piece extends Model
{
    //Relationships
    public function projects(): BelongsToMany
    {
        return $this->belongsToMany(Project::class);
    }

    //Collections
    public function products(): Collection
    {
        return Product::query()
            ->where("product",$this->product)
            ->where("material",$this->material)
            ->where("grade",$this->grade)
            ->where("surface",$this->surface)
            ->where("measurement_unit",$this->measurement_unit)
            ->where("size",$this->size)
            ->get();
    }
}
