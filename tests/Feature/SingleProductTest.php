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
        'name' => 'marko',
        'domain' => 'marko.com',
        'admin_setup_complete' => true,
    ]);

    //Admin user
    return User::factory()->create([
        'name' => 'Mark',
        'email' => config('env.admin_email'),
        'business_id' => $adminBusiness->id,
    ]);
}

function findProducts(string $description): array
{
    $dataClassificationService = new DataClassificationService;

    //PRODUCT
    $product = $dataClassificationService->findProductConfigFromText($description);

    $generalProductMatches = collect([]);

    //Has product
    if ($product) {
        //MATERIAL
        $materialEnum = $dataClassificationService->findMaterial($product, $description);

        //GRADE
        $gradesEnums = $dataClassificationService->findGrades($product, $description);

        //SURFACE
        $surfaceEnum = $dataClassificationService->findSurface($product, $description, $gradesEnums);

        //NOMINAL UNITS
        $measurementUnitEnum = $dataClassificationService->findMeasurementUnit($product);

        //NOMINAL LENGTH
        $nominalLengthInt = $dataClassificationService->findNumberByRegex($product, $description, 'nominalLengthRegex');

        //NOMINAL WIDTH
        $nominalWidthInt = $dataClassificationService->findNumberByRegex($product, $description, 'nominalWidthRegex');

        //NOMINAL HEIGHT
        $nominalHeightInt = $dataClassificationService->findNumberByRegex($product, $description, 'nominalHeightRegex');

        //WALL
        $wall = $dataClassificationService->findNumberByRegex($product, $description, 'wallRegex');

        //WEIGHT
        $kg_per_m = $dataClassificationService->findNumberByRegex($product, $description, 'weightRegex');

        //        dd([
        //            "text" => $description,
        //            "uncertainLengthFloat" => $nominalLengthInt,
        //            "uncertainWidthFloat" => $nominalWidthInt,
        //            "uncertainHeightFloat" => $nominalHeightInt,
        //            "wall" => $wall,
        //            "kg_per_m" => $kg_per_m,
        //            "gradesEnums" => $gradesEnums,
        //        ]);

        //Price book search
        $user = auth()->user();
        $generalProductMatches = $dataClassificationService->findGeneralProductMatches(
            $user,
            $product['productCategory'],
            $materialEnum,
            $gradesEnums,
            $surfaceEnum,
            $measurementUnitEnum,
            $nominalLengthInt,
            $nominalWidthInt,
            $nominalHeightInt,
            $wall,
            $kg_per_m,
        );
    }

    return $generalProductMatches["results"];
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
    $products = findProducts('75PFC 9m');

    //Test
    expect($products[0]['product_category'])->toBe(ProductEnums::PFC->value)
        ->and($products[0]['material'])->toBe(MaterialEnums::PLAIN_CARBON_STEEL->value)
        ->and($products[0]['grade'])->toBe(GradeEnums::GR300->value)
        ->and($products[0]['nominal_height'])->toBe('75');
});

test('that "PLT10(asterix)160" finds GR250 and GR350', function () {
    //Create admin
    $adminUser = createAdmin();

    //Authorised
    $this->actingAs($adminUser);

    //Seed master_product.csv to create products
    $this->get(route('admin.update.master.materials.spreadsheet'));

    //Find product
    $products = findProducts('PLT10*160');

    //2 results in 2 grades
    expect($products[0]['product_category'])->toBe(ProductEnums::PLATE->value)
        ->and($products[0]['nominal_height'])->toBe('10')
        ->and($products[0]['grade'])->toBe(GradeEnums::GR250->value)
        ->and($products[1]['grade'])->toBe(GradeEnums::GR350->value);
});

test('that "150PFC 9000mm" finds exact product', function () {
    //Create admin
    $adminUser = createAdmin();

    //Authorised
    $this->actingAs($adminUser);

    //Seed master_product.csv to create products
    $this->get(route('admin.update.master.materials.spreadsheet'));

    //Find product
    $products = findProducts('150PFC 9000mm');

    //Test
    expect($products[0]['product_category'])->toBe(ProductEnums::PFC->value)
        ->and($products[0]['material'])->toBe(MaterialEnums::PLAIN_CARBON_STEEL->value)
        ->and($products[0]['grade'])->toBe(GradeEnums::GR300->value)
        ->and($products[0]['surface'])->toBe(SurfaceEnums::NONE->value)
        ->and($products[0]['nominal_height'])->toBe('150');
});

