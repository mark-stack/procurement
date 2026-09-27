<?php

namespace App\Http\Requests;

class StoreProductRequest extends ProductRequest
{
    /**
     * Nothing new needs to start out with a blank in a column it will be matched on. The blanks
     * already in the catalogue were inherited from the spreadsheet, not chosen.
     */
    protected function toleratesBlankSpec(): bool
    {
        return false;
    }
}
