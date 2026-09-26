<?php

namespace App\Http\Controllers;

use App\Actions\Piece\DetachPiecesFromBatch;
use App\Models\Batch;
use App\Models\Offcut;
use App\PrerequisiteConditions\PrerequisiteConditions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class BatchController extends Controller
{
    public function index()
    {
        //
    }

    public function create()
    {
        //
    }

    public function store(Request $request)
    {
        //Batches are created by QuoteController::store when a user starts quoting
        abort(404);
    }

    public function show(Batch $batch)
    {
        Gate::authorize('owned', $batch);
    }

    public function edit(Batch $batch)
    {
        Gate::authorize('owned', $batch);
    }

    public function update(Request $request, Batch $batch)
    {
        Gate::authorize('owned', $batch);
    }

    public function destroy(Batch $batch): RedirectResponse
    {
        /**
         * Break batch to renest
         *
         */

        Gate::authorize('owned', $batch);

        $user = auth()->user();

        /*
         * One transaction for the whole unwind.
         *
         * This is eight destructive statements - orders, the piece/quote pivot, quotes, the pieces'
         * batch_id, order approvals, the released offcuts, the produced offcuts, the batch itself - and
         * they used to autocommit one by one. Anything throwing partway left a batch whose orders were
         * gone but whose quotes remained, or pieces detached from a batch row that was still on the
         * board, and the app has no repair path for either.
         */
        DB::transaction(function () use ($batch, $user) {
            /*
             * Lock before the gate reads, or the gate is only a snapshot.
             *
             * Conditions 4 and 8 both test things somebody else can change: OrderSentController marks an
             * order sent, and a later nest claims one of this batch's offcuts (CreateBarsAndOffcuts).
             * Between the old unlocked read and the delete there was nothing stopping either, so a batch
             * could be unwound moments after its materials were ordered - the exact disaster the
             * condition 4 test was written for, reached through the back door.
             *
             * These row locks make a concurrent UPDATE on the same rows wait for this commit. Locking
             * the batch row also serialises two people re-nesting the same batch at once.
             */
            Batch::query()->whereKey($batch->id)->lockForUpdate()->first();
            $batch->orders()->lockForUpdate()->get();

            $offcutsAssignedToThisBatch = Offcut::query()
                ->where("batch_to_id",$batch->id)
                ->lockForUpdate()
                ->get();

            Offcut::query()
                ->where("batch_from_id",$batch->id)
                ->lockForUpdate()
                ->get();

            //Prerequisite conditions
            $prerequisiteUndoStartQuoting = (new PrerequisiteConditions())->undoStartQuoting(
                $batch,
                $user,
                $offcutsAssignedToThisBatch,
            );
            abort_if(!$prerequisiteUndoStartQuoting,403);

            //Delete orders (before the quotes - orders.quote_id is a restricting foreign key)
            $batch->orders()->delete();

            /*
             * Detach pieces from quotes.
             *
             * Addressed by the batch's quotes rather than one detach() per piece, which was a separate
             * DELETE for every piece on the batch. It also catches a pivot row whose piece has already
             * lost its batch_id, which the per-piece loop could not see.
             */
            DB::table('piece_quote')
                ->whereIn('quote_id',$batch->quotes()->pluck('id'))
                ->delete();

            //Delete quotes
            $batch->quotes()->delete();

            //Detach pieces from batch
            DetachPiecesFromBatch::run($batch);

            //Delete order approvals
            $batch->orderApprovals()->delete();

            //Un-associate any offcuts ("batch_to_id") - one statement, not one per offcut
            Offcut::query()
                ->where("batch_to_id",$batch->id)
                ->update(["batch_to_id" => null]);

            /*
             * Delete the offcuts this batch produced ("batch_from_id").
             *
             * Un-nesting the batch means those cuts were never made, so the offcuts do not exist. They used
             * to be left behind pointing at a batch row that is about to disappear: invisible on the offcuts
             * index (it only lists offcuts whose source batch is still one of yours), but still holding
             * their unique marks against the pool the next nest generates from.
             *
             * Condition 8 of the prerequisite gate above has already established that none of them has been
             * nested into by a later batch.
             */
            Offcut::query()
                ->where("batch_from_id",$batch->id)
                ->delete();

            /*
             * Delete the bars this batch cut.
             *
             * Same reasoning as the offcuts: the cuts were never made. The bars table had no batch column
             * until batch_id was added, so these were left behind as rows nothing could reach - one per
             * utilised bar, every time a batch was unwound.
             */
            $batch->bars()->delete();

            //Delete batch
            $batch->delete();
        });

        return back();
    }
}