test('that "UB460(asterix)67" finds exact product', function () {
    //Create admin
    $adminUser = createAdmin();

    //Authorised
    $this->actingAs($adminUser);

    //Seed master_product.csv to create products
    $this->get(route('admin.update.master.materials.spreadsheet'));

    //Find product
    $products = findProducts('UB460*67');

    //Test
    expect($products[0]['product_category'])->toBe(ProductEnums::UB->value)
        ->and($products[0]['material'])->toBe(MaterialEnums::PLAIN_CARBON_STEEL->value)
        ->and($products[0]['grade'])->toBe(GradeEnums::GR300->value)
        ->and($products[0]['nominal_height'])->toBe('460');
});

test('that "90X63 LVL 7 meters" finds exact product', function () {
    //Create admin
    $adminUser = createAdmin();

    //Authorised
    $this->actingAs($adminUser);

    //Seed master_product.csv to create products
    $this->get(route('admin.update.master.materials.spreadsheet'));

    //Find product
    $products = findProducts('90X63 LVL 7 meters');

    //Test
    expect($products[0]['product_category'])->toBe(ProductEnums::LVL->value)
        ->and($products[0]['material'])->toBe(MaterialEnums::TIMBER->value)
        ->and($products[0]['grade'])->toBe(GradeEnums::E13->value)
        ->and($products[0]['nominal_height'])->toBe('90')
        ->and($products[0]['nominal_width'])->toBe('63');
});

test('that "M12 Allthread" finds exact product', function () {
    //Create admin
    $adminUser = createAdmin();

    //Authorised
    $this->actingAs($adminUser);

    //Seed master_product.csv to create products
    $this->get(route('admin.update.master.materials.spreadsheet'));

    //Find product
    $products = findProducts('M12 Allthread');

    //1 result
    expect(count($products))->toBe(1);

    //Product specs
    $product = $products[0];
    expect($product['product_category'])->toBe(ProductEnums::ALLTHREAD->value)
        ->and($product['material'])->toBe(MaterialEnums::PLAIN_CARBON_STEEL->value)
        ->and($product['grade'])->toBe(GradeEnums::GR_4_6->value)
        ->and($product['surface'])->toBe(SurfaceEnums::GALVANISED->value)
        ->and($product['nominal_width'])->toBe('12');
});

test('that "M12 CHEMICAL ANCHOR 180mm" finds exact product', function () {
    //Create admin
    $adminUser = createAdmin();

    //Authorised
    $this->actingAs($adminUser);

    //Seed master_product.csv to create products
    $this->get(route('admin.update.master.materials.spreadsheet'));

    //Find product
    $products = findProducts('M12 CHEMICAL ANCHOR 180mm');

    //Test
    expect($products[0]['product_category'])->toBe('ANCHOR_STUD')
        ->and($products[0]['material'])->toBe(MaterialEnums::PLAIN_CARBON_STEEL->value)
        ->and($products[0]['grade'])->toBe(GradeEnums::GR_5_8->value)
        ->and($products[0]['surface'])->toBe('ZINC')
        ->and($products[0]['nominal_width'])->toBe('12')
        ->and($products[0]['nominal_length'])->toBe('180');
});

test('that "M12 8.8S 30mm" finds exact product', function () {
    //Create admin
    $adminUser = createAdmin();

    //Authorised
    $this->actingAs($adminUser);

    //Seed master_product.csv to create products
    $this->get(route('admin.update.master.materials.spreadsheet'));

    //Find product
    $products = findProducts('M12 8.8S 30mm');

    //Test
    expect($products[0]['product_category'])->toBe('HEX_BOLT')
        //1st result
        ->and($products[0]['material'])->toBe(MaterialEnums::PLAIN_CARBON_STEEL->value)
        ->and($products[0]['grade'])->toBe(GradeEnums::GR_8_8->value)
        ->and($products[0]['nominal_width'])->toBe('12')
        ->and($products[0]['nominal_length'])->toBe('30')
        ->and($products[0]['surface'])->toBe('ZINC')
        //2nd result
        ->and($products[1]['material'])->toBe(MaterialEnums::PLAIN_CARBON_STEEL->value)
        ->and($products[1]['grade'])->toBe(GradeEnums::GR_8_8->value)
        ->and($products[1]['nominal_width'])->toBe('12')
        ->and($products[1]['nominal_length'])->toBe('30')
        ->and($products[1]['surface'])->toBe(SurfaceEnums::GALVANISED->value);
});

