<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Support\Collection;

class Project extends Model
{
    /** @use HasFactory<\Database\Factories\ProjectFactory> */
    use HasFactory;

    protected $guarded = [];

    //Relationships
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    //Optional
    public function quotes(): HasMany
    {
        return $this->hasMany(Quote::class);
    }

    //Optional
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function pieces(): HasMany
    {
        return $this->hasMany(Piece::class);
    }

    public function rawMaterialQuotes(): HasMany
    {
        return $this->hasMany(RawMaterialQuote::class);
    }

    //Collections
    public function suppliers(): Collection
    {
        $suppliers = [];

        foreach($this->orders as $order){
            $suppliers[] = $order->supplier;
        }

        return collect($suppliers);
    }

    public function orderedProducts(): Collection
    {
        $products = [];

        foreach($this->orders as $order){
            foreach($order->products as $product){
                $products[] = $product;
            }
        }

        return collect($products);
    }

    //Local scopes
    public function scopeActive(Builder $query): void
    {
        $query->where('archive',false);
    }
    public function scopeAwarded(Builder $query): void
    {
        $query->where('tendering_stage',false);
    }
}
