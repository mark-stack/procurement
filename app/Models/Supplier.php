<?php

namespace App\Models;

use Database\Factories\SupplierFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

class Supplier extends Model
{
    /** @use HasFactory<SupplierFactory> */
    use HasFactory;

    protected $guarded = [];

    /**
     * The category flags on this supplier, as a label => bool map.
     *
     * supplier_categories is a PHP-serialized array in a string column, and five places read it:
     * this screen, the admin one, the resource, the formatter and a seeder. Every one of them called
     * a bare unserialize() on it, which will build objects out of whatever the string describes.
     *
     * Nothing writes an object there - AttachSupplierToBusiness and SupplierController both
     * serialize() a validated array of booleans, so the column cannot hold a crafted payload today.
     * The reason to route them all through here anyway is that "today" is doing the work in that
     * sentence: allowed_classes false means the column is data whatever is in it, and one decoder
     * means the next reader of it inherits that rather than copying the old line.
     *
     * A malformed or empty value reads as no categories rather than throwing: the supplier list
     * should not be a 500 because one row was written by an older version of this app.
     *
     * @return array<string, bool>
     */
    public function categories(): array
    {
        $stored = $this->supplier_categories;

        if (! is_string($stored) || $stored === '') {
            return [];
        }

        $decoded = @unserialize($stored, ['allowed_classes' => false]);

        return is_array($decoded) ? $decoded : [];
    }

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

        foreach ($this->orders as $order) {
            if ($order->project) {
                $projectsWithThisSupplier[] = $order->project;
            }
        }

        return collect($projectsWithThisSupplier);
    }

    public function usersWhoOrderedFromThisSupplier(): Collection
    {
        $usersWhoOrderFromThisSupplier = [];

        foreach ($this->orders as $order) {
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
            ->whereRelation('users', 'email', '!=', config('env.admin_business'))
            ->count() > 0;

        // 2) Attached to quotes
        $cond2 = $this->quotes()->count() > 0;

        return $cond1 && $cond2;
    }
}
