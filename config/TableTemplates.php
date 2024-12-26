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
        "skipOrFinishCheckRelativeOffset" => 1,
        "ShouldSkipRow" => null, //None
        "isLastDataRow" => null, //2 consecutive blank 'description' cells
        "DescriptionRelativeOffset" => 4,
        "MaterialRelativeOffset" => null,
        "GradeRelativeOffset" => null,
        "SurfaceRelativeOffset" => 15, //"O"
        "LengthRelativeOffset" => 19, //"S"
        "WidthRelativeOffset" => null,
        "SubQtyRelativeOffset" => 2, //"B"
        "UnitRateRelativeOffset" => null,
        "nominalUnits" => "mm",
        "numberOfTablesInDocument" => 1,
        /*
         * Assembly mark
         * 1) Directly from a column for each row
         *    - provide column number
         *    - "COLUMN"
         * 2) A fixed cell reference applied to all rows
         *    - provide relative coordinates relative to first table heading. up & left = minus. e.g X,Y = [3,-1]
         *    - "FIXED"
         * 3) None: null
         */
        "assemblyMarkRule" => ["COLUMN",1],
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
        "skipOrFinishCheckRelativeOffset" => 1,
        "ShouldSkipRow" => "Subtotal",  //Description column = "Subtotal"
        "isLastDataRow" => "Total",     //Description cell = "Total"
        "DescriptionRelativeOffset" => 1,
        "MaterialRelativeOffset" => null,
        "GradeRelativeOffset" => 3,
        "SurfaceRelativeOffset" => null,
        "LengthRelativeOffset" => 11,
        "WidthRelativeOffset" => null,
        "SubQtyRelativeOffset" => 8,
        "UnitRateRelativeOffset" => null,
        "nominalUnits" => "mm",
        "numberOfTablesInDocument" => 5,
        /*
         * Assembly mark
         * 1) Directly from a column for each row
         *    - provide column number
         *    - "COLUMN"
         * 2) A fixed cell reference applied to all rows
         *    - provide relative coordinates relative to first table heading. up & left = minus. e.g X,Y = [3,-1]
         *    - "FIXED"
         * 3) None: null
         */
        "assemblyMarkRule" => ["COLUMN",3],
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
        "skipOrFinishCheckRelativeOffset" => 1,
        "ShouldSkipRow" => null,        //None
        "isLastDataRow" => "Bolt Dia",  //cell = "Bolt Dia"
        "DescriptionRelativeOffset" => null,
        "MaterialRelativeOffset" => null,
        "GradeRelativeOffset" => 5, //"E"
        "SurfaceRelativeOffset" => null,
        "LengthRelativeOffset" => 13, //"M"
        "WidthRelativeOffset" => 1, //"A"
        "SubQtyRelativeOffset" => 16, //"P"
        "UnitRateRelativeOffset" => null,
        "nominalUnits" => "mm",
        "numberOfTablesInDocument" => 1,
        /*
         * Assembly mark
         * 1) Directly from a column for each row
         *    - provide column number
         *    - "COLUMN"
         * 2) A fixed cell reference applied to all rows
         *    - provide relative coordinates relative to first table heading. up & left = minus. e.g X,Y = [3,-1]
         *    - "FIXED"
         * 3) None: null
         */
        "assemblyMarkRule" => ["FIXED",[5,-3]],
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
        "skipOrFinishCheckRelativeOffset" => 1,
        "ShouldSkipRow" => null, //None
        "isLastDataRow" => null,
        "DescriptionRelativeOffset" => null, //Has no description column, so need product category derived from "predeterminedProductCategory"
        "MaterialRelativeOffset" => null,
        "GradeRelativeOffset" => null,
        "SurfaceRelativeOffset" => null,
        "LengthRelativeOffset" => 16, //"P"
        "WidthRelativeOffset" => 1, //"A"
        "SubQtyRelativeOffset" => 20, //"T"
        "UnitRateRelativeOffset" => null,
        "nominalUnits" => "mm",
        "numberOfTablesInDocument" => 1,
        /*
         * Assembly mark
         * 1) Directly from a column for each row
         *    - provide column number
         *    - "COLUMN"
         * 2) A fixed cell reference applied to all rows
         *    - provide relative coordinates relative to first table heading. up & left = minus. e.g X,Y = [3,-1]
         *    - "FIXED"
         * 3) None: null
         */
        "assemblyMarkRule" => ["NONE"],
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
        "skipOrFinishCheckRelativeOffset" => -2,
        "ShouldSkipRow" => null, //If description column is blank
        "isLastDataRow" => null, //2 consecutive blank 'description' cells
        "DescriptionRelativeOffset" => -2,
        "MaterialRelativeOffset" => null,
        "GradeRelativeOffset" => null,
        "SurfaceRelativeOffset" => null,
        "LengthRelativeOffset" => 0,
        "WidthRelativeOffset" => 1,
        "SubQtyRelativeOffset" => 2,
        "UnitRateRelativeOffset" => 3,
        "nominalUnits" => "m",
        "numberOfTablesInDocument" => 1,
        /*
         * Assembly mark
         * 1) Directly from a column for each row
         *    - provide column number
         *    - "COLUMN"
         * 2) A fixed cell reference applied to all rows
         *    - provide relative coordinates relative to first table heading. up & left = minus. e.g X,Y = [3,-1]
         *    - "FIXED"
         * 3) None: null
         */
        "assemblyMarkRule" => ["FIXED",[-2,-1]],
    ],
];
