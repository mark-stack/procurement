<?php

use App\Enums\NestingEnums;
use App\Enums\OffcutRemovalEnums;
use App\Enums\ScrapSourceEnums;
use App\Models\Bar;
use App\Models\Batch;
use App\Models\Offcut;
use App\Models\Scrap;
use App\Services\ScrapReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

/**
 * The per-bar scrap a saved nest claims, in millimetres.
 *
 * Read straight out of nested_state the way the nesting screens read it - see
 * NestingFormatter::summariseBars - so a test comparing this against the scraps table is comparing
 * the two accounts of one number rather than restating one of them.
 */
function scrapClaimedInNest(Batch $batch): float
{
    $claimed = 0.0;

    foreach ($batch->nested_state[NestingEnums::METERAGE->value] ?? [] as $product) {
        foreach ($product->nested['utilisedBars'] ?? [] as $utilisedBar) {
            $drop = (float) $utilisedBar['result']['unused'];

            if ($drop > 0 && $drop < (float) $utilisedBar['result']['scrap_threshold_mm']) {
                $claimed += $drop * (int) $utilisedBar['count'];
            }
        }
    }

    foreach ($batch->nested_state[NestingEnums::METERAGE->value] ?? [] as $product) {
        foreach ($product->nested['bestResultOffcuts']['utilisedOffcutBars'] ?? [] as $offcutBar) {
            $claimed += (float) ($offcutBar['scrap']['scrapLength'] ?? 0);
        }
    }

    return $claimed;
}

it('writes a scrap row for every drop a nest leaves too short to bank', function () {
    /*
     * Case 2 of nestingTestCases: two 7,000s and seven 1,700s. Two bars end with 300mm on them and
     * one with 500mm, all under the 1,000mm threshold, so three pieces of steel go in the skip and
     * none of them reaches the rack. Three rows, not one row of 1,100mm - ten bars each with 400mm
     * left is ten pieces of scrap, which is the same reasoning the nesting totals apply.
     */
    [, , $batch] = nestedBatch([[7000, 2], [1700, 7]]);

    $scrap = Scrap::query()->where('batch_id', $batch->id)->orderBy('length')->get();

    expect($scrap)->toHaveCount(3)
        ->and($scrap->pluck('length')->all())->toBe([300.0, 300.0, 500.0]);

    foreach ($scrap as $row) {
        expect($row->source)->toBe(ScrapSourceEnums::NEST_DROP)
            //The bar it came off, which is what makes it more than a number in a monthly total
            ->and($row->bar_id)->not->toBeNull()
            ->and($row->offcut_id)->toBeNull()
            //Nobody decided this - the cut plan did
            ->and($row->scrapped_by_user_id)->toBeNull()
            //A weight and a value, which is the whole point of the row
            ->and($row->weight_kg)->toBeGreaterThan(0)
            ->and($row->value)->toBeGreaterThan(0)
            //The bin pays for the metal, so destroying it costs less than it was worth but not nothing
            ->and($row->recovered_value)->toBeGreaterThan(0)
            ->and($row->netLoss())->toBeGreaterThan(0)
            ->and($row->netLoss())->toBeLessThan($row->value)
            //Priced off the real section rather than the business default
            ->and($row->kg_per_m_estimated)->toBeFalse()
            //Padded the way every derived label is - the section is spelled out, not rebuilt from columns
            ->and(trim($row->product_derived_label))->toBe('200PFC');
    }

    //Each drop is hung on its own bar, not all three on the first one
    expect($scrap->pluck('bar_id')->unique())->toHaveCount(3);

    //And every bar named is one this batch actually cut
    $barIds = Bar::query()->where('batch_id', $batch->id)->pluck('id');
    expect($scrap->pluck('bar_id')->diff($barIds))->toBeEmpty();
});

