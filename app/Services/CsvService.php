<?php

namespace App\Services;

use App\Models\Piece;
use App\Models\Project;
use App\Models\RawMaterialQuote;
use App\Models\Template;
use Exception;
use Illuminate\Http\RedirectResponse;

class CsvService
{
    public function csvToArray(string $path): array
    {
        /**
         * Single purpose: convert the CSV into array
         */

        $data = [];
        if (($handle = fopen(storage_path("app/private/{$path}"), 'r')) !== false) {
            while (($row = fgetcsv($handle, 1000, ',')) !== false) {
                $data[] = $row;
            }
            fclose($handle);
        }

        return $data;
    }

    public function processCsv(array $csvArray, Project $project): RedirectResponse
    {
        /**
         * Single purpose: detect the import category and direct to relevant post-processing
         * 1) Project quote
         * 2) Tekla CAD import todo
         * todo more
         */

        //Default return if unsuccessful
        $return = back()->with("warning","The file didn't auto-detect properly. Did the template change? Please email the file to mark.laravel.coder@gmail to have it re-calibrated");

        //1) Project quote
        $importCategory = $this->detectImportCategory($csvArray);
        if($importCategory === "PROJECT_QUOTE"){
            //Should have just 1 result
            $projectQuoteTemplatesDetected = $this->projectQuoteTemplatesDetected($csvArray,$project->user);

            if(count($projectQuoteTemplatesDetected) === 1){
                $projectQuoteTemplate = $projectQuoteTemplatesDetected[0];
                $this->projectQuoteTemplateProcessing($csvArray,$projectQuoteTemplate,$project);
                $return = back(); //ok
            }
        }
        //2) Tekla CAD import
        if($importCategory === "CAD_TEKLA"){
            $return = back(); //ok
        }
        //todo more

        return $return;
    }

    public function detectImportCategory(array $csvArray): string
    {
        /**
         * Single purpose: detect the import category
         * 1) Project quote
         * 2) CAD import todo
         */

        return "PROJECT_QUOTE"; //todo: needs multiple CAD types
    }


    public function projectQuoteTemplateProcessing(array $csvArray, object $projectQuoteTemplate, Project $project): void
    {
        /**
         * Single purpose: extract the materials via an admin-configured template
         */

        //Services
        $dataClassificationService = new DataClassificationService();

        //Clean the data (but no default assumptions yet)
        $cleanCsvData = $this->cleanCsvData($csvArray,$projectQuoteTemplate);

        //Sense checks
        //$productService->senseChecks(); //todo incomplete

        //Find price book products
        $dataWithProducts = $dataClassificationService->findProductsFromCleanData($cleanCsvData,$project->user);

        //Save user material list
        $cleanMaterialList = $this->saveRawMaterialQuoteData($dataWithProducts,$project);

        //Create new user-custom products
        //todo is incomplete
        $this->createUserCustomProducts($dataWithProducts,$project);
    }

    public function projectQuoteTemplatesDetected(array $data, object $projectUser): array
    {
        /**
         * Single purpose: detect which admin-configured template this is designed for
         */

        $projectQuoteTemplatesDetected = [];

        //admin sees all templates
        $authUser = auth()->user();
        $isAdmin = $authUser->isAdmin();

        $userTemplates = collect([]);
        if($isAdmin){
            $userTemplates = Template::all();
        }
        else{
            $userTemplates = Template::query()
                ->where("business_id",$projectUser->business->id)
                ->get();
        }

        //Check against each template
        foreach($userTemplates as $template){
            //Detects this template
            if($this->checkSingleTemplate($data,$template)){
                $projectQuoteTemplatesDetected[] = $template;
            }
        }

        return $projectQuoteTemplatesDetected;
    }

    public function checkSingleTemplate(array $data, object $template): bool
    {
        /**
         * Single purpose: Check if this CSV data matches this template
         */

        $result = false;
        try {
            /**
             * Random cell match #1
             */
            $randomCellSpreadSheetCoordinate_1 = $this->spreadsheetCoordinateToIndexes($template->random_cell_1);
            $colIndex_1 = $randomCellSpreadSheetCoordinate_1["column_index"];
            $rowIndex_1 = $randomCellSpreadSheetCoordinate_1["row_index"];
            $randomCellText_1 = $template->random_cell_text_1;
            $matchRandomCell_1 = strcasecmp($data[$rowIndex_1][$colIndex_1], $randomCellText_1) === 0;

            /**
             * Random cell match #2
             */
            $randomCellSpreadSheetCoordinate_2 = $this->spreadsheetCoordinateToIndexes($template->random_cell_2);
            $colIndex_2 = $randomCellSpreadSheetCoordinate_2["column_index"];
            $rowIndex_2 = $randomCellSpreadSheetCoordinate_2["row_index"];
            $randomCellText_2 = $template->random_cell_text_2;
            $matchRandomCell_2 = strcasecmp($data[$rowIndex_2][$colIndex_2], $randomCellText_2) === 0;

            $result = $matchRandomCell_1 && $matchRandomCell_2;
        }
        catch (Exception $e) {
            $result = false;
        }

        return $result;
    }

