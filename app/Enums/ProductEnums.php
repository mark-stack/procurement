<?php

namespace App\Enums;

enum ProductEnums: string
{
    //Fasteners
    case HEX_BOLT = "HEX_BOLT";
    case ALLTHREAD = "ALLTHREAD";
    CASE ANCHOR_STUD = "ANCHOR_STUD";
    case CSK_BOLT = "CSK_BOLT";
    case NUT = "NUT";

    //Sections
    case UB = "UB";
    case UC = "UC";
    case PFC = "PFC";
    case PLATE = "PLATE";
    case LVL = "LVL";
    case SHS = "SHS"; //"square hollow section","square hollow sections",
    case RHS = "RHS"; //"rectangular hollow section","rectangular hollow sections",

    //todo more

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
}
