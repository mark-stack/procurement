<?php

namespace App\Models;

use App\Formatters\UniqueLetterIDGenerator;
use App\Services\ProductService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

class Offcut extends Model
{
    /** @use HasFactory<\Database\Factories\OffcutFactory> */
    use HasFactory;

    protected $guarded = [];

    /**
     * How far back up the offcut_from_id chain anything is ever walked.
     *
     * An offcut cut from an offcut is itself cuttable, so the chain has no fixed length. Each
     * generation is shorter than the one before it by at least a cut plus the saw kerf, and the chain
     * ends on its own when the drop falls under the business's scrap threshold and is scrapped instead
     * of banked - a 12m bar cut down in 1m steps runs out after about a dozen generations. That is the
     * real limit, and it is the right one: every generation is steel physically in the yard, so
     * refusing to reuse it after an arbitrary number of cuts would throw material away.
     *
     * This cap is not that limit - it is protection for the walk itself. $guarded is empty on this
     * model, so any path that writes an offcut can set offcut_from_id, and a row pointing at itself
     * (or a pair pointing at each other) would otherwise spin forever on a page render.
     */
    public const MAX_ANCESTRY_DEPTH = 50;

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

    //Ancestry
    /**
     * The offcuts this one was cut from, nearest first - its source offcut, then that offcut's source,
     * back to the one that came off a bar.
     *
     * Uses the chain loadAncestry() hung on the model when it was loaded as part of a set, and walks it
     * itself otherwise, so a single offcut read on its own still answers correctly.
     *
     * @return Collection<int, Offcut>
     */
    public function ancestors(): Collection
    {
        if (! $this->relationLoaded('ancestorOffcuts')) {
            /** @var Collection<int, Offcut> $justThisOne */
            $justThisOne = collect([$this]);

            //Hangs the chain on this very instance, so the walk only ever happens once
            self::loadAncestry($justThisOne);
        }

        return $this->getRelation('ancestorOffcuts');
    }

    /**
     * How many cuts back this offcut's steel is: 1 for one cut from a bar of new stock, 2 for an offcut
     * of that offcut, and so on.
     */
    public function generation(): int
    {
        return $this->ancestors()->count() + 1;
    }

    /**
     * Resolve the whole ancestry of a set of offcuts and hang each chain on its model as
     * "ancestorOffcuts", nearest ancestor first.
     *
     * One query per generation for the entire set rather than one per offcut per generation - a page of
     * third-generation offcuts would otherwise cost hundreds of reads. Ancestors are read unscoped:
     * they are consumed (batch_to_id is set on them), so none of them is in availableOffcuts() any more,
     * yet they are exactly where the certificate trail lives.
     *
     * @param  Collection<int, Offcut>  $offcuts
     * @return Collection<int, Offcut>
     */
    public static function loadAncestry(Collection $offcuts): Collection
    {
        //Offcut id => its ancestors so far, nearest first
        $chains = [];
        //Ancestor id => the ids of the offcuts whose chain is waiting on it
        $frontier = [];
        //Offcut id => the ancestor ids already in its chain, so a corrupt chain cannot be walked twice
        $placed = [];

        foreach ($offcuts as $offcut) {
            $chains[$offcut->id] = [];
            $placed[$offcut->id] = [$offcut->id => true];

            if ($offcut->offcut_from_id !== null) {
                $frontier[$offcut->offcut_from_id][] = $offcut->id;
            }
        }

        $depth = 0;
        while (count($frontier) > 0) {
            if ($depth >= self::MAX_ANCESTRY_DEPTH) {
                //Truncated rather than followed, so say so - a chain this long is a data problem, and
                //silently dropping the rest of it drops certificates with it
                Log::warning('Offcut ancestry hit the depth cap and was truncated', [
                    'depth' => $depth,
                    'offcut_ids' => array_values(array_unique(array_merge(...array_values($frontier)))),
                ]);

                break;
            }

            $depth++;

            $ancestors = self::query()->whereIn('id', array_keys($frontier))->get()->keyBy('id');

            $nextFrontier = [];
            foreach ($frontier as $ancestorId => $descendantIds) {
                //A chain that points at a row that is no longer there simply ends
                $ancestor = $ancestors->get($ancestorId);

                if (! $ancestor) {
                    continue;
                }

                foreach ($descendantIds as $descendantId) {
                    //Already in this chain: the ids form a loop, so stop instead of going round it
                    if (isset($placed[$descendantId][$ancestor->id])) {
                        continue;
                    }

                    $chains[$descendantId][] = $ancestor;
                    $placed[$descendantId][$ancestor->id] = true;

                    if ($ancestor->offcut_from_id !== null) {
                        $nextFrontier[$ancestor->offcut_from_id][] = $descendantId;
                    }
                }
            }

            $frontier = $nextFrontier;
        }

        foreach ($offcuts as $offcut) {
            $offcut->setRelation('ancestorOffcuts', collect($chains[$offcut->id]));
        }

        return $offcuts;
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
