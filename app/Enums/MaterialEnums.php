<?php

namespace App\Enums;

enum MaterialEnums: string
{
    case PLAIN_CARBON_STEEL = 'PLAIN_CARBON_STEEL';
    case STAINLESS_STEEL = 'STAINLESS_STEEL';
    case HARDOX = 'HARDOX';
    case ALLOY = 'ALLOY';
    case TIMBER = 'TIMBER';
    case ALUMINIUM = 'ALUMINIUM';
    case PLASTIC = 'PLASTIC';
    case MIXED = 'MIXED';

    //todo more.

    /**
     * What to call this on screen.
     *
     * Short on purpose. The column value is a join key that pieces, bars and offcuts are matched on
     * (see Services\ProductSpec), so it is written the way a database wants it - and a catalogue of
     * 1,107 rows reading PLAIN_CARBON_STEEL down an entire column says nothing, because almost all
     * of them are. What a reader is actually scanning for is the handful that are NOT steel.
     */
    public function label(): string
    {
        return match ($this) {
            self::PLAIN_CARBON_STEEL => 'Steel',
            self::STAINLESS_STEEL => 'Stainless',
            self::HARDOX => 'Hardox',
            self::ALLOY => 'Alloy',
            self::TIMBER => 'Timber',
            self::ALUMINIUM => 'Aluminium',
            self::PLASTIC => 'Plastic',
            self::MIXED => 'Mixed',
        };
    }

    /**
     * Whether this is steel of some kind.
     *
     * Asked by the catalogue screen, which marks the rows that are not - because a row that is not
     * steel is costed through a different merchant's price per tonne and a different mass, and
     * until recently was not (see Services\SupplierGroupCosts). Hardox is a wear plate and stainless
     * is stainless, but both are bought from a steel merchant and weigh what steel weighs; timber,
     * aluminium and plastic are none of those things.
     */
    public function isSteel(): bool
    {
        return match ($this) {
            self::PLAIN_CARBON_STEEL, self::STAINLESS_STEEL, self::HARDOX, self::ALLOY => true,
            self::TIMBER, self::ALUMINIUM, self::PLASTIC, self::MIXED => false,
        };
    }
}
