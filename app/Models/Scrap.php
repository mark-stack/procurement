<?php

namespace App\Models;

use App\Enums\ScrapSourceEnums;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * One piece of steel that was destroyed: how long it was, what it weighed, and what it cost.
 *
 * Written by Services\ScrapLedger and by nothing else, from the two events that destroy material -
 * a nest leaving a drop too short to bank, and the quarterly cleanout weighing in dead stock. See
 * App\Enums\ScrapSourceEnums for why those are kept apart.
 *
 * The money on a row is resolved when the scrap happens and never again. See the migration: the
 * mass per metre behind it comes from the product catalogue, and a catalogue row whose spec columns
 * are edited stops matching the steel that was cut to it, so a figure re-derived on read would
 * silently restate a quarter that has already been reported.
 *
 * @property float $length The drop, in millimetres
 * @property float $weight_kg What that length of this section weighed
 * @property float $value What owning it had cost - landed, freight included
 * @property float $recovered_value What the weighbridge pays back for the metal alone
 * @property float $kg_per_m The mass per metre the three figures above were worked out from
 * @property bool $kg_per_m_estimated Whether that mass was the business default rather than the catalogue's
 * @property ScrapSourceEnums $source
 * @property Carbon $scrapped_at When the steel was destroyed - not when the row was written
 * @property int $batch_id
 * @property int|null $bar_id
 * @property int|null $offcut_id
 */
class Scrap extends Model
{
    /*
     * No factory, deliberately. Nothing should be able to conjure a scrap row that did not come from
     * a nest or a cleanout, test code included - a fixture built by hand would not have been through
     * the valuation in Services\ScrapLedger, so a test written against it would be checking numbers
     * it had itself made up.
     */
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'source' => ScrapSourceEnums::class,
            'scrapped_at' => 'datetime',
            'length' => 'float',
            'weight_kg' => 'float',
            'value' => 'float',
            'recovered_value' => 'float',
            'kg_per_m' => 'float',
            'kg_per_m_estimated' => 'boolean',
        ];
    }

    /**
     * Steel comes off a bar or off the rack, never both.
     *
     * Enforced here rather than as a check constraint for the reason App\Models\Cut gives: the rule
     * then reads the same under mysql and under the sqlite the tests run on, and the message names
     * what was wrong with the row. $guarded is empty on this model, as on every model here, so
     * without this any path that writes scrap could claim a drop came off two pieces of steel at once
     * and have it counted twice by anything that reports per bar.
     *
     * Cut insists on exactly one; this one allows neither, which is the single difference between the
     * two rules and is not an oversight. A drop reconstructed from a batch nested before bars carried
     * a batch_id (the 2026_09_26 migration) has no bar row anybody can point at - see
     * Services\ScrapLedger::recordNest. The millimetres, the weight and the money are all still true
     * of that steel, and a report that left them out to keep a tidier foreign key would under-state
     * every quarter before that date. Where the bar is unknown the row says so by naming nothing.
     */
    protected static function booted(): void
    {
        static::saving(function (self $scrap): void {
            if ($scrap->bar_id !== null && $scrap->offcut_id !== null) {
                throw new InvalidArgumentException(
                    'Scrap comes off a bar or off an offcut, and this row names both.',
                );
            }
        });
    }

    //Relationships
    /**
     * The batch this steel belonged to - the nest that cut it, or for a cleanout the batch that
     * produced the offcut being weighed in.
     *
     * @return BelongsTo<Batch, $this>
     */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class);
    }

    /**
     * @return BelongsTo<Bar, $this>
     */
    public function bar(): BelongsTo
    {
        return $this->belongsTo(Bar::class);
    }

    /**
     * @return BelongsTo<Offcut, $this>
     */
    public function offcut(): BelongsTo
    {
        return $this->belongsTo(Offcut::class);
    }

    /**
     * Whoever weighed it in. Null on a nest drop, which nobody decided.
     *
     * @return BelongsTo<User, $this>
     */
    public function scrappedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'scrapped_by_user_id');
    }

    //Local scopes
    /**
     * Everything this business has destroyed.
     *
     * Reached through its batches rather than through a business_id of its own, which is what carries
     * the sandbox filter: batches are scoped by App\Models\Concerns\BelongsToSandbox, so a test-mode
     * nest's scrap stays out of the live report without this table needing a flag. See the migration.
     *
     * @param  Builder<Scrap>  $query
     */
    public function scopeOfBusiness(Builder $query, Business $business): void
    {
        $query->whereIn('batch_id', $business->batches()->select('batches.id'));
    }

    /**
     * @param  Builder<Scrap>  $query
     */
    public function scopeScrappedBetween(Builder $query, Carbon $from, Carbon $to): void
    {
        $query->whereBetween('scrapped_at', [$from, $to]);
    }

    //Money
    /**
     * What this piece of steel actually cost to destroy: what it was worth, less what the bin paid.
     *
     * Derived rather than stored, so it cannot disagree with the two figures it is made of.
     */
    public function netLoss(): float
    {
        return $this->value - $this->recovered_value;
    }
}
