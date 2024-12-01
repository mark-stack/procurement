<?php

namespace App\Enums;

enum MaterialEnums: string
{
    case STEEL = "STEEL";
    case TIMBER = "TIMBER";

    //todo more.


    public function label(): string
    {
        return match ($this) {
            self::STEEL => "STEEL",
            self::TIMBER => "TIMBER",
            //todo more.
        };
    }
}