it('weighs a drop as the steel it actually is', function () {
    /*
     * 200PFC runs about 25.1 kg/m, so 300mm of it is roughly 7.5kg. The test is not the catalogue
     * figure - it is that the row was costed off the section rather than off default_kg_per_m, which
     * is 10 kg/m and would make the same drop 3kg.
     */
    [, , $batch] = nestedBatch([[7000, 2], [1700, 7]]);

    $row = Scrap::query()->where('batch_id', $batch->id)->orderBy('length')->first();

    expect($row->kg_per_m)->toBeGreaterThan(20.0)
        ->and($row->weight_kg)->toEqualWithDelta($row->kg_per_m * 0.3, 0.001)
        //Landed value against the business's own material cost, and the bin's share of the bare price
        ->and($row->value)->toEqualWithDelta($row->weight_kg * 2.0, 0.01)
        ->and($row->recovered_value)->toEqualWithDelta($row->value * 0.13, 0.01);
});

it('leaves a nest that banked everything with nothing in the scrap ledger', function () {
    /*
     * Case 1 of nestingTestCases fills one bar exactly and leaves 2,500mm on the other - well over
     * the threshold, so it goes on the rack. A yard that wasted nothing has to read as nothing, not
     * as a zero-length row.
     */
    [, , $batch] = nestedBatch([[2500, 5], [1500, 2]]);

    expect(Scrap::query()->where('batch_id', $batch->id)->count())->toBe(0)
        ->and(Offcut::query()->where('batch_from_id', $batch->id)->count())->toBe(1);
});

it('reconciles against the per-bar scrap of a batch nested before any of this existed', function () {
    /*
     * The question this table has to be able to answer about its own history.
     *
     * A batch nested before scrap was a record has the figures in its nested_state and nowhere else.
     * The backfill is stripped back to exactly that state here - the rows deleted and the bar ids
     * taken back out of the saved nest, which is what a batch from before CreateBarsAndOffcuts
     * stamped them looks like - and then the command is run over it.
     */
    [, , $batch] = nestedBatch([[7000, 2], [1700, 7]]);

    $claimed = scrapClaimedInNest($batch);
    expect($claimed)->toBe(1100.0);

    Scrap::query()->where('batch_id', $batch->id)->delete();

    $nested = $batch->nested_state;
    foreach ($nested[NestingEnums::METERAGE->value] as $product) {
        $state = $product->nested;

        foreach (array_keys($state['utilisedBars']) as $index) {
            unset($state['utilisedBars'][$index]['result']['bar_ids']);
        }

        $product->nested = $state;
    }
    $batch->nested_state = $nested;
    $batch->save();

    $this->artisan('scrap:backfill')->assertSuccessful();

    $rebuilt = Scrap::query()->where('batch_id', $batch->id)->get();

    //The millimetres agree with the nest, which is the reconciliation
    expect($rebuilt->sum('length'))->toBe($claimed)
        ->and($rebuilt)->toHaveCount(3);

    /*
     * And the bars are still named. Without the ids in the saved nest they are recovered by pairing
     * bars.id ascending against the order the nest created them in, which is only taken when the two
     * counts agree exactly - see Services\ScrapLedger.
     */
    $barIds = Bar::query()->where('batch_id', $batch->id)->pluck('id');
    expect($rebuilt->pluck('bar_id')->filter()->diff($barIds))->toBeEmpty()
        ->and($rebuilt->pluck('bar_id')->filter())->toHaveCount(3);

    //Run twice, counted once
    $this->artisan('scrap:backfill')->assertSuccessful();
    expect(Scrap::query()->where('batch_id', $batch->id)->count())->toBe(3);
});

it('would be a disaster if a backfill hung a drop on a bar that is not the one it came off', function () {
    /*
     * The pairing above is by order, so it is only safe while the two sequences are the same length.
     * Delete a bar and nothing can say which of the remaining ones carried which drop - and a wrong
     * bar is worse than no bar, because it reads as traceability. The weight still has to be
     * recorded, or every month before bars carried a batch_id would under-report.
     */
    [, , $batch] = nestedBatch([[7000, 2], [1700, 7]]);

    $claimed = scrapClaimedInNest($batch);

    Scrap::query()->where('batch_id', $batch->id)->delete();

    $nested = $batch->nested_state;
    foreach ($nested[NestingEnums::METERAGE->value] as $product) {
        $state = $product->nested;

        foreach (array_keys($state['utilisedBars']) as $index) {
            unset($state['utilisedBars'][$index]['result']['bar_ids']);
        }

        $product->nested = $state;
    }
    $batch->nested_state = $nested;
    $batch->save();

    Bar::query()->where('batch_id', $batch->id)->orderBy('id')->first()->delete();

    $this->artisan('scrap:backfill')->assertSuccessful();

    $rebuilt = Scrap::query()->where('batch_id', $batch->id)->get();

    expect($rebuilt)->toHaveCount(3)
        ->and($rebuilt->sum('length'))->toBe($claimed)
        ->and($rebuilt->pluck('bar_id')->filter())->toBeEmpty();
});

