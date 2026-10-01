<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * One physical cut: this part, off that bar or that offcut.
 *
 * Written by App\Actions\Bar\CreateBarsAndOffcuts when a nest is saved, and deleted with the batch
 * that planned it. See the cuts migration for why this is a table rather than a column on pieces.
 *
 * @property float $length
 * @property int|null $bar_id
 * @property int|null $offcut_id
 */
class Cut extends Model
{
    /** @use HasFactory<\Database\Factories\CutFactory> */
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'length' => 'float',
        ];
    }

    /**
     * Steel comes off a new bar or off the rack - never both, never neither.
     *
     * Enforced here rather than as a check constraint so the rule reads the same under mysql and under
     * the sqlite the tests run on, and so the message says which row was wrong. $guarded is empty on
     * this model, as it is on every model here, so without this any path that writes a cut could write
     * one that belongs to nothing - and a traceability row that traces to nothing is worse than no row,
     * because it counts.
     */
    protected static function booted(): void
    {
        static::saving(function (self $cut): void {
            $sources = ($cut->bar_id !== null ? 1 : 0) + ($cut->offcut_id !== null ? 1 : 0);

            if ($sources !== 1) {
                throw new InvalidArgumentException(
                    'A cut comes off exactly one of a bar or an offcut, and this one names '
                    .($sources === 0 ? 'neither' : 'both').'.',
                );
            }
        });
    }

    //Relationships
    public function piece(): BelongsTo
    {
        return $this->belongsTo(Piece::class);
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class);
    }

    /**
     * Optional - null when this cut came off the rack.
     *
     * Annotated because callers do more with it than read columns: heatNumber() and originBar() below
     * both reach through it, and without the generic every one of those reads as a property access on a
     * plain Model.
     *
     * @return BelongsTo<Bar, $this>
     */
    public function bar(): BelongsTo
    {
        return $this->belongsTo(Bar::class);
    }

    /**
     * Optional - null when this cut came off new stock.
     *
     * Annotated for the same reason bar() is, and more so: originBar() calls ancestors() on it, which is
     * Offcut's own method rather than anything Model has.
     *
     * @return BelongsTo<Offcut, $this>
     */
    public function offcut(): BelongsTo
    {
        return $this->belongsTo(Offcut::class);
    }

    //Local scopes
    /**
     * @param  Builder<Cut>  $query
     */
    public function scopeFromNewStock(Builder $query): void
    {
        $query->whereNotNull('bar_id');
    }

    /**
     * @param  Builder<Cut>  $query
     */
    public function scopeFromOffcuts(Builder $query): void
    {
        $query->whereNotNull('offcut_id');
    }

    //Booleans
    public function isFromNewStock(): bool
    {
        return $this->bar_id !== null;
    }

    //Traceability
    /**
     * The heat this cut's steel was rolled from, or null where nobody has recorded it.
     *
     * Two routes to the same answer, because steel reaches a saw two ways. A cut off new stock reads
     * the heat straight off its bar. A cut off the rack reads it off the bar at the top of that offcut's
     * ancestry - an offcut of an offcut of an offcut was never bought, so the purchase is several
     * generations back up the offcut_from_id chain, and the bar is what the first generation came off.
     *
     * Null is a real answer and the honest one: heat numbers are typed in at the gate from a docket,
     * plenty of deliveries arrive without one, and for those the set-level trail on the batch
     * (Batch::offcutOrdersWithCertificates) remains the best available statement. What this adds is the
     * exact answer when the yard has recorded it.
     */
    public function heatNumber(): ?string
    {
        if ($this->bar !== null) {
            return $this->bar->heat_number;
        }

        return $this->originBar()?->heat_number;
    }

    /**
     * The bar this cut's steel was originally rolled as, however many offcuts ago.
     *
     * Walks the ancestry rather than one generation, for the reason
     * Batch::resolveOffcutOrdersWithCertificates spells out: reading only the immediate source found
     * nothing from the third generation on, and reported fully traceable steel as untraceable.
     */
    public function originBar(): ?Bar
    {
        if ($this->bar !== null) {
            return $this->bar;
        }

        $offcut = $this->offcut;

        if ($offcut === null) {
            return null;
        }

        if ($offcut->bar !== null) {
            return $offcut->bar;
        }

        /** @var Collection<int, Offcut> $ancestors */
        $ancestors = $offcut->ancestors();

        foreach ($ancestors as $ancestor) {
            if ($ancestor->bar !== null) {
                return $ancestor->bar;
            }
        }

        return null;
    }
}
