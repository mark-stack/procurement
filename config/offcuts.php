<?php

return [
    /*
     * How long a piece of steel may sit on the offcut rack before the quarterly cleanout will put it
     * up for scrapping.
     *
     * A year, because that is roughly how long it takes to be sure. An offcut is only worth keeping
     * if it gets used, and "used" is not a property of its length - a 1.2m stub of light angle that
     * a nest draws on next month cost nobody anything, and the identical stub still there four
     * quarters later has been paid for four times over in marking, shifting and looking past.
     *
     * Age alone is never enough to propose one. It has to be short enough not to pay for its own
     * keep as well - see App\Services\OffcutCleanout.
     */
    'shelf_life_days' => (int) env('OFFCUT_SHELF_LIFE_DAYS', 365),
];
