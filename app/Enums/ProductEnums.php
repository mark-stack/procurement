<?php

namespace App\Enums;

/**
 * @deprecated
 */
enum ProductEnums: string
{
    case BOLT = "BOLT";
    case UB = "UB";
    case UC = "UC";
    case PFC = "PFC";
    case PLATE = "PLATE";
    case LVL = "LVL";
    case SHS = "SHS";
    case RHS = "RHS";


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
            self::RHS => "RHS",
        };
    }
}
