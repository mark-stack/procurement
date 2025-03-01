<?php

namespace App\Models;

use App\Services\ProductService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Piece extends Model
{
    /** @use HasFactory<\Database\Factories\PieceFactory> */
    use HasFactory;

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

    //Local scopes
    //...

    //Collections
    public function product(): ?Product
    {
        return Product::query()
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
