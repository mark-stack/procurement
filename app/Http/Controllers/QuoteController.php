<?php

namespace App\Http\Controllers;

use App\Actions\Batch\RemoveOffcutsFromInventory;
use App\Actions\Batch\SaveNesting;
use App\Actions\OrderApproval\CreatePendingOrderApprovals;
use App\Actions\Piece\AttachPiecesToBatch;
use App\Formatters\NestingFormatter;
use App\Models\Batch;
use App\Models\Quote;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Exception;

class QuoteController extends Controller
{
    /**
     * @deprecated
     */
    public function index() {}

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            //
        ]);

        /*
         * Prerequisite variables
         */
        $user = auth()->user();
        $business = $user->business;

        /*
         * Create batch
         */
        $batch = Batch::create([
            'user_id' => $user->id,
        ]);

        try {
            DB::transaction(function () use($business,$batch) {
                //Prerequisite variables
                $projectsReadyForBatching = $business->projectsReadyForBatching(); //Note get this before updating pieces because it gets modified
                $piecesReadyForBatching = (new NestingFormatter)->piecesReadyForBatching($business);

                //Attach pieces to batch
                AttachPiecesToBatch::run($piecesReadyForBatching, $batch);

                //Create pending order approvals
                CreatePendingOrderApprovals::run($projectsReadyForBatching, $batch);

                //Save the current nesting state
                SaveNesting::run($piecesReadyForBatching, $batch, $business);
            });
        } catch (Exception $e) {
            //dd($e->getMessage());
            //todo throw an error
        }

        return back();
    }

    /**
     * Display the specified resource.
     */
    public function show(Quote $quote)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Quote $quote)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Quote $quote): RedirectResponse
    {
        $validated = $request->validate([
            'batch_id' => 'required',
            'quote_sent' => 'required',
            'supplier_quote_reference' => 'nullable',
            'quoted_price' => 'nullable',
            'quoted_lead_time' => 'nullable',
        ]);

        $quote->update($validated);

        return back();
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Quote $quote)
    {
        //
    }
}
