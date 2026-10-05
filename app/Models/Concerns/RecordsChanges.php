<?php

namespace App\Models\Concerns;

use App\Models\RecordChange;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Facades\Auth;

/**
 * Write a record_changes row every time this model is created, changed or deleted.
 *
 * Model events rather than a call at each call site, for the reason BelongsToSandbox gives about its
 * global scope: a line that has to be remembered is a line that will be forgotten once, and once is
 * enough to leave the one change an auditor asks about unrecorded. Order, Piece, MaterialCertificate,
 * Product, Template and Project carry this, and between them they hold what was bought, what was cut,
 * the evidence behind it, the catalogue it was matched against, the rules the demand was read with -
 * and, with the project, the date all of it was being bought against.
 *
 * What this does NOT catch, and deliberately:
 *
 *   Query-builder writes. Order::query()->where(...)->update() fires no model events, so it writes no
 *   log row. Three places do that on purpose and are better off for it - SandboxCleaner tearing down
 *   test data, MaterialsJsonImport's bulk catalogue merge (which writes its own report), and
 *   BatchController unwinding a nest (whose own gate is what guarantees nothing ordered is in it).
 *   Anything a person does through a screen goes through a model.
 *
 *   Reads. ISO 9001 does not ask who looked, and a log that grew on every page view would bury the
 *   handful of rows that matter.
 */
trait RecordsChanges
{
    /**
     * Columns no event records, on every model that uses this.
     *
     * The timestamps say the same thing as the log row's own created_at, and "updated_at moved" is
     * what every save looks like whether or not anything happened.
     *
     * @var list<string>
     */
    private const NEVER_RECORDED = ['created_at', 'updated_at'];

    public static function bootRecordsChanges(): void
    {
        /*
         * Typed as self rather than as Model. At runtime they are the same thing - the trait is only
         * ever composed into a model - but static analysis reads a Model parameter as a plain Model and
         * so cannot see the trait's own methods on it, which is six "undefined method" reports per
         * model that uses this. In a trait, self resolves to the composing class.
         */
        static::created(function (self $model): void {
            /*
             * The whole row. A create has no "before", and the alternative - recording that a row was
             * created and nothing about what it said - is the version of this that cannot answer a
             * question.
             */
            $model->writeRecordChange(RecordChange::CREATED, $model->recordableAttributes());
        });

        static::updated(function (self $model): void {
            $changes = $model->recordableChanges();

            /*
             * Nothing moved but the timestamp. A save with no change is not an event, and recording it
             * would mean every page that re-saves a row it did not alter leaves a row in the log
             * saying so.
             */
            if ($changes === []) {
                return;
            }

            $model->writeRecordChange(RecordChange::UPDATED, $changes);
        });

        static::deleted(function (self $model): void {
            /*
             * The whole row again, and this is the case the table earns its keep on: once the row is
             * gone this is the only place that says what it held. "A certificate was deleted" is not
             * an answer; "cert-4471882.pdf, 2.1MB, uploaded by Jenny on the 14th, deleted by Dave on
             * the 20th" is.
             */
            $model->writeRecordChange(RecordChange::DELETED, $model->recordableAttributes());
        });
    }

    /**
     * Every change ever recorded against this row, newest first.
     *
     * @return MorphMany<RecordChange, $this>
     */
    public function recordChanges(): MorphMany
    {
        //Ordered the same way the scope is, and for the same reason - see RecordChange::scopeForRecord
        return $this->morphMany(RecordChange::class, 'record', 'record_type', 'record_id')
            ->orderByDesc('created_at')
            ->orderByDesc('id');
    }

    /**
     * Columns this model keeps out of its log, on top of the timestamps.
     *
     * Override where a column is noise or is not ours to repeat. Nothing needs it yet - none of the
     * six holds a secret - but a log is the wrong place to discover that it does.
     *
     * @return list<string>
     */
    protected function changeLogExcept(): array
    {
        return [];
    }

    /**
     * Whose records this change belongs to.
     *
     * The row's own business where it has one (a Template belongs to exactly one), and the acting
     * user's otherwise - an order or a piece belongs to the business of whoever is working on it.
     * Override to return null for anything that belongs to no business, which is what Product does:
     * the master catalogue is the platform's, and filing an edit of it under the admin's own business
     * would read as that business having changed a shared row.
     */
    protected function changeLogBusinessId(): ?int
    {
        $own = $this->getAttribute('business_id');

        if (is_numeric($own)) {
            return (int) $own;
        }

        return Auth::user()?->business_id;
    }

    /**
     * The row as it stands, in storage form, minus the columns nothing records.
     *
     * Storage form - getAttributes() rather than a cast read - so what lands in the json is what
     * landed in the column. A Carbon instance, an enum or a cast array would each encode a second way
     * of writing the same value, and two spellings of one value in a log is one too many.
     *
     * @return array<string, mixed>
     */
    protected function recordableAttributes(): array
    {
        return array_diff_key(
            $this->getAttributes(),
            array_flip([...self::NEVER_RECORDED, ...$this->changeLogExcept()]),
        );
    }

    /**
     * What this save moved, as {column: [before, after]}.
     *
     * Read inside the "updated" event, where getChanges() is already the new values and getRawOriginal()
     * is still the old ones - Eloquent does not sync the original attributes until after the event has
     * fired. Both sides are storage form, for the reason above.
     *
     * @return array<string, array{0: mixed, 1: mixed}>
     */
    protected function recordableChanges(): array
    {
        $ignored = array_flip([...self::NEVER_RECORDED, ...$this->changeLogExcept()]);

        $changes = [];

        foreach ($this->getChanges() as $column => $after) {
            if (array_key_exists($column, $ignored)) {
                continue;
            }

            $changes[$column] = [$this->getRawOriginal($column), $after];
        }

        return $changes;
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    protected function writeRecordChange(string $event, array $changes): void
    {
        RecordChange::create([
            //The morph alias, from the map in AppServiceProvider - never the class name
            'record_type' => $this->getMorphClass(),
            'record_id' => $this->getKey(),
            'event' => $event,
            'changes' => $changes,
            'user_id' => Auth::id(),
            'impersonator_user_id' => self::actingImpersonatorId(),
            'business_id' => $this->changeLogBusinessId(),
            'created_at' => now(),
        ]);
    }

    /**
     * The admin behind the user, where this request is an impersonation.
     *
     * AdminImpersonationController puts their id in the session and nothing else carries it: once
     * Auth::login() has run, the request says the impersonated user is doing this, which is what makes
     * the feature work and what made every record it touched untrue.
     *
     * Guarded on hasSession() because this runs from the queue and the scheduler too - the hourly
     * notification job and the quarterly cleanout both save models - and asking an unbooted session
     * for a key throws.
     */
    private static function actingImpersonatorId(): ?int
    {
        $request = request();

        if (! $request->hasSession()) {
            return null;
        }

        $impersonatorId = $request->session()->get('impersonator_id');

        return is_numeric($impersonatorId) ? (int) $impersonatorId : null;
    }
}
