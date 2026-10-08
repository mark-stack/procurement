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

    /*
     * Treatment, which came in with the catalogue's LVL and outlived it. No product carries either
     * since the timber went in October 2026, but DataClassificationService still reads "H2" off a
     * descriptor - and it reads it off steel too, which is why these stayed when the timber did not.
     */
    case TREATED_H2 = 'TREATED_H2';
    case TREATED = 'TREATED';

}