it('takes the scrap with the batch when the batch is unwound', function () {
    //Those cuts were never made, so neither was the drop they left
    [, $user, $batch] = nestedBatch([[7000, 2], [1700, 7]]);

    expect(Scrap::query()->where('batch_id', $batch->id)->count())->toBe(3);

    $this->actingAs($user)->delete(route('batches.destroy', $batch->id));

    expect(Batch::query()->whereKey($batch->id)->exists())->toBeFalse()
        ->and(Scrap::query()->where('batch_id', $batch->id)->count())->toBe(0);
});

it('writes a scrap row when somebody accepts the cleanout proposal', function () {
    //A 1.2m stub of 200PFC that has sat for two years - dead stock by both of the cleanout's tests
    $user = offcutsIndexUser();
    $business = $user->business;
    $this->actingAs($user);
    seedMasterMaterials();

    $batch = batchWithDeliveredOrder($user);
    $offcut = create_offcut_200PFC(1200, $batch->id);
    $offcut->created_at = now()->subYears(2);
    $offcut->save();

    $this->post(route('offcuts.scrap'), ['offcut_ids' => [$offcut->id], 'note' => 'Cleared the back rack'])
        ->assertRedirect();

    $scrap = Scrap::query()->where('offcut_id', $offcut->id)->first();

    expect($scrap)->not->toBeNull()
        ->and($scrap->source)->toBe(ScrapSourceEnums::CLEANOUT)
        //The offcut it was, the batch that cut it, and no bar - this row IS that piece of steel
        ->and($scrap->batch_id)->toBe($batch->id)
        ->and($scrap->bar_id)->toBeNull()
        ->and($scrap->length)->toBe(1200.0)
        ->and($scrap->weight_kg)->toBeGreaterThan(0)
        ->and($scrap->value)->toBeGreaterThan(0)
        //Who decided, and why, which is the half a nest drop never has
        ->and($scrap->scrapped_by_user_id)->toBe($user->id)
        ->and($scrap->note)->toBe('Cleared the back rack');

    //And the steel is out of inventory, which is the other half of the same event
    expect($offcut->fresh()->removed_reason)->toBe(OffcutRemovalEnums::SCRAPPED->value)
        ->and($business->availableOffcuts()->count())->toBe(0);
});

it('records what the cleanout page said the steel was worth, not a second opinion', function () {
    $user = offcutsIndexUser();
    $this->actingAs($user);
    seedMasterMaterials();

    $batch = batchWithDeliveredOrder($user);
    $offcut = create_offcut_200PFC(1200, $batch->id);
    $offcut->created_at = now()->subYears(2);
    $offcut->save();

    $candidate = (new App\Services\OffcutCleanout)->candidates($user->business)->firstWhere('offcut.id', $offcut->id);

    $this->post(route('offcuts.scrap'), ['offcut_ids' => [$offcut->id]]);

    $scrap = Scrap::query()->where('offcut_id', $offcut->id)->first();

    expect($scrap->value)->toBe($candidate['worth'])
        ->and($scrap->recovered_value)->toBe($candidate['bin_recovers'])
        ->and($scrap->kg_per_m)->toBe($candidate['kg_per_m'])
        /*
         * Including whether the catalogue could price the section at all. This fixture's spec matches
         * no product row, so the cleanout page costs it at the business default and says so - and the
         * scrap row has to carry that caveat with the money, or a guess reads back as a measurement.
         */
        ->and($candidate['kg_per_m_resolved'])->toBeFalse()
        ->and($scrap->kg_per_m_estimated)->toBeTrue();
});

