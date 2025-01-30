<?php

namespace App\Http\Controllers;

use App\Models\Product;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PricebookController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(Request $request): Response
    {
        $products = Product::query()
            ->availableFor(auth()->user())
            ->get();

        return Inertia::render('PriceBook', [
            'products' => $products,
        ]);
    }
}
