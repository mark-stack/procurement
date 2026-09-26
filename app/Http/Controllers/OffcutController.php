<?php

namespace App\Http\Controllers;

use App\Http\Resources\OffcutResource;
use App\Models\Offcut;
use Inertia\Inertia;
use Inertia\Response;

class OffcutController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(): Response
    {
        $user = auth()->user();
        $business = $user->business;

        $offcuts = $business->availableOffcuts()
            //bar for the payload, sourceBatch (+ its user/business) so the resource reads it without a
            //query per row
            ->with(['bar', 'sourceBatch.user.business'])
            ->get();

        /*
         * The chain each offcut was cut down from, resolved for the whole page at once.
         * The resource reads the generation and the source marks off it; left to walk per row it would
         * cost one query per offcut per generation.
         */
        $offcuts = Offcut::loadAncestry($offcuts);

        return Inertia::render('OffcutsIndex', [
            'offcuts' => OffcutResource::collection($offcuts),
        ]);
    }
}
