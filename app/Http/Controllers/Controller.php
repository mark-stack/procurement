<?php

namespace App\Http\Controllers;

use App\Models\Business;
use Illuminate\Http\Request;

abstract class Controller
{
    /**
     * The business the request is acting on.
     *
     * A user belongs to exactly one business, so routes do not take it as a
     * parameter - there is nothing to authorize, only this to resolve.
     */
    protected function businessOf(Request $request): Business
    {
        $business = $request->user()?->business;

        //A user without a business is unexpected, but it must not 500
        abort_if($business === null, 403);

        return $business;
    }
}
