<?php

namespace App\Services;

use App\Enums\MaterialEnums;
use App\Enums\MeasurementUnitEnums;
use App\Enums\GradeEnums;
use App\Enums\NestingEnums;
use App\Enums\ProductEnums;
use App\Enums\SurfaceEnums;
use App\Models\Business;
use App\Models\Piece;
use App\Models\Product;
use App\Models\RawMaterialQuote;
use App\Models\Template;
use App\Models\User;
use Exception;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;

class DataClassificationService
{
    public function findGeneralProductMatches(
        $user,
        $productString,
        $materialEnum,
        $gradesEnums,
        $surfaceEnum,
        $measurementUnitEnum,
        $nominalLengthInt,
        $nominalWidthInt,
        $nominalHeightInt,
    ): Collection
    {
        /**
         * Single purpose: find product matches independent of the length variations. e.g 200PFC
         */

        /**
         * Compare the limited attributes provided (grade, size, etc) against the master price book.
         *
         * NOTE: for METERAGE items, disregard length. e.g "150PFC STEEL GR300" disregards 9m,12m,etc.
         * NOTE: for AREA items, disregard length & width
         * NOTE: for BUNDLE items, disregard none
         */
        $return = collect([]); //default

        //"Product" is mandatory
        if($productString) {
            $algo = (new NestingService())->getNestingLabelsFromProduct($productString)[0];
            $sizeInclude = [];
            $query = null;

            //METERAGE
            if($algo === NestingEnums::METERAGE->value){
                $sizeInclude = ["nominal_width","nominal_height"];
                $query = Product::select('product', 'material', 'grade', 'surface', 'nominal_units','nominal_height')
                    ->distinct()
                    ->availableFor($user)
                    ->where("product", $productString);
            }
            //AREA
            if($algo === NestingEnums::AREA->value){
                $sizeInclude = ["nominal_height"];
                $query = Product::select('product', 'material', 'grade', 'surface', 'nominal_units', 'nominal_height')
                    ->distinct()
                    ->availableFor($user)
                    ->where("product", $productString);
            }
            //BUNDLE
            if($algo === NestingEnums::BUNDLE->value){
                $sizeInclude = ["nominal_length","nominal_width"];
                $query = Product::select('product', 'material', 'grade', 'surface', 'nominal_units', 'nominal_length','nominal_width')
                    ->distinct()
                    ->availableFor($user)
                    ->where("product", $productString);
            }

            //Material
            if (!is_null($materialEnum)) {
                $query->where("material", $materialEnum->value);
            }

            //Grade
            if (!is_null($gradesEnums)) {
                $gradesArrayValues = [];
                foreach($gradesEnums as $grade){
                    $gradesArrayValues[] = $grade->value;
                }
                $query->whereIn("grade",$gradesArrayValues);
            }

            //Surface
            if (!is_null($surfaceEnum)) {
                $query->where("surface", $surfaceEnum->value);
            }

            //Measurement Unit
            if (!is_null($measurementUnitEnum)) {
                $query->where("nominal_units", $measurementUnitEnum->value);
            }

            //Nominal length
            if (!is_null($nominalLengthInt) && in_array("nominal_length",$sizeInclude)) {
                $query->where("nominal_length", $nominalLengthInt);
            }

            //Nominal width
            if (!is_null($nominalWidthInt) && in_array("nominal_width",$sizeInclude)) {
                $query->where("nominal_width", $nominalWidthInt);
            }

            //Nominal height
            if (!is_null($nominalHeightInt) && in_array("nominal_height",$sizeInclude)) {
                $query->where("nominal_height", $nominalHeightInt);
            }

            return $query->get();
        }

        return $return;
    }


