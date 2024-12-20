<?php

namespace App\Enums;

enum TemplateSourceEnums: string
{
    case PROJECT_MANAGER = 'PROJECT_MANAGER';
    case TEKLA = 'TEKLA';
    case INVENTOR = 'INVENTOR';
    case ADVANCE_STEEL = 'ADVANCE_STEEL';
    case SOLIDWORKS = 'SOLIDWORKS';
    case REVIT = 'REVIT';
}
