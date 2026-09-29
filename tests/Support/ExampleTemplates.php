<?php

namespace Tests\Support;

use App\Enums\TemplateEnums;
use App\Enums\TemplateSourceEnums;
use App\Models\Business;
use App\Models\Template;

/**
 * The import templates the workbooks in public/examples were read with.
 *
 * Every cell below is a real cell in a file this repo ships, which is what lets
 * ExampleMaterialListsTest assert the exact rows each example extracts to - move the sub qty
 * column by one and the import quietly starts multiplying by the Rate column instead.
 *
 * These began as config/TableTemplates.php, matched against every business because the file was
 * global, and were briefly recorded onto every business for the same reason. No business is given
 * any template now: one customer's export settings are not the next customer's, and an admin
 * records a template per report a customer actually sends in. So they live here, as the fixture
 * for the tests that need something to import with, rather than in app/.
 *
 * recordFor() is idempotent - source and name identify a row within a business.
 */
class ExampleTemplates
{
    /**
     * Record all five examples against this business, whichever business it is.
     *
     * "Project Quote" was scoped to the admin's own email domain when these were handed out for
     * real, because a heading run of Length/Width/SubQty/Rate/Total is four ordinary words and one
     * distinctive one. Nothing is handed out now, so a test asks for what it wants to import with.
     */
    public function recordFor(Business $business): void
    {
        foreach ($this->definitions() as $definition) {
            Template::query()->updateOrCreate(
                [
                    'business_id' => $business->id,
                    'source' => $definition['source'],
                    'name' => $definition['name'],
                ],
                $definition,
            );
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function definitions(): array
    {
        return [
            /*
             * Tekla custom report, ConTekServices.com.au. All four were "ownerDomain => null" -
             * for everybody - because a Tekla export looks the same whoever exports it.
             */
            [
                'name' => 'Assembly List',
                'source' => TemplateSourceEnums::TEKLA->value,
                'type' => TemplateEnums::CAD_BILL_OF_MATERIALS->value,
                'web_source' => 'https://www.tekconservices.com.au/_files/ugd/c061a1_1d2d677884e24c5f9b54801278710ea3.pdf',
                'heading_cell' => 'A6',
                'expected_heading_labels' => ['Mark', 'Qty', 'Profile', 'Name', 'Finish', 'Length (mm)', 'Unit Area (m2)', 'Unit Weight (kg)'],
                'first_description_cell' => 'D7',
                'first_material_cell' => null,
                'first_grade_cell' => null,
                'first_surface_cell' => 'O7',
                'first_length_required_cell' => 'S7',
                'first_width_required_cell' => null,
                'first_sub_qty_cell' => 'B7',
                'skip_or_finish_check_cell' => 'A7',
                'should_skip_row' => null,
                //Ends on two consecutive blank cells in the check column
                'is_last_data_row' => null,
                'compound_description_prefix' => null,
                'compound_description_suffix' => null,
                'compound_description_cells' => null,
                'assembly_mark_rule' => 'COLUMN',
                'assembly_mark_cell' => 'A7',
                'length_width_units' => 'mm',
                'screenshot' => null,
                'active' => true,
            ],
            [
                'name' => 'Hot Rolled, Angles, and more.',
                'source' => TemplateSourceEnums::TEKLA->value,
                'type' => TemplateEnums::CAD_BILL_OF_MATERIALS->value,
                'web_source' => 'https://www.tekconservices.com.au/_files/ugd/c061a1_1d2d677884e24c5f9b54801278710ea3.pdf',
                'heading_cell' => 'B5',
                'expected_heading_labels' => ['Profile', 'Grade', 'Part Mark', 'Qty', 'Length[mm]', 'Unit Area (m2)', 'Total Area (m2)', 'Unit Weight (kg)', 'Total Weight (kg)'],
                'first_description_cell' => 'B6',
                'first_material_cell' => null,
                'first_grade_cell' => 'D6',
                'first_surface_cell' => null,
                'first_length_required_cell' => 'L6',
                'first_width_required_cell' => null,
                'first_sub_qty_cell' => 'I6',
                'skip_or_finish_check_cell' => 'B6',
                'should_skip_row' => 'Subtotal',
                'is_last_data_row' => 'Total',
                'compound_description_prefix' => null,
                'compound_description_suffix' => null,
                'compound_description_cells' => null,
                'assembly_mark_rule' => 'COLUMN',
                'assembly_mark_cell' => 'G6',
                'length_width_units' => 'mm',
                'screenshot' => null,
                'active' => true,
            ],
            /*
             * The bolt summaries have no description column. The importer builds one out of the
             * bolt's diameter, grade and length - "M20 8.8 65mm" - which is what the compound
             * description cells are.
             */
            [
                'name' => 'Bolt Summary - top',
                'source' => TemplateSourceEnums::TEKLA->value,
                'type' => TemplateEnums::CAD_BILL_OF_MATERIALS->value,
                'web_source' => 'https://www.tekconservices.com.au/_files/ugd/c061a1_1d2d677884e24c5f9b54801278710ea3.pdf',
                'heading_cell' => 'A7',
                'expected_heading_labels' => ['Bolt Dia', 'Bolt Grade', 'Length(mm)', 'Qty', 'Comments'],
                'first_description_cell' => null,
                'first_material_cell' => null,
                'first_grade_cell' => 'E8',
                'first_surface_cell' => null,
                'first_length_required_cell' => 'M8',
                'first_width_required_cell' => 'A8',
                'first_sub_qty_cell' => 'P8',
                'skip_or_finish_check_cell' => 'A8',
                'should_skip_row' => null,
                'is_last_data_row' => 'Bolt Dia',
                'compound_description_prefix' => 'M',
                'compound_description_suffix' => 'mm',
                'compound_description_cells' => ['A8', 'E8', 'M8'],
                //Every row's mark comes from one cell above the table rather than from a column
                'assembly_mark_rule' => 'FIXED',
                'assembly_mark_cell' => 'F4',
                'length_width_units' => 'mm',
                'screenshot' => null,
                'active' => true,
            ],
            [
                'name' => 'Bolt Summary - bottom',
                'source' => TemplateSourceEnums::TEKLA->value,
                'type' => TemplateEnums::CAD_BILL_OF_MATERIALS->value,
                'web_source' => 'https://www.tekconservices.com.au/_files/ugd/c061a1_1d2d677884e24c5f9b54801278710ea3.pdf',
                'heading_cell' => 'A6',
                'expected_heading_labels' => ['Bolt Dia', 'Profile', 'Name', 'Length(mm)', 'Qty', 'Finish'],
                'first_description_cell' => null,
                'first_material_cell' => null,
                'first_grade_cell' => null,
                'first_surface_cell' => null,
                'first_length_required_cell' => 'P7',
                'first_width_required_cell' => 'A7',
                'first_sub_qty_cell' => 'T7',
                'skip_or_finish_check_cell' => 'A7',
                'should_skip_row' => null,
                'is_last_data_row' => null,
                'compound_description_prefix' => 'M',
                'compound_description_suffix' => 'mm',
                'compound_description_cells' => ['A7', 'M7', 'P7'],
                'assembly_mark_rule' => 'NONE',
                'assembly_mark_cell' => null,
                'length_width_units' => 'mm',
                'screenshot' => null,
                'active' => true,
            ],
            /*
             * A hand-written project quote, from the Google Sheets example in public/examples.
             * The config gave it "ownerDomain => gmail.com", which is the admin's own email
             * domain and so the admin's own business - it is the demo for material_list.xlsx,
             * the file the README tells you to upload first, not a customer's report.
             */
            [
                'name' => 'Project Quote',
                'source' => TemplateSourceEnums::PROJECT_MANAGER->value,
                'type' => TemplateEnums::PROJECT_QUOTE->value,
                'web_source' => 'https://docs.google.com/spreadsheets/d/1NVv5x0np2qhD4vrLQEb2csr9DLYQCT8i-PofZmuD4pU/edit?gid=0#gid=0',
                'heading_cell' => 'D25',
                //Left of its own heading run, which a relative offset of -2 is
                'first_description_cell' => 'B27',
                'expected_heading_labels' => ['Length', 'Width', 'SubQty', 'Rate', 'Total'],
                'first_material_cell' => null,
                'first_grade_cell' => null,
                'first_surface_cell' => null,
                'first_length_required_cell' => 'D27',
                'first_width_required_cell' => 'E27',
                'first_sub_qty_cell' => 'F27',
                'skip_or_finish_check_cell' => 'B27',
                'should_skip_row' => null,
                'is_last_data_row' => null,
                'compound_description_prefix' => null,
                'compound_description_suffix' => null,
                'compound_description_cells' => null,
                'assembly_mark_rule' => 'FIXED',
                'assembly_mark_cell' => 'B24',
                'length_width_units' => 'm',
                'screenshot' => null,
                'active' => true,
            ],
        ];
    }
}