    public function spreadsheetCoordinateToIndexes(string $coordinate): array
    {
        /**
         * Single purpose: convert spreadsheet coordinate like "B5" to an X,Y index pair "1,4"
         */

        // Extract column part (letters)
        preg_match('/[A-Za-z]+/', $coordinate, $columnMatches);
        $columnString = $columnMatches[0];

        // Extract row part (numbers)
        preg_match('/\d+/', $coordinate, $rowMatches);
        $row = (int)$rowMatches[0];

        // Convert the column letters to a number (base-26)
        $columnNumber = 0;
        $columnLength = strlen($columnString);
        for ($i = 0; $i < $columnLength; $i++) {
            $columnNumber = $columnNumber * 26 + (ord(strtoupper($columnString[$i])) - ord('A') + 1);
        }

        return ['column_index' => ($columnNumber-1), 'row_index' => ($row-1)];
    }

    public function cleanCsvData(array $data, object $template): array
    {
        /**
         * Single purpose: clean & normalised the CSV data
         */
        $cleanData = [];

        //Get array of indexes from spreadsheet coordinates. e.g B3 to [2,1]
        $firstDescriptionIndexes = $this->spreadsheetCoordinateToIndexes($template->first_description_cell);
        $firstMaterialIndexes = $template->first_material_cell
            ? $this->spreadsheetCoordinateToIndexes($template->first_material_cell)
            : null; //"material" is optional
        $firstLengthRequiredIndexes = $template->first_length_required_cell
            ? $this->spreadsheetCoordinateToIndexes($template->first_length_required_cell)
            : null; //"length_required" is optional
        $firstWidthRequiredIndexes = $template->first_width_required_cell
            ? $this->spreadsheetCoordinateToIndexes($template->first_width_required_cell)
            : null; //"width_required" is optional
        $firstSubQtyIndexes = $this->spreadsheetCoordinateToIndexes($template->first_sub_qty_cell);
        $firstUnitRateIndexes = $this->spreadsheetCoordinateToIndexes($template->first_unit_rate_cell);
        $firstRowIndex = $firstDescriptionIndexes["row_index"];

        //get specific indexes
        $descriptionColumnIndex = $firstDescriptionIndexes["column_index"];
        $materialColumnIndex = $firstMaterialIndexes
            ? $firstMaterialIndexes["column_index"]
            : null; //"material" is optional
        $lengthRequiredColumnIndex = $firstLengthRequiredIndexes
            ? $firstLengthRequiredIndexes["column_index"]
            : null; //"length" is optional
        $widthRequiredColumnIndex = $firstWidthRequiredIndexes
            ? $firstWidthRequiredIndexes["column_index"]
            : null; //"width" is optional
        $subQtyColumnIndex = $firstSubQtyIndexes["column_index"];
        $unitRateColumnIndex = $firstUnitRateIndexes["column_index"];

        foreach($data as $index => $row){
            if($index >= $firstRowIndex){
                if($row[$descriptionColumnIndex] !== ""){
                    //Description
                    $description = $row[$descriptionColumnIndex];

                    //Material
                    $material = $materialColumnIndex
                        ? $row[$materialColumnIndex]
                        : null;

                    //Length required
                    $lengthRequired = $lengthRequiredColumnIndex
                        ? $this->normaliseLengthWidthRequired($row[$lengthRequiredColumnIndex],$template->length_width_units)
                        : null;

                    //Width required
                    $widthRequired = $widthRequiredColumnIndex
                        ? $this->normaliseLengthWidthRequired($row[$widthRequiredColumnIndex],$template->length_width_units)
                        : null;

                    //Sub qty
                    $subQty = $this->getSubQty($row[$subQtyColumnIndex]);

                    //Unit rate
                    $unitRate = $this->getUnitRateDollars($row[$unitRateColumnIndex]);

                    $cleanData[] = [
                        "index" => $index,
                        "description" => $description,
                        "material" => $material,
                        "length_required" => $lengthRequired,
                        "width_required" => $widthRequired,
                        "sub_qty" => $subQty,
                        "unit_rate" => $unitRate,
                    ];
                }
            }
        }

        return $cleanData;
    }

    public function getSubQty(string $rawSubQty): float
    {
        /**
         * Single purpose: convert sub qty to a float. Also set "1" as default to avoid zero multiplication
         */

        $float = (float) $rawSubQty;
        return $float === 0 ? 1.0 : $float;
    }

