<?php

namespace App\Models;

use App\Formatters\SupplierFormatter;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Builder;

class Offcut extends Model
{
    /** @use HasFactory<\Database\Factories\OffcutFactory> */
    use HasFactory;

    protected $guarded = [];

    //Relationships
    public function bar(): BelongsTo
    {
        return $this->belongsTo(Bar::class);
    }

    //Local scopes
    public function scopeMatchProduct(Builder $query, object $productSpec): void
    {
        $query->where('product_category',$productSpec->product_category)
              ->where('material',$productSpec->material ?? null)
              ->where('grade',$productSpec->grade ?? null)
              ->where('surface',$productSpec->surface ?? null)
              ->where('nominal_length',$productSpec->nominal_length ?? null)
              ->where('precise_length',$productSpec->precise_length ?? null)
              ->where('nominal_width',$productSpec->nominal_width ?? null)
              ->where('precise_width',$productSpec->precise_width ?? null)
              ->where('nominal_height',$productSpec->nominal_height ?? null)
              ->where('precise_height',$productSpec->precise_height ?? null)
              ->where('wall',$productSpec->wall ?? null);
    }

    //Batch
    public function batchFrom(): Batch
    {
        return Batch::findOrFail($this->batch_from_id);
    }

    public function batchTo(): Batch|null
    {
        return Batch::find($this->batch_to_id);
    }

    public function pieceTo(): Batch
    {
        return Piece::findOrFail($this->piece_to_id);
    }

    //Order
    public function deliveredOrder(): Order|null
    {
        /**
         * Order of the original batch
         */
        $batchFrom = $this->batchFrom();

        //Get list of ALL supplier categories with contained products. e.g "steel merchant" contains "PFC, UB, etc"
        $business = $batchFrom->user->business;
        $categories = (new SupplierFormatter)->supplierGroups($business);

        //Find supplier categories of this offcut. e,g "STEEL_MERCHANT"
        $supplierCategoryFromOffcut = null;
        foreach($categories as $supplierCategory => $includedProducts){
            foreach($includedProducts as $includedProduct){
                if($includedProduct === $this->product_category){
                    $supplierCategoryFromOffcut = $supplierCategory;
                }
            }
        }

        //Get delivered order that matches this supplier category
        return $batchFrom->orders()
            ->where("is_delivered",true)
            ->whereRelation("quote","supplier_category","=",$supplierCategoryFromOffcut)
            ->first();
    }
}
