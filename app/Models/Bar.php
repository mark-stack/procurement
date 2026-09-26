<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Bar extends Model
{
    protected $guarded = [];

    //Relationships
    public function offcuts(): HasMany
    {
        return $this->hasMany(Offcut::class);
    }

    //Nullable - bars cut before batch_id existed could only be traced through the offcuts they produced
    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class);
    }
}
