<?php

use App\Enums\GradeEnums;
use App\Enums\MaterialEnums;
use App\Enums\ProductEnums;
use App\Enums\SurfaceEnums;
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

        //WALL
        $wall = $dataClassificationService->findNominal($product, $description, "wallRegex");

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
            $nominalHeightInt,
            $wall,
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

    //Test
    expect($products[0]["product_category"])->toBe(ProductEnums::PFC->value)
        ->and($products[0]["material"])->toBe(MaterialEnums::PLAIN_CARBON_STEEL->value)
        ->and($products[0]["grade"])->toBe(GradeEnums::GR300->value)
        ->and($products[0]["nominal_height"])->toBe("75");
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
    expect($products[0]["product_category"])->toBe(ProductEnums::PLATE->value)
        ->and($products[0]["nominal_height"])->toBe("10")
        ->and($products[0]["grade"])->toBe(GradeEnums::GR250->value)
        ->and($products[1]["grade"])->toBe(GradeEnums::GR350->value);
});

test('that "150PFC 9000mm" finds exact product', function () {
    //Create admin
    $adminUser = createAdmin();

    //Authorised
    $this->actingAs($adminUser);

    //Seed master_product.csv to create products
    $this->get(route('admin.update.master.materials.spreadsheet'));

    //Find product
    $products = findProducts("150PFC 9000mm");

    //Test
    expect($products[0]["product_category"])->toBe(ProductEnums::PFC->value)
        ->and($products[0]["material"])->toBe(MaterialEnums::PLAIN_CARBON_STEEL->value)
        ->and($products[0]["grade"])->toBe(GradeEnums::GR300->value)
        ->and($products[0]["surface"])->toBe(SurfaceEnums::NONE->value)
        ->and($products[0]["nominal_height"])->toBe("150");
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
    expect($product["product_category"])->toBe(ProductEnums::ALLTHREAD->value)
        ->and($product["material"])->toBe(MaterialEnums::PLAIN_CARBON_STEEL->value)
        ->and($product["grade"])->toBe(GradeEnums::GR_4_6->value)
        ->and($product["surface"])->toBe(SurfaceEnums::GALVANISED->value)
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

    //Test
    expect($products[0]["product_category"])->toBe("ANCHOR_STUD")
        ->and($products[0]["material"])->toBe(MaterialEnums::PLAIN_CARBON_STEEL->value)
        ->and($products[0]["grade"])->toBe(GradeEnums::GR_5_8->value)
        ->and($products[0]["surface"])->toBe("ZINC")
        ->and($products[0]["nominal_width"])->toBe("12")
        ->and($products[0]["nominal_length"])->toBe("180");
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

    //Test
    expect($products[0]["product_category"])->toBe("HEX_BOLT")
        //1st result
        ->and($products[0]["material"])->toBe(MaterialEnums::PLAIN_CARBON_STEEL->value)
        ->and($products[0]["grade"])->toBe(GradeEnums::GR_8_8->value)
        ->and($products[0]["nominal_width"])->toBe("12")
        ->and($products[0]["nominal_length"])->toBe("30")
        ->and($products[0]["surface"])->toBe("ZINC")
        //2nd result
        ->and($products[1]["material"])->toBe(MaterialEnums::PLAIN_CARBON_STEEL->value)
        ->and($products[1]["grade"])->toBe(GradeEnums::GR_8_8->value)
        ->and($products[1]["nominal_width"])->toBe("12")
        ->and($products[1]["nominal_length"])->toBe("30")
        ->and($products[1]["surface"])->toBe(SurfaceEnums::GALVANISED->value);
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
        ->and($product["material"])->toBe(MaterialEnums::PLAIN_CARBON_STEEL->value)
        ->and($product["grade"])->toBe(GradeEnums::GR_4_6->value)
        ->and($product["surface"])->toBe(SurfaceEnums::ZINC->value)
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
        ->and($product["material"])->toBe(MaterialEnums::PLAIN_CARBON_STEEL->value)
        ->and($product["grade"])->toBe(GradeEnums::GR_12_9->value)
        ->and($product["surface"])->toBe(SurfaceEnums::ZINC->value)
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
        ->and($product["material"])->toBe(MaterialEnums::PLAIN_CARBON_STEEL->value)
        ->and($product["grade"])->toBe(GradeEnums::GR_5_8->value)
        ->and($product["surface"])->toBe(SurfaceEnums::GALVANISED->value)
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
    expect($product["product_category"])->toBe(ProductEnums::NUT->value)
        ->and($product["material"])->toBe(MaterialEnums::PLAIN_CARBON_STEEL->value)
        ->and($product["nominal_width"])->toBe("20");
});

test('that "M20 x 65" finds exact products', function () {
    //Create admin
    $adminUser = createAdmin();

    //Authorised
    $this->actingAs($adminUser);

    //Seed master_product.csv to create products
    $this->get(route('admin.update.master.materials.spreadsheet'));

    //Find product
    $products = findProducts("M20 x 65");

    //2 results (HDG and Zinc)
    expect($products[0]["product_category"])->toBe(ProductEnums::HEX_BOLT->value)
        ->and($products[0]["nominal_width"])->toBe("20")
        ->and($products[0]["nominal_length"])->toBe("65")
        ->and($products[0]["grade"])->toBe(GradeEnums::GR_8_8->value)
        ->and($products[0]["surface"])->toBe(SurfaceEnums::GALVANISED->value)
        ->and($products[1]["surface"])->toBe(SurfaceEnums::ZINC->value);
});

test('that CHS distinguishes nominal & actual diameter, and wall thickness variations', function () {
    /**
     * CHS is recognised in 3 formats (nominal, actual, actual rounded)
     * "25nb" vs "33.7OD" vs 34OD"
     * Thickness variations also identified. e.g 3.2mm wall.
     * Output like: "20nb (Ø33.7x3.2)”
     *
     * Formats to pass:
     * - CHS 200nb (Ø219.1x6.4) 12m
     * - CHS193.7*6.0
     * - 150nb (Ø168.3)
     */
    //Create admin
    $adminUser = createAdmin();

    //Authorised
    $this->actingAs($adminUser);

    //Seed master_product.csv to create products
    $this->get(route('admin.update.master.materials.spreadsheet'));

    //Find product
    $products1 = findProducts("CHS 200nb (Ø219.1x6.4) 12m");
    $products2 = findProducts("CHS193.7*6.0");
    $products3 = findProducts("150nb (Ø168.3)");

    //#1 "CHS 200nb (Ø219.1x6.4) 12m"
    expect($products1->count())->toBe(1)
        ->and($products1[0]["nominal_width"])->toBe("200")
        ->and($products1[0]["actual_width"])->toBe("219.1")
        ->and($products1[0]["wall"])->toBe(6.4);

    //#2 "CHS193.7*6.0"
    expect($products2->count())->toBe(1)
        ->and($products2[0]["nominal_width"])->toBe("200")
        ->and($products2[0]["actual_width"])->toBe("193.7")
        ->and($products2[0]["wall"])->toBe(6.0);

    //#3 "150nb (Ø168.3)"
    expect($products3->count())->toBe(8)
        //1st result
        ->and($products3[0]["nominal_width"])->toBe("150")
        ->and($products3[0]["actual_width"])->toBe("165.1")
        ->and($products3[0]["wall"])->toBe(3.0)

        //2nd result
        ->and($products3[1]["nominal_width"])->toBe("150")
        ->and($products3[1]["actual_width"])->toBe("165.1")
        ->and($products3[1]["wall"])->toBe(3.5);
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


