<?php

use App\Enums\NestingEnums;
use App\Models\Bar;
use App\Models\Batch;
use App\Models\Offcut;
use App\Models\Supplier;
use App\Services\DataClassificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

/**
 * Every cut a nest actually calls for, as one flat list.
 *
 * Three places hold cuts and all three are real steel: bars of new stock, offcuts taken out of
 * inventory, and the pieces no stock length could hold. Identical bars are drawn as a single row
 * with a count and each of them is cut for real, so a "3 off" bar calls for its cuts three times.
 *
 * @return array<int, array{projectId: int, length: int, letter: string, from: string}>
 */
function everyCutInNest(array $nested): array
{
    $cuts = [];

    foreach ($nested['utilisedBars'] as $bar) {
        for ($i = 1; $i <= $bar['count']; $i++) {
            foreach ($bar['result']['pieces'] as $cut) {
                $cuts[] = [
                    'projectId' => (int) $cut['projectId'],
                    'length' => (int) $cut['cutLength'],
                    'letter' => $cut['letter'],
                    'from' => 'NEW_STOCK',
                ];
            }
        }
    }

    foreach ($nested['bestResultOffcuts']['utilisedOffcutBars'] as $offcutBar) {
        foreach ($offcutBar['sourceOffcut']['cuts'] as $cut) {
            $cuts[] = [
                'projectId' => (int) $cut['projectId'],
                'length' => (int) $cut['length'],
                'letter' => $cut['letter'],
                'from' => 'OFFCUT',
            ];
        }
    }

    foreach ($nested['tooLong'] as $cut) {
        $cuts[] = [
            'projectId' => (int) $cut['project'],
            'length' => (int) ceil((float) $cut['length']),
            'letter' => $cut['letter'],
            'from' => 'TOO_LONG',
        ];
    }

    return $cuts;
}

/**
 * The cuts as "<project id> x <length>" => qty, so what a nest calls for can be compared with what
 * the projects asked for - and one nest with another - however either of them happens to order them.
 *
 * @param  array<int, array{projectId: int, length: int}>  $cuts
 * @return array<string, int>
 */
function cutTally(array $cuts): array
{
    $tally = [];

    foreach ($cuts as $cut) {
        $key = $cut['projectId'].' x '.$cut['length'];
        $tally[$key] = ($tally[$key] ?? 0) + 1;
    }

    ksort($tally);

    return $tally;
}

/** The checks the nesting screen itself runs, that did not pass. */
function failedNestingChecks(array $checks): array
{
    return collect($checks)
        ->reject(fn (array $check) => $check['result'] === true)
        ->keys()
        ->all();
}

