<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class RawMaterialQuote extends Model
{
    protected $guarded = [];

    /**
     * Relationships
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return HasOne<Piece, $this> */
    public function piece(): HasOne
    {
        return $this->hasOne(Piece::class);
    }

    /**
     * Scopes
     */
    public function scopeOwnedBy(Builder $query, Business $business): Builder
    {
        /**
         * The bulk endpoints address these rows by id in a request body rather
         * than through a bound route parameter, so there is no model for a Gate
         * to check - ownership has to be part of the query itself.
         */
        return $query->whereHas(
            'project.user',
            fn (Builder $user) => $user->where('business_id', $business->id)
        );
    }

    public function scopeOwnedByUser(Builder $query, User $user): Builder
    {
        /**
         * Narrower than ownedBy(), for the endpoints that change a material list rather than read
         * one: clarifying a partial price book match, saving a custom product, deleting rows.
         *
         * The Bill of Materials modal is openable on any colleague's project - the Nesting column
         * is shared - and it hides the upload dropzone and the row-delete checkboxes on somebody
         * else's project. It did not hide the clarification and custom-product forms, and these
         * endpoints scoped only to the business, so the one thing a colleague could not do to your
         * BOM was add to it, while committing product choices and deleting rows outright - the
         * half that does not come back - went through.
         */
        return $query->whereHas(
            'project',
            fn (Builder $project) => $project->where('user_id', $user->id)
        );
    }

    /**
     * Strings
     */
    public function status(): ?string
    {
        $piece = $this->piece;
        $orderedOrder = false;
        $sentQuotes = false;

        if ($piece) {
            //order_sent is cast to bool on the model, so the old "=== 1" would never have matched again
            $orderedOrder = $piece->order ? $piece->order->order_sent : null;

            /**
             * Read through the relation rather than re-querying it: callers listing a
             * whole BOM eager-load piece.quotes, and a query here would defeat that.
             */
            $sentQuotes = $piece->quotes->contains(fn (Quote $quote) => $quote->quote_sent);
        }

        $status = null;
        if ($sentQuotes) {
            $status = 'QUOTED';
        }
        if ($orderedOrder) {
            $status = 'ORDERED';
        }

        return $status;
    }
}