    public function normaliseLengthWidthRequired(string $quantity, string $lengthWidthUnits): float
    {
        /**
         * Single purpose: extract just the number from string number representation.
         */

        $float = 1.0; //default
        $removeLetters = preg_replace('/[a-zA-Z]/', '', $quantity);
        $removeCurrencySymbols =preg_replace('/[€£¥₹$¢₱₽₩₦฿]/u', '', $removeLetters);

        if (is_numeric($removeCurrencySymbols)) {
            //If template length/width is METERS
            if($lengthWidthUnits === "m"){
                $float = (float) $removeCurrencySymbols;
            }
            //If template length/width is MILLIMETERS
            if($lengthWidthUnits === "mm"){
                $float = (float) ($removeCurrencySymbols/1000);
            }
        }

        return $float;
    }

    public function getUnitRateDollars(string $rawUnitRate): float
    {
        /**
         * Single purpose: extract float numbers from string
         */

        $result = 0;

        // Regular expression to match integers and floats
        $pattern = '/\b\d{1,3}(?:,\d{3})*(?:\.\d+)?|\b\d+(?:\.\d+)?\b/';

        // Perform regex match
        if (preg_match($pattern, $rawUnitRate, $matches)) {
            $result = $matches[0]; // Return the matched number
        }

        return (float) $result;
    }

    public function createUserCustomProducts(array $dataWithProducts, object $project): void
    {
        /**
         * Single purpose: create custom products not found in general price book
         * todo: incomplete
         */

        $user = $project->user;
        $business = $user->business;
        $domain = $user->getDomainFromEmail();

//        foreach($dataWithProducts as $row){
//            $userProductData = $row["product_custom_for_user"];//todo
//            if($userProductData !== null){
//
////                $userProductData
////                "index" => 27
////                "description" => "Steel Beams (I-Beams)"
////                "material" => null
////                "length_required" => 12.0
////                "sub_qty" => 2.0
////                "unit_rate" => 50.0
//
//                Product::create([
//                    "description" => $userProductData["description"],
//                    "product" => ProductEnums::PFC, //todo: let the user customise
//                    "material" => $userProductData["material"] ?? MaterialEnums::STEEL->value, //todo: let the user customise
//                    "grade" => GradeEnums::NONE->value, //todo: let the user customise
//                    "surface" => SurfaceEnums::NONE->value, //todo: let the user customise
//                    "nominal_units" => MeasurementUnitEnums::SINGLE->value, //todo: let the user customise
//                    "size" => 1, //todo: let the user customise
//                    "length" => $userProductData["length_required"], //todo: let the user customise
//                    "width" => $userProductData["width_required"], //todo: let the user customise
//                    "kg_per_m" => 1, //todo: let the user customise
//                    "baseline_unit_rate" => 1, //todo: let the user customise
//                    'domain' => $domain,
//                ]);
//            }
//        }
    }

    public function saveRawMaterialQuoteData($dataWithProducts,$project): array
    {
        /**
         * Single purpose:
         */

        //Services
        $dataClassificationService = new DataClassificationService();

        $materialList = [];
        foreach($dataWithProducts as $cleanRow){
            $productCategory = $dataClassificationService->findProduct($cleanRow["description"]);

            /**
             * Create 'RawMaterialQuote' item
             */
            $rawMaterialQuote = RawMaterialQuote::create([
                "csv_index" => $cleanRow["index"],
                "description" => $cleanRow["description"],
                "product_category" => $productCategory ? $productCategory["productEnum"]->value : null,
                "material" => $cleanRow["material"] ?? null,
                "nominal_units" => $dataClassificationService->findMeasurementUnit($productCategory),
                "length_required" => $cleanRow["length_required"],
                "width_required" => $cleanRow["width_required"],
                "sub_qty" => $cleanRow["sub_qty"],
                "unit_rate" => $cleanRow["unit_rate"],
                'project_id' => $project->id,
                "general_product_matches" => serialize($cleanRow["generalProductMatches"]),
            ]);

            $materialList[] = $rawMaterialQuote;

            /**
             * Create 'Pieces'
             */
            if(count($cleanRow["generalProductMatches"]) === 1){
                $item = $cleanRow["generalProductMatches"][0];

                $piece = Piece::create([
                    'project_id' => $project->id,
                    "raw_material_quote_id" => $rawMaterialQuote->id,
                    "product" => $item["product"],
                    "material" => $item["material"],
                    "grade" => $item["grade"],
                    "surface" => $item["surface"],
                    "nominal_units" => $item["nominal_units"],
                    "nesting_algo" => (new NestingService())->getNestingLabelsFromProduct($item["product"])[0],
                    "nominal_length" => $item["nominal_length"] ?? null,
                    "nominal_width" => $item["nominal_width"] ?? null,
                    "nominal_height" => $item["nominal_height"] ?? null,
                    "actual_length" => $cleanRow["length_required"], //For singular items like bolts, this is "QTY" that's divisible.
                    "actual_width" => $cleanRow["width_required"],
                    "actual_qty" => $cleanRow["sub_qty"],
                ]);
            }
        }

        return $materialList;
    }
}
