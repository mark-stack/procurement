<?php

use App\Enums\NestingEnums;
use App\Formatters\NestingFormatter;
use App\Models\Batch;
use App\Models\BatchMeasurement;
use App\Models\Project;
use App\Models\Scrap;
use App\Services\BatchMeasurements;
use App\Services\MeasuresReport;
use App\Services\NestingCostModel;
use App\Services\NestingSettings;
use App\Services\ScrapLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

/**
 * Value a batch's drops again from scratch, the way the backfill does.
 *
 * The rows are deleted first because Services\ScrapLedger::recordNest leaves a batch that already
 * has scrap alone - which is the right behaviour and is in the way of asking "what would this nest
 * be valued at if it were read today".
 */
function revalueScrap(Batch $batch): float
{
    Scrap::query()->where('batch_id', $batch->id)->delete();

    (new ScrapLedger)->recordNest($batch->fresh());

    return (float) Scrap::query()->where('batch_id', $batch->id)->sum('value');
}

it('keeps the figures a nest was run on with the nest', function () {
    /*
     * The settings in force at the moment of nesting, written onto the batch the way
     * scrap_threshold_mm has always been written onto each bar. Every coefficient the cost model
     * reads has to be in there: one missing key falls back to a default on the way out, which is the
     * same thing as not having retained it.
     */
    [$business, , $batch] = nestedBatch([[7000, 2], [1700, 7]]);

    $retained = $batch->nesting_settings;

    expect($retained)->toBeArray()
        ->and(NestingSettings::wasRetained($batch))->toBeTrue();

    foreach (array_keys(NestingCostModel::DEFAULTS) as $coefficient) {
        expect($retained)->toHaveKey($coefficient);
    }

    /*
     * And the two that are not prices: what the blade takes, and how short is too short to keep.
     *
     * Compared numerically rather than identically, because json_encode writes an integral float as
     * "2000" and it comes back an int. Harmless - every reader casts - but toBe() would be asserting
     * about PHP's JSON encoder rather than about what was retained.
     */
    expect($retained['kerf_mm'])->toEqual((int) $business->kerf_mm)
        ->and($retained['scrap_threshold_mm'])->toEqual((int) $business->scrap_threshold_mm)
        ->and($retained['material_cost_per_tonne'])->toEqual((float) $business->material_cost_per_tonne)
        ->and($retained['labour_rate_per_hour'])->toEqual((float) $business->labour_rate_per_hour)
        //The one coefficient that is not a round number, so it proves the value survives rather than the default
        ->and($retained['scrap_recovery_rate'])->toEqual((float) $business->scrap_recovery_rate);
});

it('would be a disaster if raising the steel price restated what an old batch destroyed', function () {
    /*
     * The failure this whole change exists for.
     *
     * A batch nested in February destroyed a fixed number of kilograms of steel. What those
     * kilograms were WORTH was being resolved against whatever the business's material cost happened
     * to be at the moment somebody asked - so a yard that put its steel price up in March restated
     * February, and the quarter it had already reported on changed underneath it.
     */
    [$business, , $batch] = nestedBatch([[7000, 2], [1700, 7]]);

    $costedAt = (float) Scrap::query()->where('batch_id', $batch->id)->sum('value');
    expect($costedAt)->toBeGreaterThan(0);

    //The merchant doubles its price. Nothing about February's steel has changed
    $business->material_cost_per_tonne = 4000.00;
    $business->save();

    expect(revalueScrap($batch))->toEqualWithDelta($costedAt, 0.01);

    /*
     * And the same batch with its snapshot taken away - which is what every batch nested before
     * today looks like. It is valued at the new price, because there is nothing else to value it at:
     * this is the behaviour being left behind, pinned here so the difference is visible rather than
     * asserted about.
     */
    $batch->nesting_settings = null;
    $batch->save();

    expect(revalueScrap($batch))->toEqualWithDelta($costedAt * 2, 0.02);
});

