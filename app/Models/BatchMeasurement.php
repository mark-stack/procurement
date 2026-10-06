<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * What one batch measured: the yield its nest achieved, and whether its steel arrived in time.
 *
 * Written by Services\BatchMeasurements and by nothing else, at the two moments there is something
 * to measure - when the nest is saved, and when the delivery lands. Both halves are readings taken
 * at the time rather than answers worked out on the way out, which is the entire reason the table
 * exists: a yield re-derived from nested_state is re-derived through today's cost model, and an
 * on-time delivery re-derived from the projects is measured against a fabrication date any manager
 * on the batch may have moved since.
 *
 * Nothing here is ever updated except by the delivery half filling in the three columns the nesting
 * half left null. A measurement that can be revised is not a measurement.
 *
 * @property Carbon|null $nested_at When the nest this measures was saved
 * @property float $purchased_mm New stock bought
 * @property float $offcut_mm Steel taken off the rack
 * @property float $consumed_mm What the nest is charged for - see NestingFormatter::materialConsumed
 * @property float $used_mm What left as a finished part
 * @property float $reusable_mm What went back on the rack
 * @property float $kerf_mm What the blade turned into swarf
 * @property float $scrap_mm What was destroyed
 * @property float $efficiency The share of consumed material that became parts
 * @property float $effective_efficiency The share that was not destroyed
 * @property float|null $cost What the nest was costed at, in dollars
 * @property bool $cost_from_retained_settings Whether that cost was struck on the settings the nest actually ran on
 * @property Carbon|null $required_on The day the steel was wanted, as that day stood when it arrived
 * @property Carbon|null $delivered_on The day it arrived
 * @property int|null $days_late Calendar days between the two - negative is early, null is nothing to measure against
 */
class BatchMeasurement extends Model
{
    /*
     * No factory, for the reason App\Models\Scrap has none: a row conjured by hand has not been
     * through the reading in Services\BatchMeasurements, so a test written against one would be
     * checking figures it had itself made up.
     */
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'nested_at' => 'datetime',
            'required_on' => 'date',
            'delivered_on' => 'date',
            'purchased_mm' => 'float',
            'offcut_mm' => 'float',
            'consumed_mm' => 'float',
            'used_mm' => 'float',
            'reusable_mm' => 'float',
            'kerf_mm' => 'float',
            'scrap_mm' => 'float',
            'efficiency' => 'float',
            'effective_efficiency' => 'float',
            'cost' => 'float',
            'cost_from_retained_settings' => 'boolean',
            'days_late' => 'integer',
        ];
    }

    //Relationships
    /**
     * @return BelongsTo<Batch, $this>
     */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class);
    }

    //Local scopes
    /**
     * Everything this business has measured.
     *
     * Through its batches rather than a business_id of its own, exactly as Scrap::scopeOfBusiness
     * does and for the same two reasons: there is always a batch to reach through, and batches carry
     * the sandbox filter - so a nest run in somebody's test mode stays out of the real trend without
     * this table knowing a sandbox exists.
     *
     * @param  Builder<BatchMeasurement>  $query
     */
    public function scopeOfBusiness(Builder $query, Business $business): void
    {
        $query->whereIn('batch_id', $business->batches()->select('batches.id'));
    }

    /**
     * @param  Builder<BatchMeasurement>  $query
     */
    public function scopeNestedBetween(Builder $query, Carbon $from, Carbon $to): void
    {
        $query->whereBetween('nested_at', [$from, $to]);
    }

    /**
     * @param  Builder<BatchMeasurement>  $query
     */
    public function scopeDeliveredBetween(Builder $query, Carbon $from, Carbon $to): void
    {
        $query->whereBetween('delivered_on', [$from->toDateString(), $to->toDateString()]);
    }

    /**
     * Whether this batch's steel was in the yard by the day it was wanted.
     *
     * Null rather than false where nothing was promised - a job that names no fabrication date has
     * no day for a delivery to have met, and counting those as failures is how an on-time figure
     * stops meaning anything. The report counts them separately and says how many there were.
     */
    public function onTime(): ?bool
    {
        return $this->days_late === null ? null : $this->days_late <= 0;
    }
}
