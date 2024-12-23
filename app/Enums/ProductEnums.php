<?php

namespace App\Enums;

enum ProductEnums: string
{
    //Fasteners
    case BOLT = "BOLT";
    case ALLTHREAD = "ALLTHREAD";

    //Sections
    case UB = "UB";
    case UC = "UC";
    case PFC = "PFC";
    case PLATE = "PLATE";
    case LVL = "LVL";
    case SHS = "SHS";
    case RHS = "RHS";

    //todo more
}
