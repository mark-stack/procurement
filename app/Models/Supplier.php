<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Bus;

class Supplier extends Model
{
    /** @use HasFactory<\Database\Factories\SupplierFactory> */
    use HasFactory;

    protected $guarded = [];

    //Relationships
    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class);
    }

    public function businesses(): BelongsToMany
    {
        return $this->belongsToMany(Business::class);
    }

    public function quotes(): HasMany
    {
        return $this->hasMany(Quote::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    //Collections
    public function projectsUsingThisSupplier(): Collection
    {
        $projectsWithThisSupplier = [];

        foreach($this->orders as $order){
            if($order->project){
                $projectsWithThisSupplier[] = $order->project;
            }
        }

        return collect($projectsWithThisSupplier);
    }

    public function usersWhoOrderedFromThisSupplier(): Collection
    {
        $usersWhoOrderFromThisSupplier = [];

        foreach($this->orders as $order){
            $usersWhoOrderFromThisSupplier[] = $order->user;
        }

        return collect($usersWhoOrderFromThisSupplier);
    }

    //Boolean
    public function isUsed(): bool
    {
        /**
         * 1) Attached to non-admin business
         * 2) Attached to quotes
         */

        // 1) Attached to non-admin business
        $cond1 = $this->businesses()
            ->whereRelation("users","email","!=",env("ADMIN_BUSINESS"))
            ->count() > 0;

        // 2) Attached to quotes
        $cond2 = $this->quotes()->count() > 0;

        return $cond1 && $cond2;
    }
}
