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

    /**Relationships
     */
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

    public function projects(): BelongsToMany
    {
        return $this->belongsToMany(Project::class);
    }

    public function keywords(): HasMany
    {
        return $this->hasMany(Keyword::class);
    }

    public function rawMaterialQuotes(): HasMany
    {
        return $this->hasMany(RawMaterialQuote::class);
    }

    //Local scopes
    public function scopePlatformCreated(Builder $query): void
    {
        $query->whereNull('domain');
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
        $yourDomain = $user->getDomainFromEmail();
        $query->where('deprecated',false)
              ->where(function($q) use($yourDomain){
                $q->where('domain',null)
                  ->orWhere('domain',$yourDomain);
              });
    }

    //Collections
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
