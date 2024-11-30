<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Quote extends Model
{
    /** @use HasFactory<\Database\Factories\QuoteFactory> */
    use HasFactory;

    protected $guarded = [];

    //Relationships
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class);
    }

    //Optional
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    //Optional
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }
}
