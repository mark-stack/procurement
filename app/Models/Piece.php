<?php

namespace App\Models;

use App\Models\Concerns\RecordsChanges;
use App\Services\ProductService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Piece extends Model
{
    /** @use HasFactory<\Database\Factories\PieceFactory> */
    use HasFactory, RecordsChanges;

    protected $guarded = [];

    //Relationships
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** @return BelongsToMany<Quote, $this> */
    public function quotes(): BelongsToMany
    {
        return $this->belongsToMany(Quote::class);
    }

    public function rawMaterialQuote(): BelongsTo
    {
        return $this->belongsTo(RawMaterialQuote::class);
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class);
    }

    /**
     * The individual cuts this piece became - one per physical part.
     *
     * A piece row is a line of demand with a quantity, not a part: actual_qty of 3 is three cuts, and
     * the nest places each one separately, so they can land on three different bars or on a bar and
     * an offcut. This is where that is recorded, and it is what makes the question "which heat is
     * this part" answerable. See App\Models\Cut.
     *
     * @return HasMany<Cut, $this>
     */
    public function cuts(): HasMany
    {
        return $this->hasMany(Cut::class);
    }

    //Local scopes
    //...

    //Collections
    public function product(): ?Product
    {
        return Product::query()
            ->active()
            ->where('product_category', $this->product_category)
            ->where('material', $this->material)
            ->where('grade', $this->grade)
            ->where('surface', $this->surface)
            ->where('nominal_units', $this->nominal_units)
            ->where('nominal_length', $this->nominal_length)
            ->where('nominal_width', $this->nominal_width)
            ->where('nominal_height', $this->nominal_height)
            ->first();
    }

    //String
    public function supplierGroup(): string
    {
        $implementation = (new ProductService)->getImplementationFromProductCategory($this->product_category);
        $config = $implementation->config();
        $supplierGroupEnum = $config['supplierGroup'];

        return $supplierGroupEnum->value;
    }
}
