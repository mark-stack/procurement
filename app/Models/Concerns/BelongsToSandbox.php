<?php

namespace App\Models\Concerns;

use App\Sandbox\Sandbox;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Keeps a model's test rows and its live rows apart, everywhere, without asking the caller.
 *
 * A global scope rather than a scope each query opts into. This application reads projects and
 * batches from around forty places - the board, the nesting column, the offcut inventory, the past
 * projects list, the notification checks, the usage download - and a filter that has to be
 * remembered is a filter that will be forgotten once, which is all it takes for somebody's test
 * project to turn up on a colleague's board or in a supplier's order.
 *
 * Everything downstream of a project or a batch - pieces, material lines, quotes, orders, offcuts,
 * bars, scraps - is reached through one of the two and so is filtered with them. None of them
 * carries a flag of its own.
 *
 * Suppliers, products and templates carry nothing either, and are meant not to: the whole point of
 * the sandbox is to try a real BOM against the real price book and the real supplier list.
 */
trait BelongsToSandbox
{
    public static function bootBelongsToSandbox(): void
    {
        /*
         * Named, so App\Services\SandboxCleaner can lift it - clearing a sandbox is the one job
         * that has to see rows the current mode hides.
         */
        static::addGlobalScope('sandbox', function (Builder $query): void {
            $column = $query->getModel()->qualifyColumn('sandbox_user_id');
            $ownerId = Sandbox::ownerId();

            /*
             * Qualified with the table name because this scope also runs inside joins and
             * existence subqueries - Business::projects() and Business::batches() are
             * hasManyThrough, and piecesReadyForBatching() reaches projects through whereRelation.
             */
            $ownerId === null
                ? $query->whereNull($column)
                : $query->where($column, $ownerId);
        });

        /*
         * Stamped on the way in, so no controller has to know the sandbox exists. Anything created
         * while test mode is on belongs to that user's sandbox; anything created outside it is live.
         */
        static::creating(function (Model $model): void {
            if ($model->sandbox_user_id === null) {
                $model->sandbox_user_id = Sandbox::ownerId();
            }
        });
    }

    public function isSandbox(): bool
    {
        return $this->sandbox_user_id !== null;
    }
}
