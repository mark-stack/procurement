<?php

namespace App\Models;

use App\Observers\ProjectObserver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;

#[ObservedBy([ProjectObserver::class])]
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

    //Integers
    public function percentageOfMaterialsQuoted(): int {
        /**
         * Based on raw material quote > piece > quote
         */
        $percentageOfMaterialsQuoted = 0;
        $materialListRows = $this->rawMaterialQuotes()->count();

        foreach($this->rawMaterialQuotes as $rawMaterialQuote){
            $piece = $rawMaterialQuote->piece;
            if($piece){
                dd($piece);
                $quote = $piece->quote;
                if($quote){
                    $percentageOfMaterialsQuoted++;
                }
            }
        }

        return $percentageOfMaterialsQuoted > 0
            ? ceil($percentageOfMaterialsQuoted/$materialListRows*100)
            : 0;
    }

    public function percentageOfMaterialsOrdered(): int {
        /**
         * Based on raw material quote > piece > order
         */
        $percentageOfMaterialsOrdered = 0;
        $materialListRows = $this->rawMaterialQuotes()->count();

        foreach($this->rawMaterialQuotes as $rawMaterialQuote){
            $piece = $rawMaterialQuote->piece;
            if($piece){
                $order = $piece->order;
                if($order){
                    $percentageOfMaterialsOrdered++;
                }
            }
        }

        return $percentageOfMaterialsOrdered > 0
            ? ceil($percentageOfMaterialsOrdered/$materialListRows*100)
            : 0;
    }

    //Local scopes
    public function scopeActive(Builder $query): void
    {
        $query->where('archive',false);
    }
    public function scopeAwarded(Builder $query): void
    {
        $query->where('awarded',true);
    }
}
