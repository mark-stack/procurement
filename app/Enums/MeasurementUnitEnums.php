<?php

namespace App\Enums;

/**
 * @deprecated
 */
enum MeasurementUnitEnums: string
{
    case SINGLE = "SINGLE";
    case METERS = 'METERS';
    case MILLIMETERS = 'MILLIMETERS';
    case FEET = 'FEET';
    case INCHES = 'INCHES';


    public function label(): string
    {
        return match ($this) {
            self::SINGLE => "SINGLE",
            self::METERS => 'METERS',
            self::MILLIMETERS => 'MILLIMETERS',
            self::FEET => "FEET",
            self::INCHES => "INCHES",
        };
    }
}
