<?php

use App\Enums\GradeEnums;
use App\Enums\MaterialEnums;
use App\Enums\MeasurementUnitEnums;
use App\Enums\NestingEnums;
use App\Enums\SurfaceEnums;
use App\Models\Business;
use App\Models\Piece;
use App\Models\Project;
use App\Models\RawMaterialQuote;
use App\Models\User;
use App\Services\DataClassificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

function createAdmin(): User
{
    //Admin business
    $adminBusiness = Business::create([
        "name" => "marko",
        "domain" => "marko.com",
        "admin_setup_complete" => true,
    ]);

    //Admin user
    return User::factory()->create([
        "name" => "Mark",
        "email" => env("ADMIN_EMAIL"),
        "business_id" => $adminBusiness->id,
    ]);
}

function createProject(User $user, bool $awarded): Project
{
    return Project::create([
        "name" => "some project",
        'user_id' => $user->id,
        "awarded" => $awarded,
        "reference" => "ref",
        "date_materials_required" => null,
        "tentative" => true,
        "archive" => false,
    ]);
}

function piecePfc(int $nominalHeight, int $length, int $qty, Project $project, object $dataClassificationService): array
{
    $description = $nominalHeight."PFC";
    $generalProductMatches = $dataClassificationService->findGeneralProductMatchesFromText($description,$project->user);

    return [
        "description" => $description,
        "material" => MaterialEnums::PLAIN_CARBON_STEEL->value,
        "grade" => GradeEnums::GR300,
        "surface" => SurfaceEnums::NONE,
        "nominal_units" => MeasurementUnitEnums::MILLIMETERS,
        "length_required" => $length,
        "width_required" => null,
        "sub_qty" => $qty,
        "unit_rate" => 100,
        'project_id' => $project->id,
        "general_product_matches" => serialize($generalProductMatches->toArray()),
        "assembly_mark" => "on the thing",
    ];
}

function sampleBOM(Project $project, object $dataClassificationService): array
{
    $bom[] = [
        piecePfc(200,1500, 2, $project, $dataClassificationService),
        piecePfc(200,2500, 5, $project, $dataClassificationService),
    ];

    return $bom;
}
function createRawMaterialQuote(array $row, object $dataClassificationService, Project $project): RawMaterialQuote
{
    $productCategory = $dataClassificationService->findProductConfigFromText($row["description"]);
    $productCategory = $productCategory ? $productCategory["productCategory"] : null;

    return RawMaterialQuote::create([
        "csv_index" => 999,
        "description" => $row["description"],
        "product_category" => $productCategory,
        "material" => $row["material"] ?? null,
        "grade" => $row["grade"] ?? null,
        "surface" => $row["surface"] ?? null,
        "nominal_units" => $dataClassificationService->findMeasurementUnit($productCategory),
        "length_required" => $row["length_required"] ?? null,
        "width_required" => $row["width_required"] ?? null,
        "sub_qty" => $row["sub_qty"],
        "unit_rate" => $row["unit_rate"] ?? null,
        'project_id' => $project->id,
        "general_product_matches" => serialize($row["general_product_matches"]),
        "assembly_mark" => $row["assembly_mark"] ?? "",
    ]);
}

function createPieces(array $sampleBOM,Project $project,object $dataClassificationService): array
{
    $pieces = [];
    foreach($sampleBOM as $row){
        $rawMaterialQuote = createRawMaterialQuote($row,$dataClassificationService,$project);

        //Create piece
        $piece = createPiece($project,$rawMaterialQuote,$row);
        $pieces[] = $piece;
    }

    return $pieces;
}
function createPiece(Project $project, RawMaterialQuote $rawMaterialQuote, array $row): Piece
{
    $lengthRequired = $row["length_required"];
    $widthRequired = $row["width_required"];

    return Piece::create([
        'project_id' => $project->id,
        "raw_material_quote_id" => $rawMaterialQuote->id,
        "product_category" => $rawMaterialQuote->product_category,
        "material" => $rawMaterialQuote->material,
        "grade" => $rawMaterialQuote->grade->value,
        "surface" => SurfaceEnums::NONE->value,
        "nominal_units" => MeasurementUnitEnums::MILLIMETERS->value,
        "nesting_algo" => NestingEnums::METERAGE,
        "nominal_length" => $row["length_required"] ?? null,
        "precise_length" => $row["precise_length"] ?? null,
        "nominal_width" => $row["nominal_width"] ?? null,
        "precise_width" => $row["precise_width"] ?? null,
        "nominal_height" => $row['nominal_height'] ?? null,
        "precise_height" => $row['precise_height'] ?? null,
        "actual_length" => $lengthRequired < 20 ? ($lengthRequired*1000) : $lengthRequired,
        "actual_width" => $widthRequired < 20 ? ($widthRequired*1000) : $widthRequired,
        "wall" => $row["wall"] ?? null,
        "kg_per_m" => $row["kg_per_m"] ?? null,
        "actual_qty" => $row["sub_qty"]
    ]);
}

