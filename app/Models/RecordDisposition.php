<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * One deliberate act of throwing records away.
 *
 * Written by App\Console\Commands\DisposeOfExpiredRecords and by nothing else. There is no screen
 * that creates one, because a disposal is not something that happens while you are doing something
 * else - somebody sits down, reads what is eligible, and decides.
 *
 * Append-only for the same reason RecordChange is: a record of a disposal that can be rewritten
 * afterwards answers no question anybody would ask it.
 *
 * @property string $record_class
 * @property string $method
 * @property \Illuminate\Support\Carbon $cutoff
 * @property int $retain_days
 * @property int $eligible
 * @property int $disposed
 */
class RecordDisposition extends Model
{
    /**
     * The application deleted it, here, as part of the command run that wrote this row.
     */
    public const BY_APPLICATION = 'application';

    /**
     * The application cannot delete it and said so. The change log is the case: production grants
     * the application INSERT and SELECT on record_changes and nothing else, so the command records
     * the authorisation and prints the statement for whoever holds the database to run.
     *
     * eligible is what was in scope at that moment; disposed is 0, because this row is the
     * authorisation rather than the deed.
     */
    public const BY_DBA = 'handed-to-dba';

    /**
     * created_at is filled in by Eloquent; there is no updated_at column, because an act does not
     * get a second version.
     */
    public const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'cutoff' => 'datetime',
            'retain_days' => 'integer',
            'eligible' => 'integer',
            'disposed' => 'integer',
        ];
    }

    /**
     * Append-only, enforced rather than documented - see App\Models\RecordChange, which carries the
     * same two guards and the same reasoning.
     */
    protected static function booted(): void
    {
        static::updating(function (self $disposition): void {
            throw new LogicException(
                'A record_dispositions row cannot be updated - it is what was done, not what is true now.',
            );
        });

        static::deleting(function (self $disposition): void {
            throw new LogicException(
                'A record_dispositions row cannot be deleted. A disposal that can be unrecorded is '
                .'not a controlled disposal.',
            );
        });
    }

    //Relationships
    /**
     * The account that authorised it, or null where that account has since been deleted. The typed
     * name in authorised_by is the half that survives, which is why both columns exist.
     */
    public function authorisedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'authorised_by_user_id');
    }

    //Booleans
    public function wasCarriedOutHere(): bool
    {
        return $this->method === self::BY_APPLICATION;
    }

    //Strings
    /**
     * One line naming what happened, for a screen or an export.
     */
    public function summary(): string
    {
        $rule = $this->retain_days.' days, cutoff '.$this->cutoff->toDateString();

        return $this->wasCarriedOutHere()
            ? "disposed of {$this->disposed} of {$this->eligible} eligible {$this->record_class} ({$rule})"
            : "authorised disposal of {$this->eligible} {$this->record_class}, handed to the database administrator ({$rule})";
    }
}
