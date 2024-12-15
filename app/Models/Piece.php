<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Piece extends Model
{
    protected $guarded = [];

    //Relationships
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function rawMaterialQuote(): BelongsTo
    {
        return $this->belongsTo(RawMaterialQuote::class);
    }

    //Collections
    public function product(): ?Product
    {
        return Product::query()
            ->where("product",$this->product)
            ->where("material",$this->material)
            ->where("grade",$this->grade)
            ->where("surface",$this->surface)
            ->where("nominal_units",$this->measurement_unit)
            ->where("nominal_length",$this->nominal_length)
            ->where("nominal_width",$this->nominal_width)
            ->where("nominal_height",$this->nominal_height)
            ->first();
    }
}