    public function findProductsInRow(array $cleanCsvRow, User $user): array
    {
        /**
         * Single purpose:
         */

//        $cleanCsvRow
//        "index" => 27
//        "description" => "Steel Beams (I-Beams)"
//        "material" => null
//        "nominal_units" => null
//        "length_required" => 12.0
//        "sub_qty" => 2.0
//        "unit_rate" => 50.0

        //Product (category like "PFC")
        $productCategory = $this->findProduct($cleanCsvRow["description"]);

        //Has product
        $generalProductMatches = collect([]);
        $materialEnum = null;
        $gradesEnums = null;
        $surfaceEnum = null;
        $measurementUnitEnum = null;
        $nominalLengthInt = null;
        $nominalWidthInt = null;
        $nominalHeightInt = null;

        if($productCategory){
            //MATERIAL
            $materialEnum = $this->findMaterial($productCategory,$cleanCsvRow["description"]);

            //GRADE
            $gradesEnums = $this->findGrades($productCategory,$cleanCsvRow["description"]);

            //SURFACE
            $surfaceEnum = $this->findSurface($productCategory,$cleanCsvRow["description"],$gradesEnums); //todo this might be a column

            //NOMINAL UNITS
            $measurementUnitEnum = $this->findMeasurementUnit($productCategory);

            //NOMINAL LENGTH
            $nominalLengthInt = $this->findNominal($productCategory,$cleanCsvRow["description"],"nominalLengthRegex");

            //NOMINAL WIDTH
            $nominalWidthInt = $this->findNominal($productCategory,$cleanCsvRow["description"],"nominalWidthRegex");

            //NOMINAL HEIGHT
            $nominalHeightInt = $this->findNominal($productCategory,$cleanCsvRow["description"],"nominalHeightRegex");

            /**
             * Price book search
             */
            $generalProductMatches = $this->findGeneralProductMatches(
                $user,
                $productCategory["productEnum"]->value,
                $materialEnum,
                $gradesEnums,
                $surfaceEnum,
                $measurementUnitEnum,
                $nominalLengthInt,
                $nominalWidthInt,
                $nominalHeightInt
            );
        }

        return [
            "cleanCsvRow" => $cleanCsvRow,
            "generalProductMatches" => $generalProductMatches->toArray(),
        ];
    }

    public function findProductsFromCleanData(array $cleanCsvData, User $user): array
    {
        /**
         * Single purpose:
         */

        $result = [];

        foreach($cleanCsvData as $cleanCsvRow){
            $productsInRow = $this->findProductsInRow($cleanCsvRow,$user);

            $append = $cleanCsvRow;
            $append["generalProductMatches"] = $productsInRow["generalProductMatches"];
            $result[] = $append;
        }

        return $result;
    }

