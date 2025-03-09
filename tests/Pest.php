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
use App\Models\Business;
use App\Models\Offcut;
use App\Models\Piece;
use App\Models\Project;
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

function nestingTestCasesWithOffcuts(): array
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

function createBusiness(string $name, bool $adminSetupComplete): Business
{
    return (new TestingFormatter())->createBusiness($name,$adminSetupComplete);
//    return Business::create([
//        'name' => $name,
//        'domain' => str_replace(' ', '-', $name).'.com',
//        'admin_setup_complete' => $adminSetupComplete,
//    ]);
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
//        'archive' => false,
//    ]);
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

//    return Offcut::create([
//        'batch_from_id' => $batchFromId,
//        'batch_to_id' => null,
//        'piece_to_id' => null,
//        'product_category' => ProductEnums::PFC,
//        'material' => MaterialEnums::PLAIN_CARBON_STEEL,
//        'grade' => GradeEnums::GR300,
//        'surface' => SurfaceEnums::NONE,
//        'nominal_length' => null,
//        'precise_length' => null,
//        'nominal_width' => null,
//        'precise_width' => null,
//        'nominal_height' => 200,
//        'precise_height' => null,
//        'wall' => null,
//        'length' => $length,
//    ]);
}