test('that "M16 4.6S 45mm" finds exact product', function () {
    //Create admin
    $adminUser = createAdmin();

    //Authorised
    $this->actingAs($adminUser);

    //Seed master_product.csv to create products
    $this->get(route('admin.update.master.materials.spreadsheet'));

    //Find product
    $products = findProducts('M16 4.6S 45mm');

    //1 result
    expect(count($products))->toBe(1);

    //Product specs
    $product = $products[0];
    expect($product['product_category'])->toBe('HEX_BOLT')
        ->and($product['material'])->toBe(MaterialEnums::PLAIN_CARBON_STEEL->value)
        ->and($product['grade'])->toBe(GradeEnums::GR_4_6->value)
        ->and($product['surface'])->toBe(SurfaceEnums::ZINC->value)
        ->and($product['nominal_width'])->toBe('16')
        ->and($product['nominal_length'])->toBe('45');
});

test('that "M20 12.9_CSK 45mm" finds exact product', function () {
    //Create admin
    $adminUser = createAdmin();

    //Authorised
    $this->actingAs($adminUser);

    //Seed master_product.csv to create products
    $this->get(route('admin.update.master.materials.spreadsheet'));

    //Find product
    $products = findProducts('M20 12.9_CSK 45mm');

    //1 result
    expect(count($products))->toBe(1);

    //Product specs
    $product = $products[0];
    expect($product['product_category'])->toBe('CSK_BOLT')
        ->and($product['material'])->toBe(MaterialEnums::PLAIN_CARBON_STEEL->value)
        ->and($product['grade'])->toBe(GradeEnums::GR_12_9->value)
        ->and($product['surface'])->toBe(SurfaceEnums::ZINC->value)
        ->and($product['nominal_width'])->toBe('20')
        ->and($product['nominal_length'])->toBe('45');
});

test('that "M20x500 D20 ANCHOR ROD" finds exact product', function () {
    //Create admin
    $adminUser = createAdmin();

    //Authorised
    $this->actingAs($adminUser);

    //Seed master_product.csv to create products
    $this->get(route('admin.update.master.materials.spreadsheet'));

    //Find product
    $products = findProducts('M20x500 D20 ANCHOR ROD');

    //1 result
    expect(count($products))->toBe(1);

    //Product specs
    $product = $products[0];
    expect($product['product_category'])->toBe('ANCHOR_STUD')
        ->and($product['material'])->toBe(MaterialEnums::PLAIN_CARBON_STEEL->value)
        ->and($product['grade'])->toBe(GradeEnums::GR_5_8->value)
        ->and($product['surface'])->toBe(SurfaceEnums::GALVANISED->value)
        ->and($product['nominal_width'])->toBe('20')
        ->and($product['nominal_length'])->toBe('500');
});

test('that "M20 M20_NUT NUT" finds exact product', function () {
    //Create admin
    $adminUser = createAdmin();

    //Authorised
    $this->actingAs($adminUser);

    //Seed master_product.csv to create products
    $this->get(route('admin.update.master.materials.spreadsheet'));

    //Find product
    $products = findProducts('M20 M20_NUT NUT');

    //1 result
    expect(count($products))->toBe(1);

    //Product specs
    $product = $products[0];
    expect($product['product_category'])->toBe(ProductEnums::NUT->value)
        ->and($product['material'])->toBe(MaterialEnums::PLAIN_CARBON_STEEL->value)
        ->and($product['nominal_width'])->toBe('20');
});

