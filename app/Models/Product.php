<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

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
