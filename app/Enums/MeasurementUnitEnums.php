<?php

namespace App\Enums;

enum MeasurementUnitEnums: string
{
    case SINGLE = "SINGLE";
    case METERS = 'METERS';
    case MILLIMETERS = 'MILLIMETERS';

    public function label(): string
    {
        return match ($this) {
            self::SINGLE => "SINGLE",
            self::METERS => 'METERS',
            self::MILLIMETERS => 'MILLIMETERS',
        };
    }
}