it('judges an old nest by the threshold it was nested under, not by today\'s', function () {
    /*
     * A yard that raises its scrap threshold from 1,000mm to 2,000mm has not retrospectively thrown
     * away every 1,500mm remnant it banked - those pieces are on the rack. The per-bar stamp has
     * always said what each bar was judged against; the snapshot is what answers the same question
     * for a batch whose bars predate that stamping.
     */
    [$business, , $batch] = nestedBatch([[2500, 5], [1500, 2]]);

    //This nest banked its 2,500mm drop, so there is nothing in the skip
    expect(Scrap::query()->where('batch_id', $batch->id)->count())->toBe(0);

    $business->scrap_threshold_mm = 4000;
    $business->save();

    //Strip the per-bar stamps, leaving the batch-wide snapshot as the only thing that knows
    $nested = $batch->nested_state;
    foreach ($nested[NestingEnums::METERAGE->value] as $product) {
        $state = $product->nested;

        foreach (array_keys($state['utilisedBars']) as $index) {
            unset($state['utilisedBars'][$index]['result']['scrap_threshold_mm']);
        }

        $product->nested = $state;
    }
    $batch->nested_state = $nested;
    $batch->save();

    revalueScrap($batch);

    expect(Scrap::query()->where('batch_id', $batch->id)->count())->toBe(0);
});

it('falls back to the business figures for a batch nested before any were retained', function () {
    [$business, , $batch] = nestedBatch([[7000, 2], [1700, 7]]);

    $batch->nesting_settings = null;
    $batch->save();

    expect(NestingSettings::wasRetained($batch->fresh()))->toBeFalse()
        //The live business itself, not a copy of it - there is nothing else to answer with
        ->and(NestingSettings::asOf($batch->fresh(), $business)->is($business))->toBeTrue();
});

it('reports the cost a batch was costed at, not what it would cost today', function () {
    /*
     * The figure the search actually picked this plan on - material plus the labour of making it.
     * It is read back out of the saved nest rather than struck again, so the labour rate going up
     * does not make last month's job look like it cost more to do.
     */
    [$business, , $batch] = nestedBatch([[7000, 2], [1700, 7]]);

    $costedAt = $batch->nestCost();

    expect($costedAt)->toBeFloat()->toBeGreaterThan(0);

    $measurement = $batch->measurement;

    expect($measurement->cost)->toEqualWithDelta($costedAt, 0.01)
        //And it says the rates behind it were kept, which is what makes it a record rather than a valuation
        ->and($measurement->cost_from_retained_settings)->toBeTrue();

    $business->labour_rate_per_hour = 500.00;
    $business->material_cost_per_tonne = 9000.00;
    $business->save();

    expect($batch->fresh()->nestCost())->toEqualWithDelta($costedAt, 0.01)
        ->and($batch->fresh()->measurement->cost)->toEqualWithDelta($costedAt, 0.01);
});

it('writes down what a nest achieved at the moment it is saved', function () {
    [, , $batch] = nestedBatch([[7000, 2], [1700, 7]]);

    $measurement = $batch->measurement;

    //The same reading the nesting screens take off nested_state, not a second opinion on it
    $usage = (new NestingFormatter)->usageStats($batch->nested_state)[NestingEnums::METERAGE->value];

    expect($measurement)->not->toBeNull()
        ->and($measurement->purchased_mm)->toEqualWithDelta((float) $usage['totalPurchasedMaterial'], 0.1)
        ->and($measurement->used_mm)->toEqualWithDelta((float) $usage['totalUsedMaterial'], 0.1)
        ->and($measurement->consumed_mm)->toEqualWithDelta((float) $usage['totalConsumed'], 0.1)
        ->and($measurement->scrap_mm)->toEqualWithDelta((float) $usage['totalScrap'], 0.1)
        ->and($measurement->reusable_mm)->toEqualWithDelta((float) $usage['totalReusable'], 0.1)
        ->and($measurement->efficiency)->toEqualWithDelta((float) $usage['efficiency'], 0.05)
        ->and($measurement->effective_efficiency)->toEqualWithDelta((float) $usage['effectiveEfficiency'], 0.05)
        //The month it belongs to is the month it was nested, not the month the row was written
        ->and($measurement->nested_at->toDateTimeString())->toBe($batch->created_at->toDateTimeString())
        //Nothing has been delivered, so the delivery half is untouched rather than zeroed
        ->and($measurement->delivered_on)->toBeNull()
        ->and($measurement->days_late)->toBeNull();
});

