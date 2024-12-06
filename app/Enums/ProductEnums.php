<?php

namespace App\Enums;

enum ProductEnums: string
{
    case BOLT = "BOLT";
    case UB = "UB";
    case UC = "UC";
    case PFC = "PFC";
    case PLATE = "PLATE";
    case LVL = "LVL";
    case SHS = "SHS";


    public function label(): string
    {
        return match ($this) {
            self::BOLT => "BOLT",
            self::UB => "UB",
            self::UC => "UC",
            self::PFC => "PFC",
            self::PLATE => "Plate",
            self::LVL => "LVL",
            self::SHS => "SHS",
        };
    }

    public static function millProducts(): array
    {
        return [
            self::UB,
            self::UC,
            self::PFC,
            self::PLATE,
            self::SHS,
            //todo...more
            //RHS
            //CHS
            //UBS
            //UCS
            //HSS
            //EA
            //UA
            //RSJ
            //FLAT_BAR
            //ROUND_BAR
            //SQUARE_BAR
            //I_BEAM
        ];
    }
}