test('project with awarded status has materials available for nesting', function () {
    /**
     * project with awarded status has materials available for nesting
     */
    //Create admin
    $adminUser = createAdmin();

    //Authorised
    $this->actingAs($adminUser);

    //Seed master_product.csv to create products
    $this->get(route('admin.update.master.materials.spreadsheet'));

    //Create project
    $project = createProject($adminUser,true);

    //Service
    $dataClassificationService = new dataClassificationService();

    //Create BOM
    $sampleBOM = sampleBOM($project,$dataClassificationService);
    dd($sampleBOM);
    //Create raw material quotes & pieces
    $pieces = createPieces($sampleBOM,$project,$dataClassificationService);
    expect(count($pieces))->toBe(5);

    $response = $this->get(route("quotes.index"));
    $response->assertStatus(200);

    $response->assertInertia(fn (Assert $page) => $page
        ->count('pieces',1) //1 batch parsed to the view
        ->count('pieces.0.0.pieces',count($pieces)) //5 pieces
    );
});

test('project with non-awarded status has no materials available for nesting', function () {
    /**
     *project with non-awarded status has no materials available for nesting
     */
    //Create admin
    $adminUser = createAdmin();

    //Authorised
    $this->actingAs($adminUser);

    //Seed master_product.csv to create products
    $this->get(route('admin.update.master.materials.spreadsheet'));

    //Create project
    $project = createProject($adminUser,false);

    //Service
    $dataClassificationService = new dataClassificationService();

    //Create BOM
    $sampleBOM = sampleBOM($project,$dataClassificationService);

    //Create raw material quotes & pieces
    $pieces = createPieces($sampleBOM,$project,$dataClassificationService);
    expect(count($pieces))->toBe(5);

    $response = $this->get(route("quotes.index"));
    $response->assertStatus(200);

    $response->assertInertia(fn (Assert $page) => $page
        ->count('pieces',0) //No batches parsed to the view
    );
});

test('import detects units of length input by user', function () {
    /**
     * A user might provide a BOM in METERS, MILLIMETERS, or a mix of both.
     * The basic rule is below 20 = METERS
     */
});

test('one click email material list', function () {
    /**
     *
     */
});

test('bundle nesting with single project', function () {
    /**
     *
     */
});

test('meterage nesting with single project', function () {
    /**
     * Test that 5 items in BOM are successfully nested
     */
    //Create admin
    $adminUser = createAdmin();

    //Authorised
    $this->actingAs($adminUser);

    //Seed master_product.csv to create products
    $this->get(route('admin.update.master.materials.spreadsheet'));

    //Create project
    $project = createProject($adminUser,true);

    //Service
    $dataClassificationService = new dataClassificationService();

    //Create BOM
    $sampleBOM = sampleBOM($project,$dataClassificationService);

    //Create raw material quotes & pieces
    $pieces = createPieces($sampleBOM,$project,$dataClassificationService);
    expect(count($pieces))->toBe(5);

    $response = $this->get(route("quotes.index"));
    $response->assertStatus(200);

    $response->assertInertia(fn (Assert $page) => $page
        ->count('pieces',1) //1 batch parsed to the view
        ->count('pieces.0.0.pieces',count($pieces)) //5 pieces
        ->dd()
    );
});

test('meterage nesting with offcut', function () {
    /**
     *
     */
});

test('meterage nesting without offcut', function () {
    /**
     *
     */
});

test("meterage nesting doesn't mix different material types", function () {
    /**
     * Check that say only 150PFC is nested together and that 200PFC is excluded
     */
});

//todo more