it('would be a disaster if restoring an offcut left it counted as destroyed', function () {
    /*
     * Scrapping is reversible and the steel is not, so the row has to come back out with it -
     * otherwise the same 1.2m stub is in the scrap report and on the rack the next nest cuts from.
     */
    $user = offcutsIndexUser();
    $this->actingAs($user);
    seedMasterMaterials();

    $batch = batchWithDeliveredOrder($user);
    $offcut = create_offcut_200PFC(1200, $batch->id);
    $offcut->created_at = now()->subYears(2);
    $offcut->save();

    $this->post(route('offcuts.scrap'), ['offcut_ids' => [$offcut->id]]);
    expect(Scrap::query()->where('offcut_id', $offcut->id)->count())->toBe(1);

    $this->post(route('offcuts.restore', $offcut->id))->assertRedirect();

    expect(Scrap::query()->where('offcut_id', $offcut->id)->count())->toBe(0)
        ->and($user->business->availableOffcuts()->count())->toBe(1);
});

it('reports scrap by month, by job and by product category without nesting anything', function () {
    [$business, , $batch] = nestedBatch([[7000, 2], [1700, 7]]);

    $report = (new ScrapReport)->forBusiness($business);

    $claimed = scrapClaimedInNest($batch);

    //The headline is the nest's own figure, in millimetres, plus the weight and money it never had
    expect($report['totals']['pieces'])->toBe(3)
        ->and($report['totals']['length_mm'])->toBe($claimed)
        ->and($report['totals']['weight_kg'])->toBeGreaterThan(0)
        ->and($report['totals']['net_loss'])->toBeGreaterThan(0);

    //A row per month in the window, including the months with nothing in them
    expect($report['by_month'])->toHaveCount(ScrapReport::DEFAULT_MONTHS)
        ->and(end($report['by_month'])['month'])->toBe(now()->format('Y-m'))
        ->and(end($report['by_month'])['pieces'])->toBe(3)
        ->and($report['by_month'][0]['pieces'])->toBe(0);

    //One section on this job, and it is named
    expect($report['by_category'])->toHaveCount(1)
        ->and($report['by_category'][0]['product_category'])->toBe('PFC')
        ->and($report['by_category'][0]['weight_kg'])->toBe($report['totals']['weight_kg']);

    //One job, and all of it against that job - every bar was opened for it
    expect($report['by_project'])->toHaveCount(1)
        ->and($report['by_project'][0]['project_id'])->not->toBeNull()
        ->and($report['by_project'][0]['weight_kg'])->toBe($report['totals']['weight_kg']);

    //Split by why it was destroyed, which are two different problems
    $bySource = collect($report['by_source'])->keyBy('source');
    expect($bySource[ScrapSourceEnums::NEST_DROP->value]['pieces'])->toBe(3)
        ->and($bySource[ScrapSourceEnums::CLEANOUT->value]['pieces'])->toBe(0);
});

