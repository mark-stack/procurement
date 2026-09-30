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
    /**
     * Is anything still pointing at this supplier?
     *
     * The only caller is the admin branch of SupplierController::destroy, where a false answer
     * deletes the row - so this has to ask the question the foreign keys ask, and it asked neither
     * half of it. It read "attached to a non-admin business AND attached to quotes", joined by &&,
     * which is the wrong join for a question about whether anything would break: a supplier on three
     * businesses' lists with no quotes yet answered "unused" and was deleted out from under all
     * three. And the first half compared a user's email address to config('env.admin_business'),
     * which is set nowhere - not in .env.example, not in phpunit.xml - so it compared against null,
     * matched no rows, and made the whole method return false for every supplier that has ever
     * existed. Every admin delete went through to the DELETE.
     *
     * What stopped it was the database: orders.supplier_id, quotes.supplier_id and product_supplier
     * all restrict, so deleting a supplier with an order against it answered with a 500 instead. A
     * supplier with none of those was deleted for real, having first been detached from every
     * business holding it.
     *
     * Orders are asked about here for that reason - they were not before, and they are the reference
     * that matters most: Batch::newStockOrdersWithCertificates and the offcut ancestry both read
     * $order->supplier->name for the certificate trail behind steel already cut and installed.
     *
     * $exceptBusiness keeps what the first condition was reaching for - an admin can still delete a
     * supplier that only their own business holds - without depending on a config nobody sets.
     */
    public function isUsed(?Business $exceptBusiness = null): bool
    {
        $heldByAnotherBusiness = $this->businesses()
            ->when(
                $exceptBusiness !== null,
                fn ($query) => $query->whereKeyNot($exceptBusiness->getKey()),
            )
            ->exists();

        return $heldByAnotherBusiness
            || $this->quotes()->exists()
            || $this->orders()->exists()
            || $this->products()->exists();
    }
}
