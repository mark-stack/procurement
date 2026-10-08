<?php

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind a different classes or traits.
|
*/

use App\Enums\GradeEnums;
use App\Enums\MaterialEnums;
use App\Enums\MeasurementUnitEnums;
use App\Enums\NestingEnums;
use App\Enums\ProductEnums;
use App\Enums\SurfaceEnums;
use App\Formatters\TestingFormatter;
use App\Imports\ExcelImport;
use App\Models\Batch;
use App\Models\Business;
use App\Models\Offcut;
use App\Models\Order;
use App\Models\Piece;
use App\Models\Product;
use App\Models\Project;
use App\Models\Quote;
use App\Models\RawMaterialQuote;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\File\File;

pest()->extend(Tests\TestCase::class)
    ->use(Illuminate\Foundation\Testing\RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/
/**
 * Populate the products table by running the real seeder, so the tests exercise the same catalogue
 * the application ships rather than a hand-copied duplicate.
 *
 * This used to post to an admin route that re-imported a spreadsheet over the whole catalogue. That
 * route is gone, and so is the spreadsheet - the rows live in Database\Seeders\Data\MasterMaterials
 * and the seeder's only job is filling an empty database, which is exactly what this wants.
 */
function seedMasterMaterials(): void
{
    (new Database\Seeders\MasterMaterialsSeeder)->run();

    /*
     * The catalogue is PHP in the repo, so a clone has it and no disk is involved. Assert anyway:
     * an empty catalogue makes a nesting test fail several hundred lines later with no hint that
     * the products table is the reason.
     */
    expect(Product::count())->toBeGreaterThan(0);
}

function nestingTestCases(): array
{
    return [
        /**
         * Case 1
         *   1 of 9000: 2500|2500|2500|1500 (0 waste)
         *   1 of 9000: 2500|2500|1500 (2500 waste)
         */
        [
            'nest' => [
                [2500, 5], //length,qty
                [1500, 2],
            ],
            "qtyPieces" => 7,
            'result' => [
                [
                    'bar_length' => '9000',
                    'count' => 1,
                    'pieces' => [2500, 2500, 2500, 1500],
                    'unused' => 0,
                    "reusable" => 0,
                    "scrap" => 0,
                ],
                [
                    'bar_length' => '9000',
                    'count' => 1,
                    'pieces' => [2500, 2500, 1500],
                    'unused' => 2500,
                    "reusable" => 2500,
                    "scrap" => 0,
                ],
            ],
        ],
        /**
         * Case 2
         *   1 of 9000: 7000|1700 (300 waste)
         *   1 of 9000: 7000|1700 (300 waste)
         *   1 of 9000: 1700|1700|1700|1700|1700 (500 waste)
         */
        [
            'nest' => [
                [7000, 2], //length,qty
                [1700, 7],
            ],
            "qtyPieces" => 9,
            'result' => [
                [
                    'bar_length' => '9000',
                    'count' => 2,
                    'pieces' => [7000, 1700],
                    'unused' => 300,
                    "reusable" => 0,
                    "scrap" => 300,
                ],
                [
                    'bar_length' => '9000',
                    'count' => 1,
                    'pieces' => [1700, 1700, 1700, 1700, 1700],
                    'unused' => 500,
                    "reusable" => 0,
                    "scrap" => 500,
                ],
            ],
        ],
    ];
}

/**
 * @param  array<string, float>  $costs  nesting cost coefficients to override - see NestingCostModel
 */
function nestingBusiness(int $scrapThreshold = 1000, int $kerf = 0, array $costs = []): App\Models\Business
{
    $business = createBusiness('biz'.uniqid(), true);
    $business->scrap_threshold_mm = $scrapThreshold;
    $business->kerf_mm = $kerf;

    foreach ($costs as $setting => $value) {
        $business->setAttribute($setting, $value);
    }

    $business->save();

    return $business;
}

/**
 * A piece spec carrying nothing but a mass per metre.
 *
 * Only the section's weight matters to the cost model, and it is what decides whether destroying a few
 * hundred millimetres is cheaper than keeping a piece on the rack. Light angle and a heavy universal
 * beam answer that question differently, which is the point.
 */
function nestingSection(float $kgPerM): object
{
    return (object) ['kg_per_m' => $kgPerM];
}

/**
 * @param  array<int, array{0: int, 1: int}>  $lengthAndQty
 */
function nestingCuts(array $lengthAndQty, int $projectId = 1): array
{
    $cuts = [];
    $pieceId = 0;

    foreach ($lengthAndQty as [$length, $qty]) {
        for ($i = 0; $i < $qty; $i++) {
            $cuts[] = ['project' => $projectId, 'piece_id' => ++$pieceId, 'length' => $length];
        }
    }

    return $cuts;
}

function nestingOffcut(int $id, int $length): array
{
    return ['id' => $id, 'length' => $length, 'unique_mark' => 'M'.$id, 'batch_from_id' => 1];
}

function nestingTestCasesWithOffcuts(): array
{
    return [
        /**
         * Case 1
         *   From the 1,550mm offcut: 1500 (50 scrap)
         *   1 of 9000: 2500|2500|2500|1500 (0 waste)
         *   1 of 9000: 2500|2500 (4000 reusable)
         *
         * The 1,550mm stub takes one of the 1,500mm cuts and 50mm goes in the skip. Same 18,000mm
         * purchased either way, so the whole comparison is what the rack looks like afterwards:
         *
         *   leaving the stub alone  ->  1,200 + 1,550 + a 2,500 offcut   (three pieces)
         *   taking the stub         ->  1,200 + a 4,000 offcut           (two pieces)
         *
         * One fewer piece to store and find, and the offcut that is left is 4,000mm rather than 2,500mm,
         * which is worth appreciably more on the retention curve. 50mm of 200PFC - just over a kilogram -
         * buys both. The old plan read as 50mm less destroyed and a perfect yield figure while quietly
         * keeping a stub nobody was ever going to reach for.
         */
        [
            'nest' => [
                [2500, 5], //length,qty
                [1500, 2],
            ],
            "qtyPieces" => 7,
            "offcuts" => [
                1200,1550
            ],
            "expectedQtyOffcutsUsed" => 1,
            'result' => [
                [
                    'bar_length' => '9000',
                    'count' => 1,
                    'pieces' => [2500, 2500, 2500, 1500],
                    'unused' => 0,
                    "reusable" => 0,
                    "scrap" => 0,
                ],
                [
                    'bar_length' => '9000',
                    'count' => 1,
                    'pieces' => [2500, 2500],
                    'unused' => 4000,
                    "reusable" => 4000,
                    "scrap" => 0,
                ],
            ],
        ],
        /**
         * Case 2
         *   1 of 9000: 7000|1700 (300 waste)
         *   1 of 9000: 7000|1700 (300 waste)
         *   1 of 9000: 1700|1700|1700|1700|1700 (500 waste)
         */
        [
            'nest' => [
                [7000, 2], //length,qty
                [1700, 7],
            ],
            "qtyPieces" => 9,
            "offcuts" => [
                1200,2000
            ],
            "expectedQtyOffcutsUsed" => 1,
            'result' => [
                [
                    'bar_length' => '9000',
                    'count' => 2,
                    'pieces' => [7000, 1700],
                    'unused' => 300,
                    "reusable" => 0,
                    "scrap" => 300,
                ],
                [
                    'bar_length' => '9000',
                    'count' => 1,
                    'pieces' => [1700, 1700, 1700, 1700],
                    'unused' => 2200,
                    "reusable" => 2200,
                    "scrap" => 0,
                ],
            ],
        ],
    ];
}

/**
 * Swap the live payment provider for one that never touches the network.
 *
 * App\Billing\Billing is a singleton with the provider injected, so replacing the provider binding
 * alone would leave the already-resolved Billing holding the real one - hence the forgetInstance.
 *
 * Note that Business::billingState() memoises per instance: a business read before the fake was
 * changed has to be re-read, which is what a real request does anyway.
 */
function fakeBillingProvider(): Tests\Fakes\FakeBillingProvider
{
    $fake = new Tests\Fakes\FakeBillingProvider;

    app()->instance(App\Billing\Contracts\BillingProvider::class, $fake);
    app()->forgetInstance(App\Billing\Billing::class);

    return $fake;
}

/**
 * A business whose free trial ran out yesterday and which never bought anything - the read-only
 * state the billing gate exists for.
 */
function lapsedTrialBusiness(string $name = 'Lapsed'): Business
{
    $business = createBusiness($name);

    $business->trial_ends_at = now()->subDay();
    $business->save();

    return $business;
}

function createBusiness(string $name): Business
{
    return (new TestingFormatter())->createBusiness($name);
}

/**
 * Give this business the templates the workbooks in public/examples were read with.
 *
 * Registration hands out no templates - a business imports with what an admin records for it and
 * nothing else - so a test that uploads a spreadsheet has to say which templates are in place.
 * See Tests\Support\ExampleTemplates for the records themselves.
 */
function recordExampleTemplates(Business $business): void
{
    (new Tests\Support\ExampleTemplates)->recordFor($business);
}

function createUser(int $id, Business $business, bool $isAdmin, bool $emailVerified): User
{
    return (new TestingFormatter())->createUser($isAdmin,$id,$business,$emailVerified);

//    return User::factory()->create([
//        'name' => 'Mark',
//        'email' => $isAdmin
//            ? config('env.admin_email')
//            : ($id.'@'.$business->domain),
//        'business_id' => $business->id,
//        'email_verified_at' => $emailVerified ? now() : null,
//    ]);
}

function createProject(User $user): Project
{
    return (new TestingFormatter())->createProject($user);

//    return Project::create([
//        'name' => 'some project',
//        'user_id' => $user->id,
//        'reference' => 'ref',
//        'date_materials_required' => null,
//        'tentative' => true,
//        'done' => false,
//    ]);
}

function createRawMaterialQuote200Pfc(Project $project, MaterialEnums $material, GradeEnums $grade, int $length)
{
    return RawMaterialQuote::create([
        'csv_index' => 999,
        'description' => "200PFC",
        'product_category' => ProductEnums::PFC->value,
        'material' => $material->value,
        'grade' => $grade->value,
        'surface' => SurfaceEnums::NONE->value,
        'nominal_units' => MeasurementUnitEnums::MILLIMETERS,
        'length_required' => $length,
        'width_required' => null,
        'sub_qty' => 2,
        'project_id' => $project->id,
        'general_product_matches' => null,
        'custom_product_matches' => null,
        'assembly_mark' => '',
    ]);
}

/**
 * The same 200PFC piece, not yet nested - which is what puts its project in the board's Nesting
 * column: NestingFormatter::piecesReadyForBatching asks for pieces with no batch.
 */
function pieceReadyForBatching(Project $project): Piece
{
    $rawMaterialQuote = createRawMaterialQuote200Pfc(
        $project,
        MaterialEnums::PLAIN_CARBON_STEEL,
        GradeEnums::GR300,
        9000,
    );

    return Piece::create([
        'project_id' => $project->id,
        'raw_material_quote_id' => $rawMaterialQuote->id,
        'batch_id' => null,
        'product_category' => ProductEnums::PFC->value,
        'material' => MaterialEnums::PLAIN_CARBON_STEEL->value,
        'grade' => GradeEnums::GR300->value,
        'surface' => SurfaceEnums::NONE->value,
        'actual_length' => 9000,
    ]);
}

/**
 * A 200PFC piece nested onto a batch. A batch with no pieces has no projects, and the undo-nesting
 * gate refuses to unwind a batch that has no project of yours on it.
 */
function pieceOnBatch(Project $project, Batch $batch): Piece
{
    $rawMaterialQuote = createRawMaterialQuote200Pfc(
        $project,
        MaterialEnums::PLAIN_CARBON_STEEL,
        GradeEnums::GR300,
        9000,
    );

    return Piece::create([
        'project_id' => $project->id,
        'raw_material_quote_id' => $rawMaterialQuote->id,
        'batch_id' => $batch->id,
        'product_category' => ProductEnums::PFC->value,
        'material' => MaterialEnums::PLAIN_CARBON_STEEL->value,
        'grade' => GradeEnums::GR300->value,
        'surface' => SurfaceEnums::NONE->value,
        'actual_length' => 9000,
    ]);
}

function piecePfc(int $nominalHeight, int $length, int $qty, Project $project, object $dataClassificationService): array
{
    return (new TestingFormatter())->piecePfc($nominalHeight, $length, $qty, $project, $dataClassificationService);

//    $description = $nominalHeight.'PFC';
//    $generalProductMatches = $dataClassificationService->findGeneralProductMatchesFromText($description, $project->user);
//
//    return [
//        'description' => $description,
//        'material' => MaterialEnums::PLAIN_CARBON_STEEL->value,
//        'grade' => GradeEnums::GR300,
//        'surface' => SurfaceEnums::NONE,
//        'nominal_units' => MeasurementUnitEnums::MILLIMETERS,
//        'nominal_length' => null,
//        'precise_length' => null,
//        'nominal_width' => null,
//        'precise_width' => null,
//        'nominal_height' => $nominalHeight,
//        'precise_height' => null,
//        'length_required' => $length,
//        'width_required' => null,
//        'sub_qty' => $qty,
//        'project_id' => $project->id,
//        'general_product_matches' => serialize($generalProductMatches),
//        'assembly_mark' => 'on the thing',
//    ];
}

function sampleBOM(Project $project, object $dataClassificationService, array $nest): array
{
    return (new TestingFormatter())->sampleBOM($project, $dataClassificationService, $nest);

//    $bom = [];
//    foreach ($nest as $items) {
//        $bom[] = piecePfc(200, $items[0], $items[1], $project, $dataClassificationService);
//    }
//
//    return $bom;
}
function createRawMaterialQuote(array $row, object $dataClassificationService, Project $project): RawMaterialQuote
{
    return (new TestingFormatter())->createRawMaterialQuote($row, $dataClassificationService, $project);

//    $productCategory = $dataClassificationService->findProductConfigFromText($row['description']);
//    $productCategory = $productCategory ? $productCategory['productCategory'] : null;
//
//    return RawMaterialQuote::create([
//        'csv_index' => 999,
//        'description' => $row['description'],
//        'product_category' => $productCategory,
//        'material' => $row['material'] ?? null,
//        'grade' => $row['grade'] ?? null,
//        'surface' => $row['surface'] ?? null,
//        'nominal_units' => $dataClassificationService->findMeasurementUnit($productCategory),
//        'length_required' => $row['length_required'] ?? null,
//        'width_required' => $row['width_required'] ?? null,
//        'sub_qty' => $row['sub_qty'],
//        'project_id' => $project->id,
//        'general_product_matches' => serialize($row['general_product_matches']),
//        'assembly_mark' => $row['assembly_mark'] ?? '',
//    ]);
}

function createPieces(array $sampleBOM, Project $project, object $dataClassificationService): array
{
    return (new TestingFormatter())->createPieces($sampleBOM, $project, $dataClassificationService);

//    $pieces = [];
//
//    foreach ($sampleBOM as $row) {
//        $rawMaterialQuote = createRawMaterialQuote($row, $dataClassificationService, $project);
//
//        //Create piece
//        $piece = createPiece($project, $rawMaterialQuote, $row);
//        $pieces[] = $piece;
//    }
//
//    return $pieces;
}
function createPiece(Project $project, RawMaterialQuote $rawMaterialQuote, array $row): Piece
{
    return (new TestingFormatter())->createPiece($project, $rawMaterialQuote, $row);

//    $lengthRequired = $row['length_required'];
//    $widthRequired = $row['width_required'];
//
//    return Piece::create([
//        'project_id' => $project->id,
//        'raw_material_quote_id' => $rawMaterialQuote->id,
//        'product_category' => $rawMaterialQuote->product_category,
//        'material' => $rawMaterialQuote->material,
//        'grade' => $rawMaterialQuote->grade->value,
//        'surface' => SurfaceEnums::NONE->value,
//        'nominal_units' => MeasurementUnitEnums::MILLIMETERS->value,
//        'nesting_algo' => NestingEnums::METERAGE,
//        'nominal_length' => $row['length_required'] ?? null,
//        'precise_length' => $row['precise_length'] ?? null,
//        'nominal_width' => $row['nominal_width'] ?? null,
//        'precise_width' => $row['precise_width'] ?? null,
//        'nominal_height' => $row['nominal_height'] ?? null,
//        'precise_height' => $row['precise_height'] ?? null,
//        'actual_length' => $lengthRequired < 20 ? ($lengthRequired * 1000) : $lengthRequired,
//        'actual_width' => $widthRequired < 20 ? ($widthRequired * 1000) : $widthRequired,
//        'wall' => $row['wall'] ?? null,
//        'kg_per_m' => $row['kg_per_m'] ?? null,
//        'actual_qty' => $row['sub_qty'],
//    ]);
}

function csvArray(): ?array
{
    return (new TestingFormatter())->csvArray();

//    $filePath = 'templatesForTesting/Monthly budget excel.xlsx';
//    $csvArray = null;
//
//    if (Storage::disk('local')->exists($filePath)) {
//        $file = new File(Storage::path($filePath));
//        $csvArray = Excel::toArray(new ExcelImport, $file)[0];
//    }
//
//    return $csvArray;
}

function create_offcut_200PFC(int $length, int $batchFromId): Offcut
{
    return (new TestingFormatter())->create_offcut_200PFC($length, $batchFromId);
}

/**
 * A batch with a delivered, certificated STEEL_MERCHANT order - which is what makes its offcuts
 * "available" (see Business::availableOffcuts).
 */
function batchWithDeliveredOrder(User $user, ?string $cert = null): Batch
{
    $batch = Batch::factory()->forUser($user->id)->create();

    $quote = Quote::create([
        'user_id' => $user->id,
        'batch_id' => $batch->id,
        'supplier_category' => 'STEEL_MERCHANT',
        'supplier_quote_reference' => null,
        'quote_sent' => true,
        'quoted_price' => null,
        'quoted_lead_time' => null,
    ]);

    Order::create([
        'user_id' => $user->id,
        'batch_id' => $batch->id,
        'quote_id' => $quote->id,
        'order_sent' => true,
        'order_confirmation_received' => true,
        'purchase_order_number' => '123',
        'is_delivered' => true,
        'material_cert_numbers' => $cert,
    ]);

    return $batch;
}

/**
 * A verified user of a set-up business - the least that is needed to reach the offcuts page.
 */
function offcutsIndexUser(): User
{
    $business = createBusiness('biz');

    return createUser(1, $business, false, true);
}

/**
 * A chain of offcuts, each one cut from the one before it, oldest first.
 *
 * Only the root batch buys steel. Every batch after it nests entirely out of inventory and so places
 * no order at all - which is exactly what makes the certificate trail hard to follow.
 *
 * @return array<int, Offcut>
 */
function offcutGenerations(User $user, Batch $rootBatch, int $generations, int $length = 9000): array
{
    $chain = [create_offcut_200PFC($length, $rootBatch->id)];

    foreach (range(2, $generations) as $generation) {
        $source = end($chain);
        $length -= 1000;

        $cuttingBatch = Batch::factory()->forUser($user->id)->create();
        $source->batch_to_id = $cuttingBatch->id;
        $source->save();

        $produced = create_offcut_200PFC($length, $cuttingBatch->id);
        $produced->offcut_from_id = $source->id;
        $produced->save();

        $chain[] = $produced;
    }

    return $chain;
}

/**
 * Nest a real BOM the way the button does, and hand back what it produced.
 *
 * The long way round on purpose - through quotes.store - because the things worth testing on the far
 * side of it (the cuts the nest wrote down, the bars it bought, the supplier groups the batch requires)
 * are all derived from a real nest. A hand-built batch with one fixture piece on it produces none of
 * them: piecesNested needs a piece complete enough to resolve a product spec, and pieces with no
 * actual_qty expand to no cuts at all.
 *
 * Lives here rather than in one test file because three files need it. test() rather than $this, since
 * a helper at file scope is a plain function.
 *
 * @param  array<int, array{0: int, 1: int}>  $nest  length vs qty, as nestingTestCases() uses
 * @return array{0: Business, 1: User, 2: Batch}
 */
function nestedBatch(array $nest, string $businessName = 'fabricator'): array
{
    $dataClassificationService = new App\Services\DataClassificationService;

    //The catalogue has to exist before a BOM can be matched against it
    $adminUser = createUser(1, createBusiness('admin'), true, true);
    test()->actingAs($adminUser);
    seedMasterMaterials();

    $business = createBusiness($businessName);
    $user = createUser(1, $business, false, true);
    $project = createProject($user);

    $sampleBOM = sampleBOM($project, $dataClassificationService, $nest);
    createPieces($sampleBOM, $project, $dataClassificationService);

    test()->actingAs($user);
    test()->post(route('quotes.store'));

    return [$business, $user, Batch::first()];
}

/**
 * A quote for one supplier group on a batch, with the order that always accompanies it.
 *
 * @return array{0: Quote, 1: Order}
 */
function quoteAndOrder(
    User $user,
    Batch $batch,
    string $supplierCategory = 'STEEL_MERCHANT',
    bool $quoteSent = false,
    bool $orderSent = false,
): array {
    $quote = Quote::create([
        'user_id' => $user->id,
        'batch_id' => $batch->id,
        'supplier_category' => $supplierCategory,
        'supplier_quote_reference' => null,
        'quote_sent' => $quoteSent,
        'quoted_price' => null,
        'quoted_lead_time' => null,
    ]);

    $order = Order::create([
        'user_id' => $user->id,
        'batch_id' => $batch->id,
        'quote_id' => $quote->id,
        'order_sent' => $orderSent,
        'is_delivered' => false,
    ]);

    return [$quote, $order];
}
