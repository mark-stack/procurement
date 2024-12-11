<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Illuminate\Database\Eloquent\Builder;

class Product extends Model
{
    /** @use HasFactory<\Database\Factories\ProductFactory> */
    use HasFactory;

    protected $guarded = [];

    /**
     * Relationships
     */
    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function suppliers(): BelongsToMany
    {
        return $this->belongsToMany(Supplier::class);
    }

    public function quotes(): BelongsToMany
    {
        return $this->belongsToMany(Quote::class);
    }

    public function orders(): BelongsToMany
    {
        return $this->belongsToMany(Order::class);
    }

    public function keywords(): HasMany
    {
        return $this->hasMany(Keyword::class);
    }

    //Local scopes
    public function scopePlatformCreated(Builder $query): void
    {
        $query->whereNull('business_id');
    }
    public function scopeActive(Builder $query): void
    {
        $query->where('deprecated',false);
    }
    public function scopeAvailableFor(Builder $query,User $user): void
    {
        /**
         * 1) Not deprecated
         * 2) Not someone else's (yours or platform's)
         */
        $business = $user->business;
        $query->where('deprecated',false)
            ->where(function($q) use($business){
                $q->where('business_id',null)
                  ->orWhere('business_id',$business->id);
            });
    }

    //Collections
    public function pieces(): Collection
    {
        return Piece::query()
            ->where("product",$this->product)
            ->where("material",$this->material)
            ->where("grade",$this->grade)
            ->where("surface",$this->surface)
            ->where("measurement_unit",$this->measurement_unit)
            ->where("size",$this->size)
            ->get();
    }
    public function usersOrderedThisProduct(): Collection
    {
        $usersOrderedThisProduct = [];

        foreach($this->orders as $order){
            $usersOrderedThisProduct[] = $order->user;
        }

        return collect($usersOrderedThisProduct);
    }

    public function projectsOrderedThisProduct(): Collection
    {
        $projectsOrderedThisProduct = [];

        foreach($this->orders as $order){
            $projectsOrderedThisProduct[] = $order->project;
        }

        return collect($projectsOrderedThisProduct);
    }
}