    public function findProduct(string $text): ?array
    {
        /**
         * Single purpose: extract a 'product' from text. e.g "PFC"
         */

        $resultProduct = null;

        $products = [


//        "SHS", "square hollow section","square hollow sections",
//        "RHS", "rectangular hollow section","rectangular hollow sections",
//        "CHS", "circular hollow section","circular hollow sections",
//        "UBS", "Universal Beam Section","Universal Beam Sections",
//        "UCS", "Universal Column Section","Universal Column Sections",
//        "HSS","Hollow Structural Section","Hollow Structural Sections",
//        "EA", "equal angle","equal angles",
//        "Steel Angles","Steel Angles",
//        "UA", "unequal angle","unequal angles",
//        "RSJ", "rolled steel joist","rolled steel joists",
//        "Flat Bar","Flat Bars",
//        "round bar","round bars",
//        "Square Bar","Square Bars",
//        "Rebar","Reinforcement Bar","Reinforcement Bars",
//        "Threaded Rod","Threaded Rods","allthread",
//        "I-Beam","I-Beams",
//        "Steel Joist","Steel Joists",
//        "Steel Tube","Steel Tubes",
            //todo more


            //PFC
            [
                "productEnum" => ProductEnums::PFC,
                "productRegex" => [
                    "PFC",
                    "Parallel+\s+Flange+\s+Channel",
                    "Parallel+\s+Flanged+\s+Channel",
                    "steel+\s+channel",
                ],
                "nominalLengthRegex" => [

                ],
                "nominalWidthRegex" => [

                ],
                "nominalHeightRegex" => [
                    "(\d+)+PFC",          //200PFC
                    "(\d+)+\s+PFC",       //200 PFC
                    "(\d+)+mm+\s+PFC",    //200mm PFC
                    "(\d+)+\s+mm+\s+PFC", //200 mm PFC
                    "(\d+)+\s+mm+\s+Parallel Flange Channel",    //200 mm Parallel Flange Channel
                    "(\d+)+mm+\s+Parallel+\s+Flange+\s+Channel", //200 mm Parallel Flange Channel
                ],
                "measurementUnit" => MeasurementUnitEnums::MILLIMETERS,
                "defaultMaterial" => MaterialEnums::PLAIN_CARBON_STEEL,
            ],
            //UB
            [
                "productEnum" => ProductEnums::UB,
                "productRegex" => [
                    "\d+UB",
                    "\d+\s+UB",
                    "universal+\s+beam",
                    "steel+\s+beam",
                ],
                "nominalLengthRegex" => [

                ],
                "nominalWidthRegex" => [

                ],
                "nominalHeightRegex" => [
                    "(\d+)+UB",    //300UB
                    "(\d+)+\s+UB", //300 UB
                ],
                "measurementUnit" => MeasurementUnitEnums::MILLIMETERS,
                "defaultMaterial" => MaterialEnums::PLAIN_CARBON_STEEL,
            ],
            //UC
            [
                "productEnum" => ProductEnums::UC,
                "productRegex" => [
                    "\d+UC",
                    "\d+\s+UC",
                    "universal+\s+column",
                    "steel+\s+column",
                ],
                "nominalLengthRegex" => [

                ],
                "nominalWidthRegex" => [

                ],
                "nominalHeightRegex" => [
                    "(\d+)+UC",    //300UC
                    "(\d+)+\s+UC", //300 UC
                ],
                "measurementUnit" => MeasurementUnitEnums::MILLIMETERS,
                "defaultMaterial" => MaterialEnums::PLAIN_CARBON_STEEL,
            ],
            //Steel plate
            [
                "productEnum" => ProductEnums::PLATE,
                "productRegex" => [
                    "Plate",                //plate
                    "Plates",               //plates
                    "(\d+)+PL",             //20PL
                    "(\d+)+\s+PL",          //20 PL
                    "(\d+)+mm+\s+PL",       //20mm PL
                    "(\d+)+mm+\s+plate",    //20mm plate
                    "Steel+\s+Plate",       //Steel plate
                    "Steel+\s+Plates",      //steel plates
                ],
                "nominalLengthRegex" => [

                ],
                "nominalWidthRegex" => [
                    "(1200|1220|1800|2400|2440|3000|3200|1\.2|1\.8|1\.22|2\.4|2\.44|3\.0|3\.2)", //Find common plate widths in M or MM
                ],
                "nominalHeightRegex" => [
                    "\b(0|[1-9][0-9]?|1[0-4][0-9]|150) ?PL", //16PL or 16 PL
                    "\b(0|[1-9][0-9]?|1[0-4][0-9]|150) ?mm", //16mm or 16 mm
                ],
                "measurementUnit" => MeasurementUnitEnums::MILLIMETERS,
                "defaultMaterial" => MaterialEnums::PLAIN_CARBON_STEEL,
            ],
            //Bolts
            [
                "productEnum" => ProductEnums::BOLT,
                "productRegex" => [
                    "M+\d",
                    "bolt",
                ],
                "nominalLengthRegex" => [
                    "x+(\d+)",      //x100
                    "x+\s+(\d+)",   //x 100
                ],
                "nominalWidthRegex" => [
                    "M+(\d+)", //M16
                ],
                "nominalHeightRegex" => [

                ],
                "measurementUnit" => MeasurementUnitEnums::MILLIMETERS,
                "defaultMaterial" => MaterialEnums::PLAIN_CARBON_STEEL,
            ],
            //LVL
            [
                "productEnum" => ProductEnums::LVL,
                "productRegex" => [
                    "LVL",
                ],
                "nominalLengthRegex" => [

                ],
                "nominalWidthRegex" => [
                    "x+(\d+)",      //x100
                    "X+\s+(\d+)",   //x 100
                ],
                "nominalHeightRegex" => [
                    "(\d+)+x",      //100x
                    "(\d+)+\s+X",   //100 x
                ],
                "measurementUnit" => MeasurementUnitEnums::MILLIMETERS,
                "defaultMaterial" => MaterialEnums::TIMBER,
            ],
            //todo more
        ];

        foreach($products as $product){
            foreach($product["productRegex"] as $pattern){
                $regex = "/".$pattern."/i";
                if(preg_match($regex, $text)){
                    $resultProduct = $product;
                }
            }
        }

        return $resultProduct;
    }

    public function findMaterial($product,$text): MaterialEnums
    {
        /**
         * Single purpose: extract a 'material' from text. e.g "SS304"
         */

        $materialResult = null;

        $materials = [
            //SS304
            [
                "materialEnum" => MaterialEnums::SS304,
                "regex" => [
                    "SS304",
                    "SS+\s+304",
                    "304SS",
                    "304+\s+SS",
                ],
            ],
            //SS316
            [
                "materialEnum" => MaterialEnums::SS316,
                "regex" => [
                    "SS316",
                    "SS+\s+316",
                    "316SS",
                    "316+\s+SS",
                ],
            ],
        ];

        foreach($materials as $material){
            foreach($material["regex"] as $pattern){
                $regex = "/".$pattern."/i";
                if(preg_match($regex, $text)){
                    $materialResult = $material["materialEnum"];
                }
            }
        }

        /**
         * Default material
         */
        if(!$materialResult){
            $materialResult = $product["defaultMaterial"];
        }

        return $materialResult;
    }

