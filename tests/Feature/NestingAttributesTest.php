<?php

use App\Services\DataClassificationService;
use App\Services\ProductService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('would be a disaster if mismatched attributes', function (string $description1, string $description2, string $result) {
    //Services
    $dataClassificationService = new DataClassificationService;
    $productService = new ProductService;

    /*
     * Create admin & seed materials
     */
    $adminBusiness = createBusiness('admin', true);
    $adminUser = createUser(1, $adminBusiness, true, true);

    //Seed master_product.csv to create products
    $this->actingAs($adminUser);
    $this->get(route('admin.update.master.materials.spreadsheet'));

    //Fake data
    $business = createBusiness('gmail', true);
    $user = createUser(1, $business, false, true);
    $project = createProject($user);

    /*
     * Piece 1
     */
    $productCategory1 = $dataClassificationService->findProductConfigFromText($description1)['productCategory'];
    $generalProductMatches1 = $dataClassificationService->findGeneralProductMatchesFromText(
        $description1,
        $project->user,
    );
    $productSpec1 = $generalProductMatches1["results"];
    //dd(3,$productSpec1,$description1);
    expect(count($productSpec1))->toEqual(1);

    /*
     * Piece 2
     */
    $productCategory2 = $dataClassificationService->findProductConfigFromText($description2)['productCategory'];
    $generalProductMatches2 = $dataClassificationService->findGeneralProductMatchesFromText(
        $description2,
        $project->user,
    );
    $productSpec2 = $generalProductMatches2["results"];
    expect(count($productSpec2))->toEqual(1);

    //Same product categories
    expect($productCategory1)->toEqual($productCategory2);


    $generalProductDefinition = $productService->generalProductDefinition($productCategory1);

    //Product definition
    $allFieldsIndividual = [];
    foreach ($generalProductDefinition['mandatory'] as $field) {
        $allFieldsIndividual[$field] = false;
    }

    //Fields
    $fieldLabels = array_keys($allFieldsIndividual);

    $allMatchCount = 0;
    foreach($fieldLabels as $fieldLabel){
        if($productSpec1[0][$fieldLabel] === $productSpec2[0][$fieldLabel]){
            $allMatchCount++;
        }
    }

    //All fields match
    if($result === "PASS"){
        expect($allMatchCount)->toEqual(count($fieldLabels));
    }
    //NOT All fields match
    if($result === "FAIL"){
        expect($allMatchCount)->toBeLessThan(count($fieldLabels));
    }
})->with([
    //Mismatched products
    ["200PFC","PFC200","PASS"], //ok
    ["200PFC","PFC250","FAIL"], //ok

    //mismatched materials
    //todo
//    ["200PFC","200PFC","PASS"],
//    ["200PFC","200PFC SS316","FAIL"],

    //Mismatched grades
    ["10PL Gr350","10mm plate 350MPA","PASS"], //ok
    ["M16x100 GR4.6 Galv","M16x100 4.6 Galvanised","PASS"], //ok
    ["M16x100 GR4.6 HDG","M16x100 4.6 Galvanised","PASS"], //ok
    ["10PL gr250","10PL gr350","FAIL"], //ok
    ["M16x100 GR4.6 Gal","M16x100 GR8.8 Gal","FAIL"], //ok

    //Mismatched surface
    ["M12x30 GR8.8 ZINC","M12x30 8.8 ZINC","PASS"], //ok
    ["M12x30 GR8.8 Gal","M12x30 8.8 Zinc","FAIL"], //ok

    //Mismatched thickness/wall
    ["50x25x2.5 RHS","50 x 25 x 2.5 RHS","PASS"], //ok
    ["50x25x3.0 RHS","50x25x3RHS","PASS"], //ok
    ["75x25x1.6 RHS","75x25x1.6RHS","PASS"], //ok
    ["50x25x2.5 RHS","50 x 25 x 3.0 RHS","FAIL"], //ok
    ["50x25x2.5 RHS","RHS50x25x3","FAIL"],
    ["10PL gr250","16PL gr350","FAIL"], //ok
    ["10PL 350Mpa","16mm plate GR350","FAIL"], //ok

    //Mismatched weight
    ["310 UB 32","310UB32","PASS"], //ok
    ["310 UB 32","310UB40","FAIL"], //ok

    //Mismatched width
    ["75x25x1.6 RHS","RHS 75 x 25 x 1.6","PASS"],
    ["75x25x1.6 RHS","75x50x1.6RHS","FAIL"], //ok

    //Mismatched height
    ["M12x30 GR8.8 HDG","M12x30 8.8 Gal","PASS"], //ok
    ["M12x30 GR8.8 HDG","M12x35 8.8 Gal","FAIL"], //ok
]);