it('records whether the steel arrived by the day it was wanted', function () {
    [$business, $user, $batch] = nestedBatch([[7000, 2], [1700, 7]]);

    /*
     * The job starts on the saw in five days, so its steel is wanted one working day before that.
     * The delivery lands today, which is comfortably inside it.
     */
    $project = Project::query()->firstWhere('user_id', $user->id);
    $project->date_fabrication_begins = now()->addWeekdays(5)->toDateString();
    $project->save();

    $batch->delivered_at = now();
    $batch->save();

    (new BatchMeasurements)->recordDelivery($batch, $business);

    $measurement = $batch->fresh()->measurement;

    expect($measurement->delivered_on->toDateString())->toBe(now()->toDateString())
        ->and($measurement->required_on->toDateString())
        ->toBe(Project::materialsRequiredDate($project->date_fabrication_begins)->toDateString())
        ->and($measurement->days_late)->toBeLessThan(0)
        ->and($measurement->onTime())->toBeTrue();
});

it('counts a delivery that missed the day as late, by the days it missed by', function () {
    [$business, $user, $batch] = nestedBatch([[7000, 2], [1700, 7]]);

    //Fabrication began a week ago, so the steel was wanted eight days ago and is only turning up now
    $project = Project::query()->firstWhere('user_id', $user->id);
    $project->date_fabrication_begins = now()->subDays(7)->toDateString();
    $project->save();

    $batch->delivered_at = now();
    $batch->save();

    (new BatchMeasurements)->recordDelivery($batch, $business);

    $measurement = $batch->fresh()->measurement;

    $wanted = Project::materialsRequiredDate($project->date_fabrication_begins);

    expect($measurement->days_late)->toBe((int) round($wanted->diffInDays(now()->startOfDay(), false)))
        ->and($measurement->days_late)->toBeGreaterThan(0)
        ->and($measurement->onTime())->toBeFalse();
});

it('would be a disaster if moving a fabrication date changed a delivery that had already happened', function () {
    /*
     * A fabrication date is shared, live state: every manager with a job on the batch may move
     * theirs, and the rules deliberately leave it movable until the steel is in. So a late delivery
     * measured by looking the date up afterwards turns punctual the moment somebody slips their job
     * - and nothing records that it ever did. The promise is copied onto the measurement at the
     * delivery for exactly this reason.
     */
    [$business, $user, $batch] = nestedBatch([[7000, 2], [1700, 7]]);

    $project = Project::query()->firstWhere('user_id', $user->id);
    $project->date_fabrication_begins = now()->subDays(7)->toDateString();
    $project->save();

    $batch->delivered_at = now();
    $batch->save();

    (new BatchMeasurements)->recordDelivery($batch, $business);

    $late = $batch->fresh()->measurement->days_late;
    expect($late)->toBeGreaterThan(0);

    //The job slips a month. The steel still turned up a week after it was asked for
    $project->date_fabrication_begins = now()->addMonth()->toDateString();
    $project->save();

    expect($batch->fresh()->measurement->days_late)->toBe($late);
});

