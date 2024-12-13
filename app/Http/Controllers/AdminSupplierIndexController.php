<?php

namespace App\Http\Controllers;

use App\Http\Resources\SupplierResource;
use App\Models\Business;
use App\Models\Supplier;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AdminSupplierIndexController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(Request $request, Business $business): Response
    {
        return Inertia::render('AdminSuppliersIndex',[
            "suppliers" => SupplierResource::collection($business->suppliers()->orderBy("name")->get()),
            "categories" => config('supplier_groups'),
            "business" => $business,
        ]);
    }
}
