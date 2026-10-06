<?php

namespace App\Http\Controllers;

use App\Services\TwoDimensionalNestingProof;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class TwoDimensionalNestingController extends Controller
{
    /**
     * Nesting plate: three lifecycles, run end to end, with the workings shown.
     *
     * A proof of concept, and admin-only for that reason rather than because the figures are sensitive.
     * Nothing in the application nests plate - there is no sheet product, no table of sheet remnants and
     * no customer-facing screen - so this page is the whole of it, and what it is for is settling whether
     * the 1D offcut philosophy carries into two dimensions before anything gets built on the assumption
     * that it does. See Services\TwoDimensionalNestingProof, which is blunt about what is real here.
     *
     * Computed on every request rather than cached, exactly as /proof is. A dozen nests of a handful of
     * parts each is a couple of hundredths of a second, and a cached proof is a proof of what the
     * algorithm used to do.
     */
    public function __invoke(Request $request, TwoDimensionalNestingProof $proof): Response
    {
        return Inertia::render('TwoDimensionalNesting', [
            'scenarios' => $proof->scenarios(),
            'settings' => $proof->settings(),
        ]);
    }
}
