<?php

namespace App\Http\Controllers;

use App\Services\NestingProof;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ProofController extends Controller
{
    /**
     * Three nesting lifecycles, run end to end, with the workings shown.
     *
     * The companion to the nesting algorithm page: that one explains what the cost model charges for, this
     * one runs the algorithm over several jobs in a row and shows the plan it produces at every step -
     * including the part nothing else draws, which is a remnant coming back off the rack to be cut again,
     * and the remnant of THAT coming back after it.
     *
     * Computed on every request rather than cached. The whole page is about a dozen nests of a handful of
     * cuts each, which is well under a second, and a cached proof is a proof of what the algorithm used to
     * do. See Services\NestingProof for what is real here and what is modelled.
     */
    public function __invoke(Request $request, NestingProof $proof): Response
    {
        return Inertia::render('Proof', [
            'scenarios' => $proof->scenarios(),
            'settings' => $proof->settings(),
        ]);
    }
}
