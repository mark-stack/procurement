<?php

namespace App\Http\Controllers;

use App\Formatters\NestingFormatter;
use App\Models\Business;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class LandingController extends Controller
{
    public function __invoke(Request $request): Response
    {
        //Formatter
        $nestingFormatter = new NestingFormatter();

        $sampleBusiness = Business::query()
            ->where('name',"SAMPLE")
            ->where('domain','sample.com')
            ->first();

        $sampleData = null;
        if($sampleBusiness){
            $sampleData = $nestingFormatter->nestingViewData('SUGGESTED', $sampleBusiness, null);
        }

        return Inertia::render('Welcome', [
            "sampleNestingData" => $sampleData,
        ]);
    }
}