it('measures a delivery once, on the day the last of it landed', function () {
    [$business, $user, $batch] = nestedBatch([[7000, 2], [1700, 7]]);

    $project = Project::query()->firstWhere('user_id', $user->id);
    $project->date_fabrication_begins = now()->addWeekdays(10)->toDateString();
    $project->save();

    $batch->delivered_at = now();
    $batch->save();

    $measurements = new BatchMeasurements;
    $measurements->recordDelivery($batch, $business);

    $first = $batch->fresh()->measurement->delivered_on->toDateString();

    //A second press a fortnight later - a stale tab, a colleague marking the same merchant
    $this->travel(14)->days();
    $measurements->recordDelivery($batch->fresh(), $business);

    expect($batch->fresh()->measurement->delivered_on->toDateString())->toBe($first)
        ->and(BatchMeasurement::query()->where('batch_id', $batch->id)->count())->toBe(1);
});

it('leaves a batch nobody has delivered unmeasured', function () {
    [$business, , $batch] = nestedBatch([[7000, 2], [1700, 7]]);

    expect((new BatchMeasurements)->recordDelivery($batch, $business))->toBeNull()
        ->and($batch->fresh()->measurement->delivered_on)->toBeNull();
});

it('records the delivery when a goods receipt books in the last of the steel', function () {
    /*
     * The wiring, rather than the arithmetic. Three different presses can be the one that completes
     * a delivery and each of them asks the batch whether it is finished rather than assuming it; this
     * is the one that goes through a real order.
     */
    $user = createUser(1, createBusiness('biz'), false, true);
    $batch = Batch::factory()->forUser($user->id)->create(['done' => false]);

    [, $order] = quoteAndOrder($user, $batch, quoteSent: true, orderSent: true);

    $this->actingAs($user)
        ->post(route('order.mark.delivered', $order), [
            'docket_number' => 'DN-1',
            'quantity_verified' => true,
            'grade_verified' => true,
        ])
        ->assertRedirect();

    $measurement = $batch->fresh()->measurement;

    /*
     * A batch with no nest still gets its delivery recorded, and its yield columns stay out of the
     * yield series by having no nested_at - a measurement of a nest that never happened would drag
     * the month towards nothing.
     */
    expect($measurement)->not->toBeNull()
        ->and($measurement->delivered_on->toDateString())->toBe(now()->toDateString())
        ->and($measurement->nested_at)->toBeNull();
});

it('reports yield, scrap and on-time delivery by month without re-nesting anything', function () {
    [$business, $user, $batch] = nestedBatch([[7000, 2], [1700, 7]]);

    $project = Project::query()->firstWhere('user_id', $user->id);
    $project->date_fabrication_begins = now()->addWeekdays(5)->toDateString();
    $project->save();

    $batch->delivered_at = now();
    $batch->save();

    (new BatchMeasurements)->recordDelivery($batch, $business);

    $report = (new MeasuresReport)->forBusiness($business);

    $measurement = $batch->fresh()->measurement;

    expect($report['yield']['batches'])->toBe(1)
        ->and($report['yield']['efficiency'])->toEqualWithDelta($measurement->efficiency, 0.1)
        ->and($report['yield']['used_mm'])->toEqualWithDelta($measurement->used_mm, 0.1)
        //The scrap column is the scrap report's own figure rather than a second count of the steel
        ->and($report['scrap']['pieces'])->toBe(3)
        ->and($report['scrap']['weight_kg'])->toBeGreaterThan(0)
        ->and($report['delivery']['on_time'])->toBe(1)
        ->and($report['delivery']['late'])->toBe(0)
        ->and($report['delivery']['on_time_rate'])->toBe(100.0)
        //Every batch here retained what it was costed on
        ->and($report['provenance']['costed'])->toBe(1)
        ->and($report['provenance']['costed_on_retained_settings'])->toBe(1);

    //A row per month in the window, including the months nothing happened in
    expect($report['by_month'])->toHaveCount(MeasuresReport::DEFAULT_MONTHS)
        ->and(end($report['by_month'])['month'])->toBe(now()->format('Y-m'))
        ->and(end($report['by_month'])['batches'])->toBe(1)
        ->and(end($report['by_month'])['on_time'])->toBe(1)
        ->and(end($report['by_month'])['scrap_weight_kg'])->toBe($report['scrap']['weight_kg'])
        //An empty month reads as nothing measured rather than as nought per cent
        ->and($report['by_month'][0]['batches'])->toBe(0)
        ->and($report['by_month'][0]['efficiency'])->toBeNull()
        ->and($report['by_month'][0]['on_time_rate'])->toBeNull();
});

