<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
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

    /**
     * The import used to write the spreadsheet's literal "TRUE"/"FALSE" text into
     * certificates, which every consumer then read as a boolean. Casting here keeps the
     * column and the code that queries it talking about the same type.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'certificates' => 'boolean',
            'deprecated' => 'boolean',
            'wall' => 'float',
            'kg_per_m' => 'float',
        ];
    }

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
        $query->where('deprecated', false);
    }

    public function scopeAvailableFor(Builder $query, User $user): void
    {
        /**
         * 1) Not deprecated
         * 2) Not someone else's (yours or platform's)
         */
        $query->availableForBusiness($user->business);
    }

    public function scopeAvailableForBusiness(Builder $query, Business $business): void
    {
        /**
         * Same question as availableFor(), asked with the business rather than one of its users.
         *
         * Nesting works from a Business (it nests across every user's projects), and used to reach for
         * products with active() alone - so the purchasable stock lengths a nest was built from could
         * come from another business's private products.
         */
        $query->where('deprecated', false)
            ->where(function ($q) use ($business) {
                $q->whereNull('business_id')
                    ->orWhere('business_id', $business->id);
            });
    }

    //Collections
    public function pieces(): Collection
    {
        return Piece::query()
            ->where('product_category', $this->product_category)
            ->where('material', $this->material)
            ->where('grade', $this->grade)
            ->where('surface', $this->surface)
            ->where('nominal_units', $this->nominal_units)
            ->where('nominal_length', $this->nominal_length)
            ->where('nominal_width', $this->nominal_width)
            ->where('nominal_height', $this->nominal_height)
            ->get();
    }

    public function usersOrderedThisProduct(): Collection
    {
        $usersOrderedThisProduct = [];

        foreach ($this->orders as $order) {
            $usersOrderedThisProduct[] = $order->user;
        }

        return collect($usersOrderedThisProduct);
    }

    public function projectsOrderedThisProduct(): Collection
    {
        $projectsOrderedThisProduct = [];

        foreach ($this->orders as $order) {
            $projectsOrderedThisProduct[] = $order->project;
        }

        return collect($projectsOrderedThisProduct);
    }
}
