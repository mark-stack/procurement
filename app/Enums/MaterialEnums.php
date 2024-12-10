<?php

namespace App\Enums;

/**
 * @deprecated
 */
enum MaterialEnums: string
{
    case STEEL = "STEEL";
    case ALLOY = "ALLOY";
    case TIMBER = "TIMBER";
    case ALUMINIUM = "ALUMINIUM";
    case PLASTIC = "PLASTIC";
    case MIXED = "MIXED";

    //todo more.


    public function label(): string
    {
        return match ($this) {
            self::STEEL => "STEEL",
            self::ALLOY => "ALLOY",
            self::TIMBER => "TIMBER",
            self::ALUMINIUM => "ALUMINIUM",
            self::PLASTIC => "PLASTIC",
            self::MIXED => "MIXED",
            //todo more.
        };
    }
}
