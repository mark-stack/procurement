<?php

namespace Database\Seeders;

use App\Enums\GradeEnums;
use App\Enums\MaterialEnums;
use App\Enums\ProductEnums;
use App\Enums\SurfaceEnums;
use App\Formatters\TestingFormatter;
use App\Formatters\UniqueLetterIDGenerator;
use App\Models\Batch;
use App\Models\Business;
use App\Models\Offcut;
use App\Models\Product;
use App\Models\Project;
use App\Models\User;
use App\Services\DataClassificationService;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        //Services
        $testingFormatter = new TestingFormatter;
        $dataClassificationService = new DataClassificationService;

        /**
         * Platform product catalogue
         *
         * First, and not optional. Every BOM import classifies its lines against this table, so an
         * empty one makes an import extract nothing at all without saying why. It used to be filled
         * by an admin button nobody knew they had to press.
         */
        $this->call(MasterMaterialsSeeder::class);

        /**
         * Admin
         */
        //User
        $adminUser = User::factory()->create([
            'name' => 'Mark',
            'email' => config('env.admin_email'),
            "password" => bcrypt("Password123#")
        ]);
        //Business
        $adminBusiness = Business::create([
            'name' => null,
            'domain' => $adminUser->getDomainFromEmail(),
            'admin_setup_complete' => true,
        ]);
        $adminUser->business_id = $adminBusiness->id;
        $adminUser->save();

        /**
         * Sample data
         */
        /*
         * Business
         */
        $sampleBusiness = Business::create([
            'name' => "SAMPLE",
            'domain' => 'sample.com',
            'admin_setup_complete' => false,
        ]);

        /*
         * User2
         */
        $sampleUser_1 = User::factory()->create([
            'name' => 'John',
            'email' => 'john@sample.com',
            "business_id" => $sampleBusiness->id,
        ]);

        $sampleUser_2 = User::factory()->create([
            'name' => 'Karen',
            'email' => 'karen@sample.com',
            "business_id" => $sampleBusiness->id,
        ]);

        /*
         * Project
         * Test project with offcuts of 75x50x2.5 RHS (as found in "minimal scope" Excel)
         */
        $sampleProject_1 = Project::create([
            'name' => "Silo access platforms",
            'user_id' => $sampleUser_1->id,
        ]);
        $sampleProject_2 = Project::create([
            'name' => "Silica conveyor",
            'user_id' => $sampleUser_2->id,
        ]);

        /*
         * Batches
         */
        $sampleBatch1 = Batch::create([
            "user_id" => $sampleUser_1->id,
        ]);
        $sampleBatch2 = Batch::create([
            "user_id" => $sampleUser_2->id,
        ]);

        /*
         * BOM
         */
        $nest_1 = [
            [7000, 2], //length,qty
            [1700, 7],
            [15000, 1],
            [900, 13],
        ];
        $sampleBOM_1 = $testingFormatter->sampleBOM($sampleProject_1, $dataClassificationService, $nest_1);

        $nest_2 = [
            [3800, 6], //length,qty
            [1200, 6],
        ];
        $sampleBOM_2 = $testingFormatter->sampleBOM($sampleProject_2, $dataClassificationService, $nest_2);

        /*
         * Pieces
         */
        $samplePieces_1 = $testingFormatter->createPieces($sampleBOM_1, $sampleProject_1, $dataClassificationService);
        $samplePieces_2 = $testingFormatter->createPieces($sampleBOM_2, $sampleProject_2, $dataClassificationService);

        /*
         * Offcuts (200PFC)
         */
        $markGenerator = new UniqueLetterIDGenerator;

        $sampleOffcut1 = Offcut::create([
            'batch_from_id' => $sampleBatch1->id,
            'batch_to_id' => null,
            'business_id' => $sampleBusiness->id,
            'piece_to_id' => null, //todo redundant?
            'product_category' => ProductEnums::PFC,
            'material' => MaterialEnums::PLAIN_CARBON_STEEL,
            'grade' => GradeEnums::GR300,
            'surface' => SurfaceEnums::NONE,
            'nominal_length' => null,
            'precise_length' => null,
            'nominal_width' => null,
            'precise_width' => null,
            'nominal_height' => 200,
            'precise_height' => null,
            'wall' => null,
            'length' => 2200,

            //One generator for both, so the second mark cannot repeat the first
            "unique_mark" => $markGenerator->generate(ProductEnums::PFC->value, $sampleBusiness->id),
        ]);

        $sampleOffcut2 = Offcut::create([
            'batch_from_id' => $sampleBatch1->id,
            'batch_to_id' => null,
            'business_id' => $sampleBusiness->id,
            'piece_to_id' => null, //todo redundant?
            'product_category' => ProductEnums::PFC,
            'material' => MaterialEnums::PLAIN_CARBON_STEEL,
            'grade' => GradeEnums::GR300,
            'surface' => SurfaceEnums::NONE,
            'nominal_length' => null,
            'precise_length' => null,
            'nominal_width' => null,
            'precise_width' => null,
            'nominal_height' => 200,
            'precise_height' => null,
            'wall' => null,
            'length' => 1750,

            "unique_mark" => $markGenerator->generate(ProductEnums::PFC->value, $sampleBusiness->id),
        ]);

        /**
         * Import templates
         *
         * Neither business gets any, the admin's own included. A business's uploads are matched
         * against its own templates and nothing else, so a seeded business can import nothing
         * until a template is recorded for it - which is the real workflow, and worth meeting on
         * a seeded database rather than for the first time on a customer's account: the customer
         * emails us the reports they export, and we record one template per report on
         * /admin/businesses/{business}/templates.
         *
         * public/examples holds a workbook per report format we have read, and
         * ExampleMaterialListsTest carries the template each one was calibrated against, if you
         * want a worked example to record by hand.
         */
    }
}
