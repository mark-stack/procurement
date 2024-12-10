<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Piece extends Model
{
    protected $guarded = [];

    //Relationships
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    //Collections
    public function product(): ?Product
    {
        return Product::query()
            ->where("product",$this->product)
            ->where("material",$this->material)
            ->where("grade",$this->grade)
            ->where("surface",$this->surface)
            ->where("measurement_unit",$this->measurement_unit)
            ->where("size",$this->size)
            ->first();
    }
}
