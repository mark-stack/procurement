<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\Project;
use App\Models\RawMaterialQuote;
use App\Services\ProductService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class ProductController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Project $project): Response
    {
        Gate::authorize('owned', $project);

        $productService = new ProductService();

        $materialListRows = [];
        foreach($project->rawMaterialQuotes as $row){
            //Check if pre-nested
            $row->checkIfPreNested = $productService->isPurchasableSize($row);

            //Array
            $materialListRows[] = $row;
        }

        return Inertia::render('ProductIndex', [
            "project" => $project,
            //"projectProducts" => $projectProducts,
            "materialListRows" => $materialListRows,
        ]);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create(Project $project)
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request, Project $project): RedirectResponse
    {
        $request->validate([
            'csv' => 'required|mimes:csv,txt|max:2048', // Validate the file
        ]);

        // Store the uploaded file temporarily
        $path = $request->file('csv')->store('uploads');

        // Read the CSV
        $data = [];
        if (($handle = fopen(storage_path("app/private/{$path}"), 'r')) !== false) {
            while (($row = fgetcsv($handle, 1000, ',')) !== false) {
                $data[] = $row;
            }
            fclose($handle);
        }

        /**
         * Template detection
         */
        $productService = new ProductService();
        $templatesDetected = $productService->templatesDetected($data,$project->user);

        // Optionally delete the file after processing
        unlink(storage_path("app/private/{$path}"));

        //Only 1 template found (ideal scenario)
        $onlyOneResult = count($templatesDetected) === 1;
        if($onlyOneResult){
            $cleanCsvData = $productService->cleanCsvData($data,$templatesDetected[0]);
            $cleanMaterialList = $productService->saveRawMaterialQuoteData($cleanCsvData,$project);
            $dataWithProducts = $productService->findProductsFromCleanData($cleanMaterialList);
            $productService->saveConfirmedProducts($dataWithProducts,$project);
            $productService->createUserCustomProducts($dataWithProducts,$project);
            $getUnconfirmedRows = $productService->getUnconfirmedRows($dataWithProducts);
            //dd("getUnconfirmedRows",$getUnconfirmedRows);
        }
        else{
            //todo
            //dd("not just one. Bad.",$templatesDetected);
        }

        return back();
    }

    /**
     * Display the specified resource.
     */
    public function show(Project $project, Product $product)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Project $project, Product $product)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Product $product)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Project $project, Product $product)
    {
        //
    }
}
