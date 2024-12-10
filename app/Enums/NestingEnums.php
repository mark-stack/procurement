<?php

namespace App\Enums;

/**
 * @deprecated
 */
enum NestingEnums: string
{
    case METERAGE = 'METERAGE';
    case AREA = "AREA";
    case BUNDLE = "BUNDLE";

    public function label(): string
    {
        return match ($this) {
            self::METERAGE => "METERAGE",
            self::AREA => 'AREA',
            self::BUNDLE => "BUNDLE",
        };
    }
}
