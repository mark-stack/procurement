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
    $product = $dataClassificationService->findProductConfigFromText($description);

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
            $product["productCategory"],
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
    expect($product["product_category"])->toBe("PFC")
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

    //2 results in 2 grades
    expect($products->count())->toBe(2)
        ->and($products[0]["grade"])->toBe("GR250")
        ->and($products[1]["grade"])->toBe("GR350");
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
    $products = findProducts("M12 Allthread");

    //1 result
    expect($products->count())->toBe(1);

    //Product specs
    $product = $products[0];
    expect($product["product_category"])->toBe("ALLTHREAD")
        ->and($product["material"])->toBe("PLAIN_CARBON_STEEL")
        ->and($product["grade"])->toBe("GR_4_6")
        ->and($product["surface"])->toBe("GALVANISED")
        ->and($product["nominal_width"])->toBe("12");
});

test('that "M12 CHEMICAL ANCHOR 180mm" finds exact product', function () {
    //Create admin
    $adminUser = createAdmin();

    //Authorised
    $this->actingAs($adminUser);

    //Seed master_product.csv to create products
    $this->get(route('admin.update.master.materials.spreadsheet'));

    //Find product
    $products = findProducts("M12 CHEMICAL ANCHOR 180mm");

    //1 result
    expect($products->count())->toBe(1);

    //Product specs
    $product = $products[0];
    expect($product["product_category"])->toBe("ANCHOR_STUD")
        ->and($product["material"])->toBe("PLAIN_CARBON_STEEL")
        ->and($product["grade"])->toBe("GR_5_8")
        ->and($product["surface"])->toBe("ZINC")
        ->and($product["nominal_width"])->toBe("12")
        ->and($product["nominal_length"])->toBe("180");
});

test('that "M12 8.8S 30mm" finds exact product', function () {
    //Create admin
    $adminUser = createAdmin();

    //Authorised
    $this->actingAs($adminUser);

    //Seed master_product.csv to create products
    $this->get(route('admin.update.master.materials.spreadsheet'));

    //Find product
    $products = findProducts("M12 8.8S 30mm");

    //1 result
    expect($products->count())->toBe(1);

    //Product specs
    $product = $products[0];
    expect($product["product_category"])->toBe("HEX_BOLT")
        ->and($product["material"])->toBe("PLAIN_CARBON_STEEL")
        ->and($product["grade"])->toBe("GR_8_8")
        ->and($product["surface"])->toBe("ZINC")
        ->and($product["nominal_width"])->toBe("12")
        ->and($product["nominal_length"])->toBe("30");
});

test('that "M16 4.6S 45mm" finds exact product', function () {
    //Create admin
    $adminUser = createAdmin();

    //Authorised
    $this->actingAs($adminUser);

    //Seed master_product.csv to create products
    $this->get(route('admin.update.master.materials.spreadsheet'));

    //Find product
    $products = findProducts("M16 4.6S 45mm");

    //1 result
    expect($products->count())->toBe(1);

    //Product specs
    $product = $products[0];
    expect($product["product_category"])->toBe("HEX_BOLT")
        ->and($product["material"])->toBe("PLAIN_CARBON_STEEL")
        ->and($product["grade"])->toBe("GR_4_6")
        ->and($product["surface"])->toBe("ZINC")
        ->and($product["nominal_width"])->toBe("16")
        ->and($product["nominal_length"])->toBe("45");
});

test('that "M20 12.9_CSK 45mm" finds exact product', function () {
    //Create admin
    $adminUser = createAdmin();

    //Authorised
    $this->actingAs($adminUser);

    //Seed master_product.csv to create products
    $this->get(route('admin.update.master.materials.spreadsheet'));

    //Find product
    $products = findProducts("M20 12.9_CSK 45mm");

    //1 result
    expect($products->count())->toBe(1);

    //Product specs
    $product = $products[0];
    expect($product["product_category"])->toBe("CSK_BOLT")
        ->and($product["material"])->toBe("PLAIN_CARBON_STEEL")
        ->and($product["grade"])->toBe("GR_12_9")
        ->and($product["surface"])->toBe("ZINC")
        ->and($product["nominal_width"])->toBe("20")
        ->and($product["nominal_length"])->toBe("45");
});

test('that "M20x500 D20 ANCHOR ROD" finds exact product', function () {
    //Create admin
    $adminUser = createAdmin();

    //Authorised
    $this->actingAs($adminUser);

    //Seed master_product.csv to create products
    $this->get(route('admin.update.master.materials.spreadsheet'));

    //Find product
    $products = findProducts("M20x500 D20 ANCHOR ROD");

    //1 result
    expect($products->count())->toBe(1);

    //Product specs
    $product = $products[0];
    expect($product["product_category"])->toBe("ANCHOR_STUD")
        ->and($product["material"])->toBe("PLAIN_CARBON_STEEL")
        ->and($product["grade"])->toBe("GR_5_8")
        ->and($product["surface"])->toBe("GALVANISED")
        ->and($product["nominal_width"])->toBe("20")
        ->and($product["nominal_length"])->toBe("500");
});

test('that "M20 M20_NUT NUT" finds exact product', function () {
    //Create admin
    $adminUser = createAdmin();

    //Authorised
    $this->actingAs($adminUser);

    //Seed master_product.csv to create products
    $this->get(route('admin.update.master.materials.spreadsheet'));

    //Find product
    $products = findProducts("M20 M20_NUT NUT");

    //1 result
    expect($products->count())->toBe(1);

    //Product specs
    $product = $products[0];
    expect($product["product_category"])->toBe("NUT")
        ->and($product["material"])->toBe("PLAIN_CARBON_STEEL")
        ->and($product["nominal_width"])->toBe("20");
});

test('that "M20 x 65" finds exact product', function () {
    //Create admin
    $adminUser = createAdmin();

    //Authorised
    $this->actingAs($adminUser);

    //Seed master_product.csv to create products
    $this->get(route('admin.update.master.materials.spreadsheet'));

    //Find product
    //todo
});

test('that pipe is recognised in 3 formats', function () {
    /**
     * 300nb vs 324 (rounded) vs 323.9 actual
     */
    //Create admin
    $adminUser = createAdmin();

    //Authorised
    $this->actingAs($adminUser);

    //Seed master_product.csv to create products
    $this->get(route('admin.update.master.materials.spreadsheet'));

    //Find product
    //todo
});

test('that different UB weights are identified', function () {
    /**
     * 360 UB 56.7 and 360 UB 57
     * 360 UB 50.7 and 360 UB 51
     * 360 UB 44.7 and 360 UB 45
     */
    //Create admin
    $adminUser = createAdmin();

    //Authorised
    $this->actingAs($adminUser);

    //Seed master_product.csv to create products
    $this->get(route('admin.update.master.materials.spreadsheet'));

    //Find product
    //todo
});

test('that different UC weights are identified', function () {
    /**
     * 250 UC 89.5 and 250 UC 90
     * 250 UC 72.9 and 250 UC 73
     */
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


