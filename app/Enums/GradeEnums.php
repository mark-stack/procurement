<?php

namespace App\Enums;

enum GradeEnums: string
{
    case NONE = "NONE ";
    case GR250 = "GR250";
    case GR350 = "GR350";
    case SS304 = 'SS304';
    case SS316 = 'SS316';
    case GR_4_6 = "4.6";
    case GR_8_8 = "8.8";
    //todo more. timber etc


    public function label(): string
    {
        return match ($this) {
            self::NONE => "NONE",
            self::GR250 => "GR250",
            self::GR350 => "GR350",
            self::SS304 => 'SS304',
            self::SS316 => 'SS316',
            self::GR_4_6 => "4.6",
            self::GR_8_8 => "8.8",
            //todo more. timber etc
        };
    }
}