test('that "M20 x 65" finds exact products', function () {
    //Create admin
    $adminUser = createAdmin();

    //Authorised
    $this->actingAs($adminUser);

    //Seed master_product.csv to create products
    $this->get(route('admin.update.master.materials.spreadsheet'));

    //Find product
    $products = findProducts('M20 x 65');

    //2 results (HDG and Zinc)
    expect($products[0]['product_category'])->toBe(ProductEnums::HEX_BOLT->value)
        ->and($products[0]['nominal_width'])->toBe('20')
        ->and($products[0]['nominal_length'])->toBe('65')
        ->and($products[0]['grade'])->toBe(GradeEnums::GR_8_8->value)
        ->and($products[0]['surface'])->toBe(SurfaceEnums::GALVANISED->value)
        ->and($products[1]['surface'])->toBe(SurfaceEnums::ZINC->value);
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
    $products1 = findProducts('CHS 200nb (Ø219.1x6.4) 12m');
    $products2 = findProducts('CHS193.7*6.0');
    $products3 = findProducts('150nb (Ø168.3)');

    //#1 "CHS 200nb (Ø219.1x6.4) 12m"
    expect($products1[0]['product_category'])->toBe(ProductEnums::CHS->value)
        ->and($products1[0]['nominal_width'])->toBe('200')
        ->and($products1[0]['precise_width'])->toBe('219.1')
        ->and($products1[0]['wall'])->toBe(6.4);

    //#2 "CHS193.7*6.0"
    expect($products2[0]['product_category'])->toBe(ProductEnums::CHS->value)
        ->and($products2[0]['nominal_width'])->toBe('200')
        ->and($products2[0]['precise_width'])->toBe('193.7')
        ->and($products2[0]['wall'])->toBe(6.0);

    //#3 "150nb (Ø168.3)"
    expect($products3[0]['product_category'])->toBe(ProductEnums::CHS->value)
        //1st result
        ->and($products3[0]['nominal_width'])->toBe('150')
        ->and($products3[0]['precise_width'])->toBe('165.1')
        ->and($products3[0]['wall'])->toBe(3.0)

        //2nd result
        ->and($products3[1]['nominal_width'])->toBe('150')
        ->and($products3[1]['precise_width'])->toBe('165.1')
        ->and($products3[1]['wall'])->toBe(3.5);
});

test('that different UB weights are identified', function () {
    /**
     * 360 UB 56.7 and 360 UB 57
     * 360 UB 50.7 and 360 UB 51
     * 360 UB 44.7 and 360 UB 45
     * UB360*57
     */
    //Create admin
    $adminUser = createAdmin();

    //Authorised
    $this->actingAs($adminUser);

    //Seed master_product.csv to create products
    $this->get(route('admin.update.master.materials.spreadsheet'));

    //Find product
    $products1A = findProducts('360 UB 56.7');
    $products1B = findProducts('360 UB 57');
    $products1C = findProducts('UB360*57');

    $products2A = findProducts('360 UB 50.7');
    $products2B = findProducts('360 UB 51');

    $products3A = findProducts('360 UB 44.7');
    $products3B = findProducts('360 UB 45');

    //#1A "360 UB 56.7"
    expect($products1A[0]['product_category'])->toBe(ProductEnums::UB->value)
        ->and($products1A[0]['grade'])->toBe(GradeEnums::GR300->value)
        ->and($products1A[0]['nominal_height'])->toBe('360')
        ->and($products1A[0]['kg_per_m'])->toBe(56.7);

    //#1B "360 UB 57"
    expect($products1B[0]['product_category'])->toBe(ProductEnums::UB->value)
        ->and($products1B[0]['grade'])->toBe(GradeEnums::GR300->value)
        ->and($products1B[0]['nominal_height'])->toBe('360')
        ->and($products1B[0]['kg_per_m'])->toBe(56.7);

    //#1C "UB360*5"
    expect($products1C[0]['product_category'])->toBe(ProductEnums::UB->value)
        ->and($products1C[0]['grade'])->toBe(GradeEnums::GR300->value)
        ->and($products1C[0]['nominal_height'])->toBe('360')
        ->and($products1C[0]['kg_per_m'])->toBe(56.7);

    //#2A "360 UB 50.7"
    expect($products2A[0]['product_category'])->toBe(ProductEnums::UB->value)
        ->and($products2A[0]['grade'])->toBe(GradeEnums::GR300->value)
        ->and($products2A[0]['nominal_height'])->toBe('360')
        ->and($products2A[0]['kg_per_m'])->toBe(50.7);

    //#2B "360 UB 51"
    expect($products2B[0]['product_category'])->toBe(ProductEnums::UB->value)
        ->and($products2B[0]['grade'])->toBe(GradeEnums::GR300->value)
        ->and($products2B[0]['nominal_height'])->toBe('360')
        ->and($products2B[0]['kg_per_m'])->toBe(50.7);

    //#3A "360 UB 44.7"
    expect($products3A[0]['product_category'])->toBe(ProductEnums::UB->value)
        ->and($products3A[0]['grade'])->toBe(GradeEnums::GR300->value)
        ->and($products3A[0]['nominal_height'])->toBe('360')
        ->and($products3A[0]['kg_per_m'])->toBe(44.7);

    //#3B "360 UB 45"
    expect($products3B[0]['product_category'])->toBe(ProductEnums::UB->value)
        ->and($products3B[0]['grade'])->toBe(GradeEnums::GR300->value)
        ->and($products3B[0]['nominal_height'])->toBe('360')
        ->and($products3B[0]['kg_per_m'])->toBe(44.7);
});

