<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One occasion on which somebody read the master catalogue, and what they found when they did.
 *
 * Appended to, never updated. A review is an event - it happened on a day, to a person, against a
 * catalogue in a particular state - and correcting one later would be correcting the past. An admin
 * who disagrees with what a review recorded records another one.
 *
 * No business_id, for the reason products has none: the master catalogue belongs to the platform,
 * and every business is matched against the same rows. See App\Services\CatalogueTrust, which is
 * what counts the findings stored on each row here.
 *
 * @property \Illuminate\Support\Carbon $reviewed_at
 * @property int $products_reviewed How many active platform products there were at the time
 * @property array<string, int> $untrusted Reason => rows carrying it, keyed by CatalogueTrust
 * @property-read ?User $user Null once the admin who reviewed has been deleted - the date stands
 */
class CatalogueReview extends Model
{
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'reviewed_at' => 'datetime',
            'untrusted' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Most recent first.
     *
     * Ordered by reviewed_at and then by id, not by id alone. The two agree for every review
     * recorded through the screen, but a seeded or backdated row would otherwise sort by when it
     * was written rather than by the date it asserts - and the date is what the record is for.
     *
     * @param  Builder<CatalogueReview>  $query
     */
    public function scopeLatestFirst(Builder $query): void
    {
        $query->orderByDesc('reviewed_at')->orderByDesc('id');
    }
}
