<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Bar extends Model
{
    protected $guarded = [];

    //Relationships
    public function offcuts(): HasMany
    {
        return $this->hasMany(Offcut::class);
    }
}
