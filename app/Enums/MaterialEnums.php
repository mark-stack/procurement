<?php

namespace App\Enums;

/**
 * @deprecated
 */
enum MaterialEnums: string
{
    case PLAIN_CARBON_STEEL = "PLAIN_CARBON_STEEL";
    case HARDOX = "HARDOX";
    case SS316 = "SS316";
    case SS304 = "SS304";
    case ALLOY = "ALLOY";
    case TIMBER = "TIMBER";
    case ALUMINIUM = "ALUMINIUM";
    case PLASTIC = "PLASTIC";
    case MIXED = "MIXED";

    //todo more.


    public function label(): string
    {
        return match ($this) {
            self::PLAIN_CARBON_STEEL => "PLAIN_CARBON_STEEL",
            self::HARDOX => "HARDOX",
            self::SS304 => 'SS304',
            self::SS316 => 'SS316',
            self::ALLOY => "ALLOY",
            self::TIMBER => "TIMBER",
            self::ALUMINIUM => "ALUMINIUM",
            self::PLASTIC => "PLASTIC",
            self::MIXED => "MIXED",
            //todo more.
        };
    }
}