it('weighs a month by the steel in it rather than averaging the batches', function () {
    /*
     * Twelve bars' worth of work and a two-cut job in the same month. Averaging the two percentages
     * would give the handrail the same say as the structure, which is how a good month reads as a
     * bad one because somebody nested an offcut on the 3rd.
     */
    [$business, , $big] = nestedBatch([[7000, 2], [1700, 7]]);

    $small = Batch::factory()->forUser($big->user_id)->create(['done' => false]);

    //Hand-built so the two have deliberately different sizes and yields - see BatchMeasurements
    BatchMeasurement::create([
        'batch_id' => $small->id,
        'nested_at' => now(),
        'purchased_mm' => 1000,
        'offcut_mm' => 0,
        'consumed_mm' => 1000,
        'used_mm' => 100,
        'reusable_mm' => 0,
        'kerf_mm' => 0,
        'scrap_mm' => 900,
        'efficiency' => 10.0,
        'effective_efficiency' => 10.0,
    ]);

    $report = (new MeasuresReport)->forBusiness($business);

    $measured = $big->fresh()->measurement;

    $weighted = round(
        ($measured->used_mm + 100) / ($measured->consumed_mm + 1000) * 100,
        1,
    );

    expect($report['yield']['batches'])->toBe(2)
        ->and($report['yield']['efficiency'])->toBe($weighted)
        //And not the mean of the two readings, which is the thing being avoided
        ->and($report['yield']['efficiency'])->not->toEqualWithDelta(($measured->efficiency + 10.0) / 2, 0.05);
});

it('counts a delivery with no date promised as neither on time nor late', function () {
    /*
     * A job created before fabrication dates were asked for promised nothing, so its delivery has
     * nothing to have met. Counting those as successes is how an on-time figure reaches 100% on a
     * yard that has never once been measured against a date.
     */
    [$business, $user, $batch] = nestedBatch([[7000, 2], [1700, 7]]);

    //A project from before the fabrication date was asked for - it names no day at all
    $project = Project::query()->firstWhere('user_id', $user->id);
    $project->date_fabrication_begins = null;
    $project->save();

    $batch->delivered_at = now();
    $batch->save();

    (new BatchMeasurements)->recordDelivery($batch, $business);

    $report = (new MeasuresReport)->forBusiness($business);

    expect($batch->fresh()->measurement->days_late)->toBeNull()
        ->and($report['delivery']['deliveries'])->toBe(1)
        ->and($report['delivery']['unpromised'])->toBe(1)
        ->and($report['delivery']['on_time'])->toBe(0)
        ->and($report['delivery']['on_time_rate'])->toBeNull();
});

it('would be a disaster if one business could read another business\'s measurements', function () {
    /*
     * This table carries no business_id either. Everything is reached through the batch, which is
     * also what keeps a test-mode nest out of the live trend - see BatchMeasurement::scopeOfBusiness.
     */
    [$mine, , ] = nestedBatch([[7000, 2], [1700, 7]], 'mine');

    $theirs = createBusiness('theirs');

    expect(BatchMeasurement::query()->count())->toBe(1)
        ->and((new MeasuresReport)->forBusiness($mine)['yield']['batches'])->toBe(1)
        ->and((new MeasuresReport)->forBusiness($theirs)['yield']['batches'])->toBe(0);
});

