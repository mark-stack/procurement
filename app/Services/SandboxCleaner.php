<?php

namespace App\Services;

use App\Models\Bar;
use App\Models\Batch;
use App\Models\Offcut;
use App\Models\Order;
use App\Models\OrderApproval;
use App\Models\Piece;
use App\Models\Project;
use App\Models\Quote;
use App\Models\RawMaterialQuote;
use App\Models\Scrap;
use App\Models\User;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\DB;

/**
 * Throws away everything one user made in test mode.
 *
 * "Disposable" is the whole promise of the sandbox, so this has to leave nothing behind: not a
 * quote, not an offcut holding a unique mark, not a reminder in the bell about a project that no
 * longer exists. The unwind in BatchController::destroy is the same job for one batch and this
 * follows it - deletions in foreign key order, all of it in one transaction, because a half-cleared
 * sandbox is a state the application has no way to repair.
 *
 * Only rows stamped with this user's id are touched. There is no path from here to live data.
 */
class SandboxCleaner
{
    /**
     * @return array{projects: int, batches: int} what was thrown away, for the message afterwards
     */
    public function clear(User $user): array
    {
        /*
         * Read without the sandbox scope. Clearing has to work whichever mode the user is in -
         * they may well have left test mode and want the mess gone before going back to work -
         * and in live mode the scope hides every row this method exists to delete.
         */
        $projectIds = Project::query()
            ->withoutGlobalScope('sandbox')
            ->where('sandbox_user_id', $user->id)
            ->pluck('id')
            ->all();

        $batchIds = Batch::query()
            ->withoutGlobalScope('sandbox')
            ->where('sandbox_user_id', $user->id)
            ->pluck('id')
            ->all();

        if ($projectIds === [] && $batchIds === []) {
            return ['projects' => 0, 'batches' => 0];
        }

        DB::transaction(function () use ($projectIds, $batchIds) {
            $quoteIds = Quote::query()->whereIn('batch_id', $batchIds)->pluck('id')->all();
            $orderIds = Order::query()->whereIn('batch_id', $batchIds)->pluck('id')->all();

            //A piece belongs to a project, so the project is what decides whether it is test data
            $pieceIds = Piece::query()->whereIn('project_id', $projectIds)->pluck('id')->all();

            /*
             * Released, not deleted.
             *
             * A live project's piece should never be sitting on a test batch - nesting only ever
             * sees one set of projects at a time - but if one somehow is, it is real work. Cutting
             * it loose keeps it; leaving it alone would fail the whole clear on the foreign key
             * when the batch goes, and deleting it would throw away somebody's live material line.
             */
            Piece::query()
                ->whereIn('batch_id', $batchIds)
                ->whereNotIn('project_id', $projectIds)
                ->update(['batch_id' => null, 'order_id' => null]);

            //Pivots first: piece_quote, product_quote and order_product all point at rows below
            DB::table('piece_quote')
                ->where(function ($query) use ($quoteIds, $pieceIds) {
                    $query->whereIn('quote_id', $quoteIds)
                        ->orWhereIn('piece_id', $pieceIds);
                })
                ->delete();

            DB::table('product_quote')->whereIn('quote_id', $quoteIds)->delete();
            DB::table('order_product')->whereIn('order_id', $orderIds)->delete();

            /*
             * Release anything real this sandbox had claimed.
             *
             * It should not be possible - the offcut inventory is built from the business's
             * batches, which the sandbox scope filters, so a test nest can only ever draw on test
             * offcuts. Defensive because the alternative is a live offcut left marked as consumed
             * by a batch that no longer exists, which nothing in the application can undo.
             */
            Offcut::query()
                ->whereIn('batch_to_id', $batchIds)
                ->whereNotIn('batch_from_id', $batchIds)
                ->update(['batch_to_id' => null, 'piece_to_id' => null]);

            //The offcuts, bars and scrap the test nesting produced. Those cuts were never made
            Offcut::query()->whereIn('batch_from_id', $batchIds)->delete();
            Scrap::query()->whereIn('batch_id', $batchIds)->delete();
            Bar::query()->whereIn('batch_id', $batchIds)->delete();

            OrderApproval::query()
                ->where(function ($query) use ($projectIds, $batchIds) {
                    $query->whereIn('batch_id', $batchIds)
                        ->orWhereIn('project_id', $projectIds);
                })
                ->delete();

            //Pieces before orders and batches - pieces.order_id and pieces.batch_id restrict both
            Piece::query()->whereIn('id', $pieceIds)->delete();

            //Orders before quotes - orders.quote_id restricts
            Order::query()->whereIn('id', $orderIds)->delete();
            Quote::query()->whereIn('id', $quoteIds)->delete();

            RawMaterialQuote::query()->whereIn('project_id', $projectIds)->delete();

            /*
             * Reminders about projects that are about to stop existing. The bell reads these off
             * the notification's own payload rather than the project, so without this they would
             * survive the clear-out and keep asking whether a deleted test project was awarded.
             */
            foreach ($projectIds as $projectId) {
                DatabaseNotification::query()
                    ->where('notifiable_type', User::class)
                    ->where('data->project_id', $projectId)
                    ->delete();
            }

            /*
             * notification_logs is deliberately left alone. Nothing writes a project to it - its
             * only writer is SendTrialReminders, which keys rows by business - so matching on
             * unique_model_id here would delete a trial reminder whose business id happened to
             * equal one of these project ids.
             */

            Project::query()
                ->withoutGlobalScope('sandbox')
                ->whereIn('id', $projectIds)
                ->delete();

            Batch::query()
                ->withoutGlobalScope('sandbox')
                ->whereIn('id', $batchIds)
                ->delete();
        });

        return ['projects' => count($projectIds), 'batches' => count($batchIds)];
    }
}