it('would be a disaster if a batch spanning several projects cut the wrong steel for the wrong job', function () {
    /**
     * The whole journey, start to end, in one 200PFC product so the arithmetic is checkable by hand.
     *
     * Round 1: three projects are nested together into one batch. Two of them share a bar, the third
     *   leaves a 4,000mm drop that is banked as an offcut once the steel is delivered.
     * Round 2: two more projects nest against that inventory. One cuts its piece out of the banked
     *   offcut, leaving an offcut of an offcut; the other buys new steel.
     *
     * What this proves, none of which holds on its own:
     *  - every cut is credited to the project whose piece it is, and stamped with that project's letter
     *  - the cuts a nest calls for are exactly the pieces the projects asked for - none lost, none
     *    duplicated, and none quietly moved from one job to another
     *  - projects genuinely nest together: one bar carries cuts for two different jobs
     *  - the nest saved against the batch is the nest the user approved on screen
     *  - an offcut is physical steel: it is spent once, by one batch, and what is left of it is banked
     *    with its provenance intact - and another business's yard is never touched
     *  - the steel balances end to end: everything bought is either a finished cut or still on the shelf
     *
     * 200PFC comes in 9m and 12m (the 13.5m and 15m lengths are over the business's 12m cap), the
     * scrap threshold is 1,000mm and the saw kerf is 0, which is what every length below turns on.
     */
    $dataClassificationService = new DataClassificationService;

    /*
     * Stage 1: an admin imports the master materials, which is where the purchasable stock lengths
     * every nest is built from come from.
     */
    $adminBusiness = createBusiness('admin', true);
    $adminUser = createUser(1, $adminBusiness, true, true);
    $this->actingAs($adminUser);
    seedMasterMaterials();

    /*
     * A rival yard, with a 9,000mm 200PFC offcut delivered and available to them. It would fit any
     * piece in this journey perfectly, and nothing here may ever touch it.
     */
    $rivalBusiness = createBusiness('rival', true);
    $rivalUser = createUser(2, $rivalBusiness, false, true);
    $rivalBatch = Batch::factory()->forUser($rivalUser->id)->create();
    [, $rivalOrder] = quoteAndOrder($rivalUser, $rivalBatch, orderSent: true);
    $rivalOrder->update(['is_delivered' => true, 'material_cert_numbers' => 'RIVAL-CERT']);
    $rivalOffcut = create_offcut_200PFC(9000, $rivalBatch->id);

    expect($rivalBusiness->availableOffcuts()->pluck('id')->all())->toBe([$rivalOffcut->id]);

    /*
     * Stage 2: our yard, and three projects whose pieces have to be nested together.
     *
     *   Project A: 1 of 4,500      Project B: 1 of 4,500      Project C: 1 of 5,000
     *
     * 14,000mm of steel needs two bars, and the cheapest two bars are 9,000 + 9,000. Nothing else
     * fits: 5,000 + 4,500 is 9,500, so the only packing is A and B paired on one bar, C alone on
     * the other with 4,000mm left over.
     */
    $business = createBusiness('biz', true);
    $user = createUser(3, $business, false, true);
    $this->actingAs($user);

    $projectA = createProject($user);
    $projectB = createProject($user);
    $projectC = createProject($user);

    createPieces(sampleBOM($projectA, $dataClassificationService, [[4500, 1]]), $projectA, $dataClassificationService);
    createPieces(sampleBOM($projectB, $dataClassificationService, [[4500, 1]]), $projectB, $dataClassificationService);
    createPieces(sampleBOM($projectC, $dataClassificationService, [[5000, 1]]), $projectC, $dataClassificationService);

    $required = [
        $projectA->id.' x 4500' => 1,
        $projectB->id.' x 4500' => 1,
        $projectC->id.' x 5000' => 1,
    ];
    ksort($required);

    //What the user is shown before batching
    $suggested = $this->get(route('suggested.nesting'));
    $suggested->assertStatus(200);
    $suggestedProps = $suggested->viewData('page')['props'];

    //All three projects are on the nest, each with a letter of its own
    $letters = $suggestedProps['lettersProjectArray'];

    expect(collect($suggestedProps['projectsReadyForBatching']['data'])->pluck('id')->sort()->values()->all())
        ->toBe(collect([$projectA->id, $projectB->id, $projectC->id])->sort()->values()->all())
        ->and(array_keys($letters))->toEqualCanonicalizing([$projectA->id, $projectB->id, $projectC->id])
        ->and(array_unique(array_values($letters)))->toHaveCount(3);

    //One product: 200PFC
    expect($suggestedProps['pieces'][NestingEnums::METERAGE->value])->toHaveCount(1);

    $suggestedNest = $suggestedProps['pieces'][NestingEnums::METERAGE->value][0]->nested;
    $suggestedCuts = everyCutInNest($suggestedNest);

    /*
     * The cuts are exactly the pieces the three projects asked for, and every one of them carries
     * the letter its own project was given.
     */
    expect(cutTally($suggestedCuts))->toBe($required);

    foreach ($suggestedCuts as $cut) {
        expect($cut['letter'])->toBe($letters[$cut['projectId']])
            ->and($cut['from'])->toBe('NEW_STOCK');
    }

    /*
     * Projects nest TOGETHER: one bar carries cuts for two different jobs. Nesting each project on
     * its own would buy three bars here and pass every per-project assertion above.
     */
    $barsSpanningProjects = 0;
    foreach ($suggestedNest['utilisedBars'] as $bar) {
        $projectsOnBar = array_unique(array_column($bar['result']['pieces'], 'projectId'));

        if (count($projectsOnBar) > 1) {
            $barsSpanningProjects++;
        }
    }

    expect($barsSpanningProjects)->toBe(1);

    //Two 9m bars, 14,000mm of it cut, 4,000mm banked and nothing destroyed
    expect($suggestedProps['usage'][NestingEnums::METERAGE->value])
        ->toMatchArray([
            'totalPurchasedMaterial' => 18000,
            'totalOffcutMaterial' => 0,
            'totalUsedMaterial' => 14000,
            'totalReusable' => 4000,
            'totalKerf' => 0,
            'totalScrap' => 0,
        ]);

    //Our yard is empty, so nothing was nested out of inventory - the rival's 9,000mm is not ours
    expect($suggestedNest['bestResultOffcuts']['utilisedOffcutBars'])->toBe([])
        ->and($business->availableOffcuts()->count())->toBe(0);

    //And the nesting screen's own audit of the plan passes
    expect(failedNestingChecks($suggestedProps['checks']))->toBe([]);

    /*
     * Stage 3: start quoting. The nest is re-run and saved against the batch, and it has to be the
     * same nest - the cut list the user approved is what the yard works from.
     */
    $this->post(route('quotes.store'));

    expect($business->batches()->count())->toBe(1);

    $batchOne = $business->batches()->sole();

    expect($batchOne->pieces()->count())->toBe(3)
        ->and($batchOne->projects()->pluck('id')->sort()->values()->all())
        ->toBe(collect([$projectA->id, $projectB->id, $projectC->id])->sort()->values()->all());

    $savedNestOne = $batchOne->fresh()->nested_state[NestingEnums::METERAGE->value][0]->nested;

    expect(cutTally(everyCutInNest($savedNestOne)))->toBe($required)
        ->and($batchOne->fresh()->letters_project_array)->toBe($letters);

    //The drop off project C's bar is banked as an offcut, with a mark and the bar it came off
    $banked = Offcut::query()->where('batch_from_id', $batchOne->id)->get();

    expect($banked)->toHaveCount(1);

    $drop = $banked->first();

    expect($drop->length)->toEqual(4000)
        ->and($drop->business_id)->toEqual($business->id)
        ->and($drop->bar_id)->not->toBeNull()
        ->and($drop->offcut_from_id)->toBeNull()
        ->and($drop->batch_to_id)->toBeNull()
        ->and($drop->unique_mark)->toMatch('/^[A-Z]{3,}$/')
        ->and(Bar::query()->where('batch_id', $batchOne->id)->count())->toBe(2);

    //It is not inventory yet - the steel has not been delivered
    expect($business->availableOffcuts()->count())->toBe(0);

    /*
     * Stage 4: the steel is ordered, certificated and marked delivered. Only now is the drop
     * physically in the yard, and only now can a later nest draw on it.
     */
    $supplier = Supplier::factory()->create(['name' => 'Southern Steel']);
    [, $orderOne] = quoteAndOrder($user, $batchOne, $supplier, quoteSent: true, orderSent: true);
    $orderOne->update(['material_cert_numbers' => 'HEAT-88201']);

    $this->post(route('order.mark.delivered', $orderOne))->assertRedirect();

    expect($orderOne->fresh()->is_delivered)->toBeTrue()
        ->and($business->availableOffcuts()->pluck('id')->all())->toBe([$drop->id]);

    /*
     * Stage 5: two more projects, nested against that inventory.
     *
     *   Project D: 1 of 3,000  ->  cut out of the banked 4,000mm offcut, leaving 1,000mm
     *   Project E: 1 of 8,000  ->  longer than anything on the shelf, so a 9m bar is bought
     *
     * The projects from round 1 are on a batch now, so none of their pieces may be nested again.
     */
    $projectD = createProject($user);
    $projectE = createProject($user);

    createPieces(sampleBOM($projectD, $dataClassificationService, [[3000, 1]]), $projectD, $dataClassificationService);
    createPieces(sampleBOM($projectE, $dataClassificationService, [[8000, 1]]), $projectE, $dataClassificationService);

    $suggestedTwo = $this->get(route('suggested.nesting'));
    $suggestedTwo->assertStatus(200);
    $suggestedTwoProps = $suggestedTwo->viewData('page')['props'];

    $lettersTwo = $suggestedTwoProps['lettersProjectArray'];

    expect(array_keys($lettersTwo))->toEqualCanonicalizing([$projectD->id, $projectE->id])
        ->and(array_unique(array_values($lettersTwo)))->toHaveCount(2);

    $suggestedNestTwo = $suggestedTwoProps['pieces'][NestingEnums::METERAGE->value][0]->nested;
    $suggestedCutsTwo = everyCutInNest($suggestedNestTwo);

    $requiredTwo = [
        $projectD->id.' x 3000' => 1,
        $projectE->id.' x 8000' => 1,
    ];
    ksort($requiredTwo);

    expect(cutTally($suggestedCutsTwo))->toBe($requiredTwo);

    foreach ($suggestedCutsTwo as $cut) {
        expect($cut['letter'])->toBe($lettersTwo[$cut['projectId']]);
    }

    //Exactly one offcut is opened: ours, for project D's piece, and it is not cut past its own length
    $utilisedOffcutBars = $suggestedNestTwo['bestResultOffcuts']['utilisedOffcutBars'];

    expect($utilisedOffcutBars)->toHaveCount(1);

    $offcutBar = $utilisedOffcutBars[0];

    expect($offcutBar['sourceOffcut']['offcutId'])->toEqual($drop->id)
        ->and($offcutBar['sourceOffcut']['unique_mark'])->toBe($drop->unique_mark)
        ->and($offcutBar['sourceOffcut']['batchFromId'])->toEqual($batchOne->id)
        ->and($offcutBar['sourceOffcut']['offcutLength'])->toEqual(4000)
        ->and($offcutBar['sourceOffcut']['cutLength'])->toBe(3000)
        ->and($offcutBar['sourceOffcut']['cuts'])->toHaveCount(1)
        ->and($offcutBar['sourceOffcut']['cuts'][0]['projectId'])->toBe($projectD->id)
        ->and($offcutBar['sourceOffcut']['cuts'][0]['letter'])->toBe($lettersTwo[$projectD->id])
        ->and($offcutBar['sourceOffcut']['cutLength'] + $offcutBar['sourceOffcut']['kerfLength'])
        ->toBeLessThanOrEqual($offcutBar['sourceOffcut']['offcutLength'])
        ->and($offcutBar['offcutFromOffcut']['reusableLength'])->toBe(1000)
        ->and($offcutBar['scrap']['scrapLength'])->toBe(0);

    //Project E's piece comes out of new stock, because no offcut in the yard could hold it
    $cutSources = array_column($suggestedCutsTwo, 'from', 'projectId');
    ksort($cutSources);

    expect($cutSources)->toBe([$projectD->id => 'OFFCUT', $projectE->id => 'NEW_STOCK']);

    expect($suggestedTwoProps['usage'][NestingEnums::METERAGE->value])
        ->toMatchArray([
            'totalPurchasedMaterial' => 9000,
            'totalOffcutMaterial' => 4000,
            'totalUsedMaterial' => 11000,
            'totalReusable' => 2000,
            'totalKerf' => 0,
            'totalScrap' => 0,
        ]);

    expect(failedNestingChecks($suggestedTwoProps['checks']))->toBe([]);

    /*
     * Stage 6: start quoting again. The offcut is spent, and what is left of it goes back on the
     * shelf as an offcut of an offcut with the trail back to the delivered steel intact.
     */
    $this->post(route('quotes.store'));

    expect($business->batches()->count())->toBe(2);

    $batchTwo = $business->batches()->where('batches.id', '!=', $batchOne->id)->sole();

    expect(cutTally(everyCutInNest($batchTwo->nested_state[NestingEnums::METERAGE->value][0]->nested)))
        ->toBe($requiredTwo);

    //The source offcut is spent, by this batch, and cannot be spent again
    expect($drop->fresh()->batch_to_id)->toEqual($batchTwo->id)
        ->and($business->availableOffcuts()->pluck('id')->all())->not->toContain($drop->id);

    //What was left of it: cut from an offcut, so it has no bar and its provenance is the chain
    $offcutOfOffcut = Offcut::query()->where('offcut_from_id', $drop->id)->sole();

    expect($offcutOfOffcut->length)->toEqual(1000)
        ->and($offcutOfOffcut->bar_id)->toBeNull()
        ->and($offcutOfOffcut->batch_from_id)->toEqual($batchTwo->id)
        ->and($offcutOfOffcut->batch_to_id)->toBeNull()
        ->and($offcutOfOffcut->business_id)->toEqual($business->id)
        ->and($offcutOfOffcut->unique_mark)->not->toBe($drop->unique_mark)
        ->and($offcutOfOffcut->generation())->toBe(2)
        ->and($offcutOfOffcut->ancestors()->pluck('id')->all())->toEqual([$drop->id]);

    //And the drop off project E's new bar, which came off a bar rather than an offcut
    $offcutFromNewBar = Offcut::query()
        ->where('batch_from_id', $batchTwo->id)
        ->whereNull('offcut_from_id')
        ->sole();

    expect($offcutFromNewBar->length)->toEqual(1000)
        ->and($offcutFromNewBar->bar_id)->not->toBeNull();

    /*
     * Inventory now: the offcut of an offcut is already in the yard, because its material was
     * delivered against the batch that cut its source. The drop off the new bar is not - batch two's
     * own steel has not been delivered yet. And the rival's offcut was never ours to see.
     */
    expect($business->availableOffcuts()->pluck('id')->all())->toBe([$offcutOfOffcut->id])
        ->and(Offcut::find($rivalOffcut->id)->batch_to_id)->toBeNull();

    /*
     * The steel balances end to end: three 9m bars bought, 25,000mm of it cut into finished pieces
     * for five projects, and 2,000mm still on the shelf. Nothing was destroyed and nothing appeared.
     */
    $bars = Bar::query()->whereIn('batch_id', [$batchOne->id, $batchTwo->id]);
    $boughtMm = (int) $bars->sum('length');
    $cutMm = 0;
    foreach ([$batchOne, $batchTwo] as $batch) {
        foreach ($batch->fresh()->nested_state[NestingEnums::METERAGE->value] as $product) {
            foreach (everyCutInNest($product->nested) as $cut) {
                $cutMm = $cutMm + $cut['length'];
            }
        }
    }
    $onTheShelfMm = (int) Offcut::query()
        ->whereIn('batch_from_id', [$batchOne->id, $batchTwo->id])
        ->whereNull('batch_to_id')
        ->sum('length');

    expect($bars->count())->toBe(3)
        ->and($boughtMm)->toBe(27000)
        ->and($cutMm)->toBe(25000)
        ->and($onTheShelfMm)->toBe(2000)
        ->and($cutMm + $onTheShelfMm)->toBe($boughtMm);

    /*
     * Stage 7: what the yard actually reads.
     *
     * The offcuts index shows the remaining offcut as second generation, names the mark it was cut
     * from, and carries the certificate for the steel it came out of - through the chain, because its
     * own batch bought nothing for it. The batch that certificate belongs to is the three-project
     * batch from round 1, which is the traceability the whole journey exists to keep.
     */
    $this->get(route('offcuts.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('OffcutsIndex')
            ->has('offcuts.data', 1)
            ->where('offcuts.data.0.id', $offcutOfOffcut->id)
            ->where('offcuts.data.0.generation', 2)
            ->where('offcuts.data.0.cut_from_marks', [$drop->unique_mark])
            ->where('offcuts.data.0.offcutOrdersWithCertificates.used_offcuts', true)
            ->where('offcuts.data.0.offcutOrdersWithCertificates.certificates.0.supplier_name', 'Southern Steel')
            ->where('offcuts.data.0.offcutOrdersWithCertificates.certificates.0.material_cert_numbers', 'HEAT-88201')
            //Cut from an offcut, so its own batch's purchases are not its certificates
            ->count('offcuts.data.0.newStockOrdersWithCertificates', 0)
            //The batch that cut it covered projects D and E
            ->count('offcuts.data.0.batch_projects', 2)
        );

    expect($drop->fresh()->batchFrom()->projectSummaries()->pluck('id')->sort()->values()->all())
        ->toBe(collect([$projectA->id, $projectB->id, $projectC->id])->sort()->values()->all());

    /*
     * And the print-friendly cut sheet for the three-project batch names the same projects on the
     * drawings as it does in the legend - the letters are read off the steel by hand.
     */
    $batchPage = $this->get(route('batch.nesting', [$batchOne->id, 'current', 1]));
    $batchPage->assertStatus(200);
    $batchProps = $batchPage->viewData('page')['props'];

    expect($batchProps['lettersProjectArray'])->toBe($letters);

    $cutsOnSheet = 0;
    foreach ($batchProps['pieces'][NestingEnums::METERAGE->value] as $product) {
        foreach (everyCutInNest($product->nested) as $cut) {
            expect($cut['letter'])->toBe($batchProps['lettersProjectArray'][$cut['projectId']]);
            $cutsOnSheet++;
        }
    }

    expect($cutsOnSheet)->toBe(3);
});