it('divides a drop between the jobs that shared the bar it came off', function () {
    /*
     * A bar opened for two jobs leaves one end, and neither job left it there on its own. The split
     * is by the steel each took off that bar, which is the only division that adds back up to the
     * drop - and the kilograms across the table have to equal the headline, or the report is two
     * different answers to the same question.
     */
    $dataClassificationService = new App\Services\DataClassificationService;

    $adminUser = createUser(1, createBusiness('admin'), true, true);
    $this->actingAs($adminUser);
    seedMasterMaterials();

    $business = createBusiness('fabricator');
    $user = createUser(1, $business, false, true);

    //Two jobs on one batch: 7,000mm for one and 1,700mm for the other fill a 9,000mm bar to 300mm
    $first = createProject($user);
    createPieces(sampleBOM($first, $dataClassificationService, [[7000, 1]]), $first, $dataClassificationService);

    $second = createProject($user);
    createPieces(sampleBOM($second, $dataClassificationService, [[1700, 1]]), $second, $dataClassificationService);

    $this->actingAs($user);
    $this->post(route('quotes.store'));

    $report = (new ScrapReport)->forBusiness($business);

    expect($report['totals']['pieces'])->toBe(1);

    $byProject = collect($report['by_project'])->keyBy('project_id');

    expect($byProject)->toHaveCount(2)
        //7,000 of the 8,700mm cut off that bar was the first job's, so it carries that share
        ->and($byProject[$first->id]['weight_kg'])
        ->toEqualWithDelta($report['totals']['weight_kg'] * 7000 / 8700, 0.2)
        ->and($byProject[$second->id]['weight_kg'])
        ->toEqualWithDelta($report['totals']['weight_kg'] * 1700 / 8700, 0.2);

    /*
     * The shares add back up - to within the single decimal place the figures are published at,
     * which is the only error rounding two shares of one drop can introduce. The piece counts
     * deliberately do not add up: one physical drop is one piece of steel in a skip, and it is
     * counted as a whole one against both jobs that left it there.
     */
    expect(collect($report['by_project'])->sum('weight_kg'))
        ->toEqualWithDelta($report['totals']['weight_kg'], 0.2)
        ->and($byProject[$first->id]['pieces'])->toBe(1)
        ->and($byProject[$second->id]['pieces'])->toBe(1);
});

it('puts the cleanout against no job at all', function () {
    /*
     * Dead stock outlived the job that produced it and was never cut for another one. Spreading it
     * over whichever jobs were running would blame a yield figure on work that had nothing to do
     * with it.
     */
    $user = offcutsIndexUser();
    $this->actingAs($user);
    seedMasterMaterials();

    $batch = batchWithDeliveredOrder($user);
    $offcut = create_offcut_200PFC(1200, $batch->id);
    $offcut->created_at = now()->subYears(2);
    $offcut->save();

    $this->post(route('offcuts.scrap'), ['offcut_ids' => [$offcut->id]]);

    $report = (new ScrapReport)->forBusiness($user->business);

    expect($report['by_project'])->toHaveCount(1)
        ->and($report['by_project'][0]['project_id'])->toBeNull()
        ->and($report['by_project'][0]['project'])->toBe('Not attributable to a job')
        ->and($report['by_project'][0]['weight_kg'])->toBe($report['totals']['weight_kg']);
});

it('would be a disaster if one business could read another business\'s scrap', function () {
    /*
     * The table carries no business_id. Everything is reached through the batch, which is what also
     * keeps a test-mode nest out of the live figures - see the migration and Scrap::scopeOfBusiness.
     * If that join were ever loosened, this is what would start answering with the whole platform's
     * write-offs.
     */
    [$mine, , ] = nestedBatch([[7000, 2], [1700, 7]], 'mine');

    $theirs = createBusiness('theirs');

    expect(Scrap::query()->count())->toBe(3)
        ->and((new ScrapReport)->forBusiness($mine)['totals']['pieces'])->toBe(3)
        ->and((new ScrapReport)->forBusiness($theirs)['totals']['pieces'])->toBe(0);
});

it('carries the report onto the scrap page', function () {
    [, $user, ] = nestedBatch([[7000, 2], [1700, 7]]);

    $this->actingAs($user)
        ->get(route('scrap.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('ScrapIndex')
            ->where('months', ScrapReport::DEFAULT_MONTHS)
            ->where('report.totals.pieces', 3)
            ->has('report.by_month', ScrapReport::DEFAULT_MONTHS)
            ->has('report.by_category', 1)
            ->has('report.by_project', 1)
        );
});

it('answers a shorter window when one is asked for', function () {
    [, $user, ] = nestedBatch([[7000, 2], [1700, 7]]);

    $this->actingAs($user)
        ->get(route('scrap.index', ['months' => 3]))
        ->assertInertia(fn (Assert $page) => $page->where('months', 3)->has('report.by_month', 3));

    //Anything that is not one of the offered windows falls back rather than reaching the report
    $this->actingAs($user)
        ->get(route('scrap.index', ['months' => 9999]))
        ->assertInertia(fn (Assert $page) => $page->where('months', ScrapReport::DEFAULT_MONTHS));
});
