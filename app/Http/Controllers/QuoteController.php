<?php

namespace App\Http\Controllers;

use App\Actions\Batch\RemoveOffcutsFromInventory;
use App\Actions\Batch\SaveNesting;
use App\Actions\OrderApproval\CreatePendingOrderApprovals;
use App\Actions\Piece\AttachPiecesToBatch;
use App\Formatters\NestingFormatter;
use App\PrerequisiteConditions\PrerequisiteConditions;
use App\Models\Batch;
use App\Models\Quote;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Exception;
use Illuminate\Support\Facades\Gate;

class QuoteController extends Controller
{
    /**
     * @deprecated
     */
    public function index() {

    }

    public function create()
    {
        //
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            //
        ]);

        //Prerequisite variables
        $user = auth()->user();
        $business = $user->business;

        //Prerequisites
        $piecesReadyForBatching = (new NestingFormatter)->piecesReadyForBatching($business);
        $projectsReadyForBatching = $business->projectsReadyForBatching($piecesReadyForBatching,$business); //Note get this before updating pieces because it gets modified

        //Prerequisite conditions
        $prerequisiteStartQuoting = (new PrerequisiteConditions())->startQuoting(
            $user,
            $projectsReadyForBatching,
            $piecesReadyForBatching,
        );

        abort_if(!$prerequisiteStartQuoting,403);

        try {
            DB::transaction(function () use($business,$user,$projectsReadyForBatching,$piecesReadyForBatching){
                /*
                 * Create batch
                 */
                $batch = Batch::create([
                    'user_id' => $user->id,
                ]);

                //Attach pieces to batch
                AttachPiecesToBatch::run($piecesReadyForBatching, $batch);

                //Create pending order approvals
                CreatePendingOrderApprovals::run($projectsReadyForBatching, $batch);

                //Save the current nesting state (points offcuts to new batch)
                SaveNesting::run($piecesReadyForBatching, $batch, $business);
            });
        } catch (Exception $e) {
            //dd($e->getMessage());
            //todo throw an error
        }

        return back();
    }

    public function show(Quote $quote)
    {
        Gate::authorize('owned', $quote);
    }

    public function edit(Quote $quote)
    {
        Gate::authorize('owned', $quote);
    }

    public function update(Request $request, Quote $quote): RedirectResponse
    {
        Gate::authorize('owned', $quote);

        $validated = $request->validate([
            'batch_id' => 'required',
            'quote_sent' => 'required',
            'supplier_quote_reference' => 'nullable',
            'quoted_price' => 'nullable',
            'quoted_lead_time' => 'nullable',
        ]);

        //Attempting to mark as sent
        if(!$quote->quote_sent && $validated['quote_sent']){
            $canMarkQuoteAsSent = (new PrerequisiteConditions())->markQuoteAsSent(
                auth()->user(),
                $quote,
            );
            abort_if(!$canMarkQuoteAsSent,403,"Cannot mark quote as sent");
        }
        //Attempting to undo mark as sent
        if($quote->quote_sent && !$validated['quote_sent']){
            $canUndoMarkQuoteAsSent = (new PrerequisiteConditions())->undoMarkQuoteAsSent(
                auth()->user(),
                $quote,
            );
            abort_if(!$canUndoMarkQuoteAsSent,403,"Cannot undo mark quote as sent");
        }

        $quote->update($validated);

        return back();
    }

    public function destroy(Quote $quote)
    {
        Gate::authorize('owned', $quote);
    }
}
