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
    case GR_10_9 = "GR_10_9";
    case GR_8_8 = "GR_8_8";
    case GR_5_8 = "GR_5_8";
    case GR_4_6 = "GR_4_6";


    //TIMBER
    case E13 = "E13";

    //PLASTIC
    case HDPE = "HDPE";

    //ALLOY (aluminium)
    case GR_6060 = "GR6060";
    case GR_6061 = "GR6061";

    //HARDOX
    case HARDOX_400 = "HARDOX_400";
    case HARDOX_450 = "HARDOX_450";
    case HARDOX_500 = "HARDOX_500";
    case HARDOX_500_TUF = "HARDOX_500_TUF";
    case HARDOX_550 = "HARDOX_550";
    case HARDOX_600 = "HARDOX_600";
    case HARDOX_HI_TUF = "HARDOX_HI_TUF";
    case HARDOX_EXTREME = "HARDOX_EXTREME";
    case HARDOX_HI_TEMP = "HARDOX_HI_TEMP";
}
