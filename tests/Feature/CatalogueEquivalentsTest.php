<?php

use App\Services\DataClassificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
 * The mass a detailer writes against a beam is rarely the mass the catalogue holds. Tekla writes
 * "UB360*51"; the section is 360UB50.7. SectionDescriptorTest proves the 51 is READ - this proves
 * it reaches the one stocked product it names.
 *
 * The equivalence matrices in DataClassificationService are what join the two, and several stocked
 * masses round to the same nominal: 18 is 180UB18.1 and 200UB18.2 both, 22 is 180UB22.2 and
 * 200UB22.3, 82 is 460UB82.1 and 530UB82.0. Those spellings used to match NOTHING. Each equivalent
 * added its own ->where(), so the query asked for a mass that was 18.1 and 18.2 at once, and the
 * depth that would have separated them was never consulted. "180UB18" and "200UB18" are ordinary
 * secondary beams, and every one of them was reported to the customer as not stocked.
 *
 * Each case is [descriptor, depth, the catalogue mass it must land on].
 */
$nominalMasses = [
    //The three spellings that this sheet of a customer's assembly list is made of
    ['UB200*18', 200.0, 18.2],
    ['UB180*18', 180.0, 18.1],
    ['UB180*22', 180.0, 22.2],
    //The rest of the range the same collision reaches
    ['UB200*22', 200.0, 22.3],
    ['UB460*82', 460.0, 82.1],
    //82.0 equals the nominal 82 exactly, so this one matched even before the fix
    ['UB530*82', 530.0, 82.0],
    //A mass that rounds to exactly one stocked section, which never had the problem
    ['UB360*51', 360.0, 50.7],
    ['UB310*40', 310.0, 40.4],
    ['UC310*97', 310.0, 96.8],
];

/*
 * The precise mass, written out. It matches a single row of the matrix however the matching is
 * assembled, which is why the fault above stayed hidden: the half of the corpus that spells the
 * mass in full exercises none of it.
 */
$preciseMasses = [
    ['UB200*18.2', 200.0, 18.2],
    ['200UB18.2', 200.0, 18.2],
    ['UB180*18.1', 180.0, 18.1],
    ['UB460*82.1', 460.0, 82.1],
];

it('matches a nominal mass to the one section that carries it', function (string $descriptor, float $height, float $kgPerM) {
    expectSingleCatalogueMatch($descriptor, $height, $kgPerM);
})->with($nominalMasses);

it('matches a precise mass to the one section that carries it', function (string $descriptor, float $height, float $kgPerM) {
    expectSingleCatalogueMatch($descriptor, $height, $kgPerM);
})->with($preciseMasses);

/**
 * One descriptor, one product spec. More than one is not a near miss - saveRawMaterialQuoteData()
 * only builds a piece where exactly one spec matched, so a second match leaves the row waiting on
 * a customer to choose between two beams their spreadsheet never asked them about.
 */
function expectSingleCatalogueMatch(string $descriptor, float $height, float $kgPerM): void
{
    $adminBusiness = createBusiness('admin');
    $adminUser = createUser(1, $adminBusiness, true, true);

    test()->actingAs($adminUser);
    seedMasterMaterials();

    $business = createBusiness('gmail');
    $user = createUser(1, $business, false, true);

    $matches = (new DataClassificationService)->findGeneralProductMatchesFromText($descriptor, $user);

    expect($matches['results'])->toHaveCount(1, "$descriptor matched ".count($matches['results']).' products');

    $spec = $matches['results'][0];

    expect((float) $spec['nominal_height'])->toEqualWithDelta($height, 0.05, "$descriptor: depth")
        ->and((float) $spec['kg_per_m'])->toEqualWithDelta($kgPerM, 0.05, "$descriptor: mass");
}