test('that different UC weights are identified', function () {
    /**
     * 250 UC 89.5
     * 250 UC 90
     * 250 UC 72.9
     * 250 UC 73
     * UC310*118
     */
    //Create admin
    $adminUser = createAdmin();

    //Authorised
    $this->actingAs($adminUser);

    //Seed master_product.csv to create products
    $this->get(route('admin.update.master.materials.spreadsheet'));

    //Find product
    $products1 = findProducts('250 UC 89.5');
    $products2 = findProducts('250 UC 90');
    $products3 = findProducts('250 UC 72.9');
    $products4 = findProducts('250 UC 73');
    $products5 = findProducts('UC310*118');

    //#1 "250 UC 89.5"
    expect($products1[0]['product_category'])->toBe(ProductEnums::UC->value)
        ->and($products1[0]['material'])->toBe(MaterialEnums::PLAIN_CARBON_STEEL->value)
        ->and($products1[0]['grade'])->toBe(GradeEnums::GR300->value)
        ->and($products1[0]['nominal_height'])->toBe('250')
        ->and($products1[0]['kg_per_m'])->toBe(89.5);

    //#2 "250 UC 90"
    expect($products2[0]['product_category'])->toBe(ProductEnums::UC->value)
        ->and($products2[0]['material'])->toBe(MaterialEnums::PLAIN_CARBON_STEEL->value)
        ->and($products2[0]['grade'])->toBe(GradeEnums::GR300->value)
        ->and($products2[0]['nominal_height'])->toBe('250')
        ->and($products2[0]['kg_per_m'])->toBe(89.5);

    //#3 "250 UC 72.9"
    expect($products3[0]['product_category'])->toBe(ProductEnums::UC->value)
        ->and($products3[0]['material'])->toBe(MaterialEnums::PLAIN_CARBON_STEEL->value)
        ->and($products3[0]['grade'])->toBe(GradeEnums::GR300->value)
        ->and($products3[0]['nominal_height'])->toBe('250')
        ->and($products3[0]['kg_per_m'])->toBe(72.9);

    //#4 "250 UC 73"
    expect($products4[0]['product_category'])->toBe(ProductEnums::UC->value)
        ->and($products4[0]['material'])->toBe(MaterialEnums::PLAIN_CARBON_STEEL->value)
        ->and($products4[0]['grade'])->toBe(GradeEnums::GR300->value)
        ->and($products4[0]['nominal_height'])->toBe('250')
        ->and($products4[0]['kg_per_m'])->toBe(72.9);

    //#5 "UC310*118"
    expect($products5[0]['product_category'])->toBe(ProductEnums::UC->value)
        ->and($products5[0]['material'])->toBe(MaterialEnums::PLAIN_CARBON_STEEL->value)
        ->and($products5[0]['grade'])->toBe(GradeEnums::GR300->value)
        ->and($products5[0]['nominal_height'])->toBe('310')
        ->and($products5[0]['kg_per_m'])->toBe(118.0);
});
//todo more
