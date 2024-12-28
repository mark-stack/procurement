<?php

namespace App\Enums;

enum GradeEnums: string
{
    //STEEL
    case NONE = "NONE";
    case GR250 = "GR250";
    case GR300 = "GR300";
    case GR350 = "GR350";
    case GR_12_9 = "GR_12_9";
    case GR_8_8 = "GR_8_8";
    case GR_5_8 = "GR_5_8";
    case GR_4_6 = "GR_4_6";


    //TIMBER
    case E13 = "E13";

    //PLASTIC
    case HDPE = "HDPE";

    //todo more.
}
