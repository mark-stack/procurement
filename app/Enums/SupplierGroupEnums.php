<?php

namespace App\Enums;

enum SupplierGroupEnums: string
{
    /*
     * Meterage
     */
    case STEEL_MERCHANT = 'STEEL_MERCHANT';
    case PURLINS = 'PURLINS';
    case TIMBER_MERCHANT = 'TIMBER_MERCHANT';

    /*
     * Bundle
     */
    case FASTENERS = 'FASTENERS';

    /*
     * Area
     */
    case PROFILE_CUTTING = 'PROFILE_CUTTING';
}