    public function findGrades($product,$text): null|array
    {
        /**
         * Single purpose: extract a 'grade' from text. e.g "GR 250"
         */

        $gradeResults = null;

        $grades = [
            //GR 250
            [
                "gradeEnum" => GradeEnums::GR250,
                "regex" => [
                    "Mild",
                    "MS",
                    "GR250",
                    "GRADE+\s+250",
                    "250MPA",
                    "250+\s+MPA",
                ],
            ],
            //GR 300
            [
                "gradeEnum" => GradeEnums::GR300,
                "regex" => [
                    "Mild",
                    "MS",
                    "GR300",
                    "GRADE+\s+300",
                    "300MPA",
                    "300+\s+MPA",
                ],
            ],
            //GR 350
            [
                "gradeEnum" => GradeEnums::GR350,
                "regex" => [
                    "Mild",
                    "MS",
                    "GR350",
                    "GRADE+\s+350",
                    "350MPA",
                    "350+\s+MPA",
                ],
            ],
            //todo: move this to material find
//            //SS304
//            [
//                "gradeEnum" => GradeEnums::SS304,
//                "regex" => [
//                    "SS304",
//                    "SS+\s+304",
//                    "304SS",
//                    "304+\s+SS",
//                ],
//            ],
//            //SS316
//            [
//                "gradeEnum" => GradeEnums::SS316,
//                "regex" => [
//                    "SS316",
//                    "SS+\s+316",
//                    "316SS",
//                    "316+\s+SS",
//                ],
//            ],
            //GR 4.6
            [
                "gradeEnum" => GradeEnums::GR_4_6,
                "regex" => [
                    "4.6",
                ],
            ],
            //GR 8.8
            [
                "gradeEnum" => GradeEnums::GR_8_8,
                "regex" => [
                    "8.8",
                ],
            ],
            //todo more
        ];

        foreach($grades as $grade){
            foreach($grade["regex"] as $pattern){
                $regex = "/".$pattern."/i";
                if(preg_match($regex, $text)){
                    $gradeResults[] = $grade["gradeEnum"];
                }
            }
        }

        /**
         * Default grade
         */
//        if(!$gradeResults){
//            $gradeResults = [
//                $product["defaultGrade"]
//            ];
//        }

        return $gradeResults;
    }

    public function findSurface($product,$text,$foundGrades): ?SurfaceEnums
    {
        /**
         * Single purpose: extract a 'surface' from text. e.g "Painted"
         */

        $surfaceResult = null;

        $surfaces = [
            [
                "surfaceEnum" => SurfaceEnums::PAINTED,
                "regex" => [
                    "painted",
                ],
            ],
            [
                "surfaceEnum" => SurfaceEnums::GALVANISED,
                "regex" => [
                    "galvanised",
                    "galvanise",
                    "galvanized",
                    "galvanize",
                    "gal",
                    "galv",
                ],
            ],
            [
                "surfaceEnum" => SurfaceEnums::PASSIVATED,
                "regex" => [
                    "passivated",
                ],
            ],
            [
                "surfaceEnum" => SurfaceEnums::TREATED_H2,
                "regex" => [
                    "h2",
                ],
            ],
            [
                "surfaceEnum" => SurfaceEnums::TREATED,
                "regex" => [
                    "treated",
                ],
            ],
            //todo more
        ];

        foreach($surfaces as $surface){
            foreach($surface["regex"] as $pattern){
                $regex = "/".$pattern."/i";
                if(preg_match($regex, $text)){
                    $surfaceResult = $surface["surfaceEnum"];
                }
            }
        }

        /**
         * Default surface
         */
//        if(!$surfaceResult){
//            $isException = false;
//            $foundGrades = $foundGrades === null
//                ? []
//                : $foundGrades;
//
//            //SS316 bolts
//            if($product["productEnum"] === ProductEnums::BOLT && in_array(GradeEnums::SS316,$foundGrades)){
//                $isException = true;
//            }
//
//            //SS304 bolts
//            if($product["productEnum"] === ProductEnums::BOLT && in_array(GradeEnums::SS304,$foundGrades)){
//                $isException = true;
//            }
//
//            if(!$isException){
//                $surfaceResult = $product["defaultSurface"];
//            }
//        }

        return $surfaceResult;
    }

    public function findMeasurementUnit($product): MeasurementUnitEnums
    {
        /**
         * Single purpose: get the measurement units from the product
         */

        return $product["measurementUnit"] ?? MeasurementUnitEnums::SINGLE;
    }
    public function findNominal($product,$text,$regexLabel): ?int
    {
        /**
         * Single purpose: extracts the number from string. e.g "200" from "200PFC"
         */

        $resultInt = null;

        $regexPatterns = $product[$regexLabel];

        foreach($regexPatterns as $pattern){
            $regex = "/".$pattern."/i";
            preg_match_all($regex, $text, $matches);
            if (!empty($matches[1])) {
                foreach ($matches[1] as $number) {
                    $resultInt = (int) $number;
                }
            }
        }

        return $resultInt;
    }
}

