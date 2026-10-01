<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use LogicException;

/**
 * One change to one row, as it was made.
 *
 * Written by App\Models\Concerns\RecordsChanges and never by a controller. Read by the admin screens
 * and by whoever is answering an auditor; there is no route that edits one, because there is no such
 * thing as a corrected event.
 *
 * @property string $event
 * @property array<string, mixed> $changes
 */
class RecordChange extends Model
{
    public const CREATED = 'created';

    public const UPDATED = 'updated';

    public const DELETED = 'deleted';

    /**
     * created_at is filled in by Eloquent; there is no updated_at column, because an event does not
     * get a second version.
     */
    public const UPDATED_AT = null;

    /**
     * Guarded rather than fillable, like every other model here. Nothing outside the trait builds one
     * of these anyway - the protection that matters is saving() below.
     */
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'changes' => 'array',
        ];
    }

    /**
     * Append-only, enforced rather than documented.
     *
     * The migration explains the whole arrangement: no updated_at, this guard, and a grant in
     * production that leaves the application's user with INSERT and SELECT. This is the half that
     * holds on a developer's machine and in the test suite, where the grant does not exist - and it
     * is the half that catches the honest mistake, which is a future screen calling update() on a log
     * row because every other model here allows it.
     */
    protected static function booted(): void
    {
        static::updating(function (self $change): void {
            throw new LogicException(
                'A record_changes row cannot be updated - it is what happened, not what is true now.',
            );
        });

        static::deleting(function (self $change): void {
            throw new LogicException(
                'A record_changes row cannot be deleted. Retention is a decision for whoever holds '
                .'the database, not for application code.',
            );
        });
    }

    //Relationships
    /**
     * The row this happened to, or null where it has since been deleted - which is the case this
     * table exists for, so callers have to handle it.
     *
     * @return MorphTo<Model, $this>
     */
    public function record(): MorphTo
    {
        return $this->morphTo(__FUNCTION__, 'record_type', 'record_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The admin who was driving, where this was done under impersonation.
     */
    public function impersonator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'impersonator_user_id');
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    //Local scopes
    /**
     * Every change to one row, newest first.
     *
     * Takes the morph alias and the key rather than the model, so the trail behind a row that has
     * been deleted can still be read - there is no model left to pass.
     *
     * @param  Builder<RecordChange>  $query
     */
    public function scopeForRecord(Builder $query, string $recordType, int $recordId): void
    {
        /*
         * Ordered by id as well as by date, and the id is what actually settles it. created_at has
         * one-second resolution, and the events worth reading are frequently inside one second of each
         * other - a row created and then corrected by the same request, a screen that saves twice. On
         * created_at alone the order of those is whatever the database feels like, so "newest first"
         * was not reliably newest at all.
         */
        $query->where('record_type', $recordType)
            ->where('record_id', $recordId)
            ->orderByDesc('created_at')
            ->orderByDesc('id');
    }

    //Booleans
    public function wasImpersonated(): bool
    {
        return $this->impersonator_user_id !== null;
    }

    //Strings
    /**
     * The columns this event touched, in the order they were written.
     *
     * @return list<string>
     */
    public function columns(): array
    {
        //changes is a NOT NULL json column under an 'array' cast, so it is always an array
        return array_keys($this->changes);
    }

    /**
     * One line naming what happened, for a screen or an export.
     *
     * Deliberately plain: the column names are the application's own, because an auditor reading
     * this alongside the schema is the audience, and a prettified label that does not match any
     * column is worse than the column.
     */
    public function summary(): string
    {
        $columns = $this->columns();

        return match ($this->event) {
            self::CREATED => 'created',
            self::DELETED => 'deleted',
            default => $columns === []
                ? 'saved with no change'
                : 'changed '.implode(', ', $columns),
        };
    }
}
