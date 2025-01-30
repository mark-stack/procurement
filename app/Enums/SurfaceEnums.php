<?php

namespace App\Enums;

enum SurfaceEnums: string
{
    //General
    case NONE = 'NONE';
    case PAINTED = 'PAINTED';

    //Metal
    case GALVANISED = 'GALVANISED';
    case ZINC = 'ZINC';
    case PASSIVATED = 'PASSIVATED';

    //Timber
    case TREATED_H2 = 'TREATED_H2';
    case TREATED = 'TREATED';

}
