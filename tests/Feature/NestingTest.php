<?php

use App\Enums\MeasurementUnitEnums;
use App\Enums\SurfaceEnums;
use App\Models\Business;
use App\Models\Piece;
use App\Models\Project;
use App\Models\RawMaterialQuote;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

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

function createProject(User $user): Project
{
    return Project::create([
        "name" => "some project",
        'user_id' => $user->id,
        "awarded" => true,
        "reference" => "ref",
        "date_materials_required" => null,
        "tentative" => true,
        "archive" => false,
    ]);
}

function sampleBOM(): array
{
    return [
        [
            "description" => fake()->text(100),
        ],
    ];
}
function createRawMaterialQuotes()
{
    $rawMaterialQuote = RawMaterialQuote::create([
        "csv_index" => 999,
        "description" => $row["description"],
        "product_category" => $productCategory,
        "material" => $row["material"] ?? null,
        "grade" => $row["grade"] ?? null,
        "surface" => $row["surface"] ?? null,
        "nominal_units" => $dataClassificationService->findMeasurementUnit($productCategory),
        "length_required" => $row["length_required"],
        "width_required" => $row["width_required"] ?? null,
        "sub_qty" => $row["sub_qty"],
        "unit_rate" => $row["unit_rate"] ?? null,
        'project_id' => $project->id,
        "general_product_matches" => serialize($row["generalProductMatches"]),
        "assembly_mark" => $row["assembly_mark"] ?? "",
    ]);
}
function createPieces(Project $project): array
{
    Piece::create([
        'project_id' => $project->id,
        "raw_material_quote_id" => $rawMaterialQuote->id,
        "product_category" => $preparedFormData["product_category"],
        "material" => $preparedFormData["material"],
        "grade" => $preparedFormData["grade"],
        "surface" => SurfaceEnums::NONE->value,
        "nominal_units" => MeasurementUnitEnums::MILLIMETERS->value,
        "nesting_algo" => $preparedFormData['nesting_algo'],
        "nominal_length" => $preparedFormData['nominal_length'],
        "precise_length" => $preparedFormData['precise_length'],
        "nominal_width" => $preparedFormData['nominal_width'],
        "precise_width" => $preparedFormData['precise_width'],
        "nominal_height" => $preparedFormData['nominal_height'],
        "precise_height" => $preparedFormData['precise_height'],
        "actual_length" => $lengthRequired < 20 ? ($lengthRequired*1000) : $lengthRequired,
        "actual_width" => $widthRequired < 20 ? ($widthRequired*1000) : $widthRequired,
        "wall" => $formData["data"]["Wall"],
        "kg_per_m" => $formData["kg_per_m"] ?? null, //todo this is not retrieving data
        "actual_qty" => $formData["data"]["sub_qty"]
    ]);
}

test('project with awarded status has materials available for nesting', function () {
    /**
     *
     */
    //Create admin
    $adminUser = createAdmin();

    //Create project
    $awardedProject = createProject($adminUser);

    //Create BOM
    $sampleBOM = sampleBOM();

    //Create raw material quotes
    foreach($sampleBOM as $row){

    }

    //Create pieces
    $pieces = createPieces($awardedProject);
    dd($pieces);

});

test('project with non-awarded status has no materials available for nesting', function () {
    /**
     *
     */
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
     *
     */
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

//todo more


