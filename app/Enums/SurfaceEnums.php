<?php

namespace App\Enums;

/**
 * @deprecated
 */
enum SurfaceEnums: string
{
    case NONE = 'NONE';
    case PAINTED = "PAINTED";
    case GALVANISED = "GALVANISED";
    case PASSIVATED = "PASSIVATED";
    case TREATED_H2 = "TREATED H2";
    case TREATED = "TREATED";


    public function label(): string
    {
        return match ($this) {
            self::NONE => "NONE",
            self::PAINTED => "PAINTED",
            self::GALVANISED => 'GALVANISED',
            self::PASSIVATED => 'PASSIVATED',
            self::TREATED => "TREATED",
            self::TREATED_H2 => "TREATED H2",
        };
    }
}
