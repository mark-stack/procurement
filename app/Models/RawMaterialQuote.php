<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RawMaterialQuote extends Model
{
    protected $guarded = [];

    /**
     * Relationships
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

//    public function product(): BelongsTo
//    {
//        return $this->belongsTo(Product::class);
//    }
}
