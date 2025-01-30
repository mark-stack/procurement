<?php

namespace App\Enums;

enum ProductEnums: string
{
    //Fasteners
    case HEX_BOLT = 'HEX_BOLT';
    case ALLTHREAD = 'ALLTHREAD';
    case ANCHOR_STUD = 'ANCHOR_STUD';
    case CSK_BOLT = 'CSK_BOLT';
    case NUT = 'NUT';

    //Sections
    case UB = 'UB';
    case UC = 'UC';
    case PFC = 'PFC';
    case PLATE = 'PLATE';
    case LVL = 'LVL';
    case SHS = 'SHS';
    case RHS = 'RHS';
    case CHS = 'CHS';
    case ROUND = 'ROUND';
    case FLAT = 'FLAT';
    case EA = 'EA';
    case UA = 'UA';

    //todo more

    //        "UBS", "Universal Beam Section","Universal Beam Sections",
    //        "UCS", "Universal Column Section","Universal Column Sections",
    //        "HSS","Hollow Structural Section","Hollow Structural Sections",
    //        "Steel Angles","Steel Angles",
    //        "UA", "unequal angle","unequal angles",
    //        "RSJ", "rolled steel joist","rolled steel joists",
    //        "Square Bar","Square Bars",
    //        "Rebar","Reinforcement Bar","Reinforcement Bars",
    //        "Threaded Rod","Threaded Rods","allthread",
    //        "I-Beam","I-Beams",
    //        "Steel Joist","Steel Joists",
    //        "Steel Tube","Steel Tubes",
}
