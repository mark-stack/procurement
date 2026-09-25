<?php

namespace App\Http\Controllers;

use App\Http\Resources\OffcutResource;
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

        return Inertia::render('OffcutsIndex', [
            'offcuts' => OffcutResource::collection($offcuts),
        ]);
    }
}
