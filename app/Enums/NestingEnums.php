<?php

namespace App\Enums;

enum NestingEnums: string
{
    case LINEAR = 'LINEAR';
    case AREA = "AREA";
    case PACK = "PACK";

    public function label(): string
    {
        return match ($this) {
            self::LINEAR => "LINEAR",
            self::AREA => 'AREA',
            self::PACK => "PACK",
        };
    }
}
