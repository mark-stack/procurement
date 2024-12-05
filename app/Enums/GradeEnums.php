<?php

namespace App\Enums;

enum GradeEnums: string
{
    case NONE = "NONE";
    case GR250 = "GR250";
    case GR300 = "GR300";
    case GR350 = "GR350";
    case SS304 = 'SS304';
    case SS316 = 'SS316';
    case GR_4_6 = "GR_8_8";
    case GR_8_8 = "GR_4_6";
    case E13 = "E13";

    //todo more.


    public function label(): string
    {
        return match ($this) {
            self::NONE => "NONE",
            self::GR250 => "GR250",
            self::GR300 => "GR300",
            self::GR350 => "GR350",
            self::SS304 => 'SS304',
            self::SS316 => 'SS316',
            self::GR_4_6 => "GR_4_6",
            self::GR_8_8 => "GR_8_8",
            self::E13 => "E13",
            //todo more. timber etc
        };
    }
}
