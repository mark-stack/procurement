<?php

namespace App\Models;

use App\Formatters\UniqueLetterIDGenerator;
use App\Services\ProductService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;

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

    public function offcutFrom(): BelongsTo
    {
        return $this->belongsTo(Offcut::class, 'offcut_from_id');
    }

    public function sourceBatch(): BelongsTo
    {
        return $this->belongsTo(Batch::class, 'batch_from_id');
    }

    //Accessors
    public function getProductDerivedLabelAttribute(): string
    {
        /*
         * Derived from the offcut's OWN product spec rather than from $this->bar, because bar_id is
         * nullable - offcuts cut from another offcut have no bar, and neither do offcuts created outside
         * CreateBarsAndOffcuts. Reading the label off a missing bar used to fatal.
         */
        return (new ProductService)->getDerivedProductLabel([
            'product_category' => $this->product_category,
            'material' => $this->material,
            'grade' => $this->grade,
            'surface' => $this->surface,
            'nominal_length' => $this->nominal_length,
            'precise_length' => $this->precise_length,
            'nominal_width' => $this->nominal_width,
            'precise_width' => $this->precise_width,
            'nominal_height' => $this->nominal_height,
            'precise_height' => $this->precise_height,
            'wall' => $this->wall,
        ]);
    }

    //Mutators
    public function setUniqueMarkAttribute(?string $value): void
    {
        /*
         * A mark is written on steel by hand and typed back into the offcuts search, so it is always
         * plain upper-case letters. $guarded is empty on this model, so without this guard any path
         * that writes an offcut - including a request body - could put anything at all in the column.
         */
        $mark = strtoupper(trim((string) $value));

        if (preg_match('/^[A-Z]{'.UniqueLetterIDGenerator::MIN_LENGTH.',32}$/', $mark) !== 1) {
            throw new InvalidArgumentException('Not a valid offcut mark: "'.$mark.'"');
        }

        $this->attributes['unique_mark'] = $mark;
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
        /*
         * Goes through the relation rather than a fresh findOrFail, so callers that read it several times
         * (and collections that eager-load sourceBatch) hit one query instead of one per call.
         */
        $batchFrom = $this->sourceBatch;

        if (! $batchFrom) {
            throw (new ModelNotFoundException)->setModel(Batch::class, [$this->batch_from_id]);
        }

        return $batchFrom;
    }

    public function batchTo(): Batch|null
    {
        return Batch::find($this->batch_to_id);
    }

    public function pieceTo(): Piece|null
    {
        return Piece::find($this->piece_to_id);
    }

    //Order
    //deliveredOrder() lived here and answered "does this offcut's batch have a delivered order from the
    //supplier category that stocks it?" one offcut at a time. Its only caller was
    //Business::availableOffcuts, which now asks the same question in SQL for the whole set at once.
}
