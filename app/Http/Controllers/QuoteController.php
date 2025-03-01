<?php

namespace App\Http\Controllers;

use App\Actions\Batch\RemoveOffcutsFromInventory;
use App\Actions\Batch\SaveNesting;
use App\Actions\OrderApproval\CreatePendingOrderApprovals;
use App\Actions\Piece\AttachPiecesToBatch;
use App\Formatters\NestingFormatter;
use App\Models\Batch;
use App\Models\Piece;
use App\Models\Project;
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

        /**
         * 1) Pieces belong to this business
         * 2) All pieces have no batch
         * 3) All projects not archived
         * 4) User is owner of at least 1 project
         * 45) Has materials
         */

        $piecesReadyForBatching = (new NestingFormatter)->piecesReadyForBatching($business);
        $projectsReadyForBatching = $business->projectsReadyForBatching($piecesReadyForBatching); //Note get this before updating pieces because it gets modified

        //1) Pieces belong to this business
        $condition_1 = true;
        foreach($projectsReadyForBatching as $project){
            if($project->user->business->id !== $business->id){
                $condition_1 = false;
            }
        }

        //2) All pieces have no batch
        $condition_2 = $piecesReadyForBatching->where("batch_id","!=",null)->count() === 0;

        //3) All projects not archived
        $condition_3 = $piecesReadyForBatching->where("archive",true)->count() === 0;

        //4) User is owner of at least 1 project
        $condition_4 = false;
        foreach($projectsReadyForBatching as $project){
            if($project->user->id === $user->id){
                $condition_4 = true;
            }
        }

        //5) Has materials
        $condition_5 = $piecesReadyForBatching->count() > 0;

        $proceed = $condition_1 && $condition_2 && $condition_3 && $condition_4 && $condition_5;

        abort_if(!$proceed,403,"conditions not satisfied");

        try {
            DB::transaction(function () use($business,$user,$piecesReadyForBatching,$projectsReadyForBatching){
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

        $quote->update($validated);

        return back();
    }

    public function destroy(Quote $quote)
    {
        Gate::authorize('owned', $quote);
    }
}
