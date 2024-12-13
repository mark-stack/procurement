<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Template extends Model
{
    protected $guarded = [];

    //Relationships
    public function product(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }
}