it('carries the three series onto the measures page', function () {
    [, $user, ] = nestedBatch([[7000, 2], [1700, 7]]);

    $this->actingAs($user)
        ->get(route('measures.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('MeasuresIndex')
            ->where('months', MeasuresReport::DEFAULT_MONTHS)
            ->where('report.yield.batches', 1)
            ->where('report.scrap.pieces', 3)
            ->has('report.delivery')
            ->has('report.by_month', MeasuresReport::DEFAULT_MONTHS)
        );

    //And a shorter window when one is asked for, anything else falling back rather than reaching the report
    $this->actingAs($user)
        ->get(route('measures.index', ['months' => 3]))
        ->assertInertia(fn (Assert $page) => $page->where('months', 3)->has('report.by_month', 3));

    $this->actingAs($user)
        ->get(route('measures.index', ['months' => 9999]))
        ->assertInertia(fn (Assert $page) => $page->where('months', MeasuresReport::DEFAULT_MONTHS));
});

it('shows a closed batch what it was costed at, and says when that figure is only a valuation', function () {
    [, $user, $batch] = nestedBatch([[7000, 2], [1700, 7]]);

    $costedAt = $batch->nestCost();

    $batch->done = true;
    $batch->save();

    /*
     * Against the stored figure rather than against nestCost() rounded, because how many decimal
     * places survive is the database's business - mysql holds the column to the cent and the sqlite
     * the tests run on keeps whatever it was given. What matters here is that the card shows the
     * figure the nest was picked on and not a fresh costing of it.
     */
    $stored = $batch->fresh()->measurement->cost;

    expect($stored)->toEqualWithDelta($costedAt, 0.01);

    $this->actingAs($user)
        ->get(route('past.batches.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('PastBatchesIndex')
            ->where('pastBatches.0.cost', $stored)
            ->where('pastBatches.0.costRetained', true)
        );

    //A batch from before the rates were kept still shows a figure, and the card says which kind it is
    $batch->nesting_settings = null;
    $batch->save();
    $batch->measurement->update(['cost_from_retained_settings' => false]);

    $this->actingAs($user)
        ->get(route('past.batches.index'))
        ->assertInertia(fn (Assert $page) => $page->where('pastBatches.0.costRetained', false));
});

it('backfills the yield of a batch nested before measurements were recorded', function () {
    /*
     * The figures are not being invented: a nest has always carried its own totals, and the backfill
     * hands the old batch to the same method the live path uses. What it cannot recover is the
     * delivery - the day the steel was wanted is editable, so working it out now would measure an old
     * delivery against a date that may have been set after it landed.
     */
    [$business, , $batch] = nestedBatch([[7000, 2], [1700, 7]]);

    $expected = $batch->measurement->used_mm;

    BatchMeasurement::query()->where('batch_id', $batch->id)->delete();

    $this->artisan('measures:backfill')
        ->expectsOutputToContain('Measurements written: 1')
        ->assertSuccessful();

    $measurement = $batch->fresh()->measurement;

    expect($measurement->used_mm)->toEqualWithDelta($expected, 0.1)
        //In the month the batch was nested, not the month somebody ran the command
        ->and($measurement->nested_at->toDateTimeString())->toBe($batch->created_at->toDateTimeString())
        //And no delivery invented for it
        ->and($measurement->delivered_on)->toBeNull();

    //Safe to run twice
    $this->artisan('measures:backfill')->assertSuccessful();

    expect(BatchMeasurement::query()->where('batch_id', $batch->id)->count())->toBe(1)
        ->and((new MeasuresReport)->forBusiness($business)['yield']['batches'])->toBe(1);
});

it('takes the measurement with the batch when the batch is unwound', function () {
    /*
     * A re-nest unwinds the batch first, and a measurement of a nest that no longer exists is not a
     * historical figure - it is a wrong one that would go on being counted in its month.
     */
    [, $user, $batch] = nestedBatch([[7000, 2], [1700, 7]]);

    expect(BatchMeasurement::query()->count())->toBe(1);

    $this->actingAs($user)->delete(route('batches.destroy', $batch->id));

    expect(Batch::query()->whereKey($batch->id)->exists())->toBeFalse()
        ->and(BatchMeasurement::query()->count())->toBe(0);
});
