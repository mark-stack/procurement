<?php

use App\Enums\ProductEnums;
use App\Enums\TemplateEnums;
use App\Enums\TemplateSourceEnums;

return [
    //Tekla
    /**
     * Tekla custom report
     * ConTekServices.com.au
     */
    [
        "label" => "Assembly List",
        "ownerDomain" => null, //For everybody
        "type" => TemplateEnums::CAD_BILL_OF_MATERIALS->value,
        "predeterminedProductCategory" => null,
        "source" => TemplateSourceEnums::TEKLA->value,
        "testFile" => "tekla single page report",
        "webSource" => "https://www.tekconservices.com.au/_files/ugd/c061a1_1d2d677884e24c5f9b54801278710ea3.pdf",
        "ExpectedHeadingLabels" => ["Mark","Qty","Profile","Name","Finish","Length (mm)","Unit Area (m2)","Unit Weight (kg)"],
        "OffsetFromHeaderToFirstDataRow" => 1,
        "skipOrFinishCheckColumnNumber" => 1,
        "ShouldSkipRow" => null, //None
        "isLastDataRow" => null, //2 consecutive blank 'description' cells
        "DescriptionColumnNumber" => 4,
        "MaterialColumnNumber" => null,
        "GradeColumnNumber" => null,
        "SurfaceColumnNumber" => 15, //"O"
        "LengthColumnNumber" => 19, //"S"
        "WidthColumnNumber" => null,
        "SubQtyColumnNumber" => 2, //"B"
        "UnitRateColumnNumber" => null,
        "nominalUnits" => "mm",
        "numberOfTablesInDocument" => 1,
    ],
    [
        "label" => "Hot Rolled, Angles, and more.",
        "ownerDomain" => null, //For everybody
        "type" => TemplateEnums::CAD_BILL_OF_MATERIALS->value,
        "predeterminedProductCategory" => null,
        "source" => TemplateSourceEnums::TEKLA->value,
        "testFile" => "tekla single page report",
        "webSource" => "https://www.tekconservices.com.au/_files/ugd/c061a1_1d2d677884e24c5f9b54801278710ea3.pdf",
        "ExpectedHeadingLabels" => ["Profile","Grade","Part Mark","Qty","Length[mm]","Unit Area (m2)","Total Area (m2)","Unit Weight (kg)","Total Weight (kg)"], //todo
        "OffsetFromHeaderToFirstDataRow" => 1,
        "skipOrFinishCheckColumnNumber" => 1,
        "ShouldSkipRow" => "Subtotal",  //Description column = "Subtotal"
        "isLastDataRow" => "Total",     //Description cell = "Total"
        "DescriptionColumnNumber" => 1,
        "MaterialColumnNumber" => null,
        "GradeColumnNumber" => 3,
        "SurfaceColumnNumber" => null,
        "LengthColumnNumber" => 11,
        "WidthColumnNumber" => null,
        "SubQtyColumnNumber" => 8,
        "UnitRateColumnNumber" => null,
        "nominalUnits" => "mm",
        "numberOfTablesInDocument" => 5,
    ],
    [
        "label" => "Bolt Summary - top",
        "ownerDomain" => null, //For everybody
        "type" => TemplateEnums::CAD_BILL_OF_MATERIALS->value,
        "predeterminedProductCategory" => ProductEnums::BOLT->value, //This is fringe case when there's no 'description' column to identify the category. e.g "200PFC" indicates it's PFC. In this case, maybe there's a table title "Parallel Flange Channels"
        "source" => TemplateSourceEnums::TEKLA->value,
        "testFile" => "tekla single page report",
        "webSource" => "https://www.tekconservices.com.au/_files/ugd/c061a1_1d2d677884e24c5f9b54801278710ea3.pdf",
        "ExpectedHeadingLabels" => ["Bolt Dia","Bolt Grade","Length(mm)","Qty","Comments"],
        "OffsetFromHeaderToFirstDataRow" => 1,
        "skipOrFinishCheckColumnNumber" => 1,
        "ShouldSkipRow" => null,        //None
        "isLastDataRow" => "Bolt Dia",  //cell = "Bolt Dia"
        "DescriptionColumnNumber" => null,
        "MaterialColumnNumber" => null,
        "GradeColumnNumber" => 5, //"E"
        "SurfaceColumnNumber" => null,
        "LengthColumnNumber" => 13, //"M"
        "WidthColumnNumber" => 1, //"A"
        "SubQtyColumnNumber" => 16, //"P"
        "UnitRateColumnNumber" => null,
        "nominalUnits" => "mm",
        "numberOfTablesInDocument" => 1,
    ],
    [
        "label" => "Bolt Summary - bottom",
        "ownerDomain" => null, //For everybody
        "type" => TemplateEnums::CAD_BILL_OF_MATERIALS->value,
        "predeterminedProductCategory" => ProductEnums::BOLT->value, //This is fringe case when there's no 'description' column to identify the category. e.g "200PFC" indicates it's PFC. In this case, maybe there's a table title "Parallel Flange Channels"
        "source" => TemplateSourceEnums::TEKLA->value,
        "testFile" => "tekla single page report",
        "webSource" => "https://www.tekconservices.com.au/_files/ugd/c061a1_1d2d677884e24c5f9b54801278710ea3.pdf",
        "ExpectedHeadingLabels" => ["Bolt Dia","Profile","Name","Length(mm)","Qty","Finish"],
        "OffsetFromHeaderToFirstDataRow" => 1,
        "skipOrFinishCheckColumnNumber" => 1,
        "ShouldSkipRow" => null, //None
        "isLastDataRow" => null,
        "DescriptionColumnNumber" => null, //Has no description column, so need product category derived from "predeterminedProductCategory"
        "MaterialColumnNumber" => null,
        "GradeColumnNumber" => null,
        "SurfaceColumnNumber" => null,
        "LengthColumnNumber" => 16, //"P"
        "WidthColumnNumber" => 1, //"A"
        "SubQtyColumnNumber" => 20, //"T"
        "UnitRateColumnNumber" => null,
        "nominalUnits" => "mm",
        "numberOfTablesInDocument" => 1,
    ],

    /**
     * Mark's examples.
     * From GoogleSheets made up example
     */
    [
        "label" => "Project Quote",
        "ownerDomain" => "gmail.com",
        "type" => TemplateEnums::PROJECT_QUOTE->value,
        "predeterminedProductCategory" => null,
        "source" => TemplateSourceEnums::PROJECT_MANAGER->value,
        "testFile" => "Monthly budget excel",
        "webSource" => "https://docs.google.com/spreadsheets/d/1NVv5x0np2qhD4vrLQEb2csr9DLYQCT8i-PofZmuD4pU/edit?gid=0#gid=0",
        "ExpectedHeadingLabels" => ["Length","Width","SubQty","Rate","Total"],
        "OffsetFromHeaderToFirstDataRow" => 2,
        "skipOrFinishCheckColumnNumber" => 2,
        "ShouldSkipRow" => null, //If description column is blank
        "isLastDataRow" => null, //2 consecutive blank 'description' cells
        "DescriptionColumnNumber" => 2,
        "MaterialColumnNumber" => null,
        "GradeColumnNumber" => null,
        "SurfaceColumnNumber" => null,
        "LengthColumnNumber" => 4,
        "WidthColumnNumber" => null,
        "SubQtyColumnNumber" => 6,
        "UnitRateColumnNumber" => 7,
        "nominalUnits" => "m",
        "numberOfTablesInDocument" => 1,
    ],
];
