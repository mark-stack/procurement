<?php

use App\Models\Business;
use App\Models\Product;
use App\Models\User;
use App\Services\DataClassificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;

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

function findProducts(string $description): Collection
{
    $dataClassificationService = new DataClassificationService();

    //PRODUCT
    $product = $dataClassificationService->findProductConfig($description);

    $generalProductMatches = collect([]);

    //Has product
    if($product) {
        //MATERIAL
        $materialEnum = $dataClassificationService->findMaterial($product, $description);

        //GRADE
        $gradesEnums = $dataClassificationService->findGrades($product, $description);

        //SURFACE
        $surfaceEnum = $dataClassificationService->findSurface($product, $description, $gradesEnums);

        //NOMINAL UNITS
        $measurementUnitEnum = $dataClassificationService->findMeasurementUnit($product);

        //NOMINAL LENGTH
        $nominalLengthInt = $dataClassificationService->findNominal($product, $description, "nominalLengthRegex");

        //NOMINAL WIDTH
        $nominalWidthInt = $dataClassificationService->findNominal($product, $description, "nominalWidthRegex");

        //NOMINAL HEIGHT
        $nominalHeightInt = $dataClassificationService->findNominal($product, $description, "nominalHeightRegex");

        //Price book search
        $user = auth()->user();
        $generalProductMatches = $dataClassificationService->findGeneralProductMatches(
            $user,
            $product["productEnum"]->value,
            $materialEnum,
            $gradesEnums,
            $surfaceEnum,
            $measurementUnitEnum,
            $nominalLengthInt,
            $nominalWidthInt,
            $nominalHeightInt
        );
    }

    return $generalProductMatches;
}

test('that master_files.csv successfully seeds products', function () {
    //Create admin
    $adminUser = createAdmin();

    //Authorised
    $this->actingAs($adminUser);

    //Seed master_product.csv to create products
    $this->get(route('admin.update.master.materials.spreadsheet'));

    //Test that over 100 products were created
    expect(Product::count())->toBeGreaterThan(100);
});

test('that "75PFC 9m" finds exact product', function () {
    //Create admin
    $adminUser = createAdmin();

    //Authorised
    $this->actingAs($adminUser);

    //Seed master_product.csv to create products
    $this->get(route('admin.update.master.materials.spreadsheet'));

    //Find product
    $products = findProducts("75PFC 9m");

    //1 result
    expect($products->count())->toBe(1);

    //Product specs
    $product = $products[0];
    expect($product["product"])->toBe("PFC")
        ->and($product["material"])->toBe("PLAIN_CARBON_STEEL")
        ->and($product["grade"])->toBe("GR300")
        ->and($product["nominal_height"])->toBe("75");
});

test('that "PLT10(asterix)160" finds GR250 and GR350', function () {
    //Create admin
    $adminUser = createAdmin();

    //Authorised
    $this->actingAs($adminUser);

    //Seed master_product.csv to create products
    $this->get(route('admin.update.master.materials.spreadsheet'));

    //Find product
    $products = findProducts("PLT10*160");

    //2 results
    expect($products->count())->toBe(2);

    //Product specs
    expect($products[0]["grade"])->toBe("GR250");
    expect($products[1]["grade"])->toBe("GR350");
});

test('that "150PFC 9000mm" finds exact product', function () {
    //Create admin
    $adminUser = createAdmin();

    //Authorised
    $this->actingAs($adminUser);

    //Seed master_product.csv to create products
    $this->get(route('admin.update.master.materials.spreadsheet'));

    //Find product
    //todo
});

test('that "UB460(asterix)67" finds exact product', function () {
    //Create admin
    $adminUser = createAdmin();

    //Authorised
    $this->actingAs($adminUser);

    //Seed master_product.csv to create products
    $this->get(route('admin.update.master.materials.spreadsheet'));

    //Find product
    //todo
});

test('that "90X63 LVL 7 meters" finds exact product', function () {
    //Create admin
    $adminUser = createAdmin();

    //Authorised
    $this->actingAs($adminUser);

    //Seed master_product.csv to create products
    $this->get(route('admin.update.master.materials.spreadsheet'));

    //Find product
    //todo
});

test('that "M12 Allthread" finds exact product', function () {
    //Create admin
    $adminUser = createAdmin();

    //Authorised
    $this->actingAs($adminUser);

    //Seed master_product.csv to create products
    $this->get(route('admin.update.master.materials.spreadsheet'));

    //Find product
    //todo
});
//todo more


