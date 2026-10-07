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
     * Record every example against this business, whichever business it is.
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
             * Tekla's stock Material_List report, which bands its rows by section and subtotals
             * each band.
             *
             * RECONSTRUCTED, and the only example here that is. The others are customer files; this
             * one was rebuilt cell by cell from a PRINT of the report - a PDF nobody can import -
             * so every value below is read off that page and the SHAPE around them is a reading of
             * it. Two things the print cannot settle are the two that matter: the subtotal line is
             * indented under Grade rather than under Profile, which is taken to mean the Profile
             * cell is empty on it, and the white space around each subtotal is taken to be blank
             * rows. A customer's real export may differ on both, so treat a failure here as a
             * question about this file before taking it as a question about the importer.
             *
             * That shape is the point. A blank Profile cell makes a subtotal line read as a gap,
             * and a band of ONE row between two gaps used to end the table - see
             * CsvService::isLastDataRowNoDataBelow(), and GroupedTableEndTest, which asserts the
             * same thing against all three shapes the report could arrive in. "Hot Rolled" above is
             * the same report with its subtotals in the Profile column, where they are a row of the
             * table rather than a gap, which is why it was never affected and why its template
             * names "Subtotal" and "Total" and this one names neither.
             */
            [
                'name' => 'Material List',
                'source' => TemplateSourceEnums::TEKLA->value,
                'type' => TemplateEnums::CAD_BILL_OF_MATERIALS->value,
                'web_source' => null,
                'heading_cell' => 'A6',
                'expected_heading_labels' => ['Profile', 'Grade', 'Qty', 'Length(mm)', 'Area(m2)', 'Weight(kg)'],
                'first_description_cell' => 'A7',
                'first_material_cell' => null,
                'first_grade_cell' => 'B7',
                'first_surface_cell' => null,
                'first_length_required_cell' => 'D7',
                'first_width_required_cell' => null,
                'first_sub_qty_cell' => 'C7',
                'skip_or_finish_check_cell' => 'A7',
                //Both blank: a subtotal line is empty in the check column, so it is a gap, not a word
                'should_skip_row' => null,
                'is_last_data_row' => null,
                'compound_description_prefix' => null,
                'compound_description_suffix' => null,
                'compound_description_cells' => null,
                //The report carries no mark of any kind - it is a list of material, not of parts
                'assembly_mark_rule' => 'NONE',
                'assembly_mark_cell' => null,
                'length_width_units' => 'mm',
                'screenshot' => null,
                'active' => true,
            ],
            /*
             * The same report printed the other way up: a Tekcon assembly list whose Area and
             * Weight are TOTALS for the quantity rather than the unit figures "Assembly List"
             * above carries, and whose Finish sits past them at the right-hand edge instead of
             * in front of the length.
             *
             * RECONSTRUCTED, like "Material List" below, and for the same reason - the customer
             * sent a print. The values are read off public/examples/tekla_assembly_list_totals.png
             * and the shape around them is a reading of it: the columns are taken to be adjacent
             * because a print cannot say how far apart they sat, where the real export of
             * "Assembly List" spreads the same seven columns across A to W. So a failure here is a
             * question about this file before it is a question about the importer.
             *
             * What the file is here to hold is the footer. "Total for 18 assemblies:" sits in the
             * PROFILE column, directly under the last assembly with no blank row in front of it,
             * so the only thing between it and the material list is its empty Ass Mk cell. Nothing
             * names it the way "Hot Rolled" names "Total" - the table ends because the check
             * column ran out and nothing below reads as a material.
             */
            [
                'name' => 'Assembly List - totals',
                'source' => TemplateSourceEnums::TEKLA->value,
                'type' => TemplateEnums::CAD_BILL_OF_MATERIALS->value,
                'web_source' => null,
                'heading_cell' => 'A6',
                'expected_heading_labels' => ['Ass Mk', 'Qty', 'Profile', 'Length (mm)', 'Area (m2)', 'Weight (kg)', 'Finish'],
                'first_description_cell' => 'C7',
                'first_material_cell' => null,
                //The report names no grade at all, which is the whole of what it costs - see the test
                'first_grade_cell' => null,
                'first_surface_cell' => 'G7',
                'first_length_required_cell' => 'D7',
                'first_width_required_cell' => null,
                'first_sub_qty_cell' => 'B7',
                'skip_or_finish_check_cell' => 'A7',
                'should_skip_row' => null,
                //Ends where the Ass Mk column runs out, which is the total line
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
