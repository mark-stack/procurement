<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSandbox;
use App\Models\Concerns\RecordsChanges;
use App\Observers\ProjectObserver;
use App\Services\ProductService;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;

/**
 * @property array{notRecognised?: list<string>, otherPlan?: list<string>, couldNotBeRead?: list<string>}|null $items_not_found
 * @property int|null $sandbox_user_id The user whose test mode created this, or null for a real project
 */
#[ObservedBy([ProjectObserver::class])]
class Project extends Model
{
    /**
     * @use HasFactory<\Database\Factories\ProjectFactory>
     *
     * RecordsChanges because the fabrication date is the fixed point every deadline on the Nesting
     * page is counted back from, and it stays editable after the steel has been quoted and bought -
     * which is exactly when a change to it is worth being able to account for. Who moved it, from
     * what to what, and when, is otherwise nowhere: the column is overwritten in place and the page
     * derives every date it prints at read time, so the old deadline leaves no trace at all.
     *
     * It catches the rename too, which is the other thing a project carries that the whole business
     * reads - see RecordsChanges for what model events do and do not pick up.
     */
    use BelongsToSandbox, HasFactory, RecordsChanges;

    /**
     * How long a name and a reference are allowed to be.
     *
     * Here rather than in one of the two form requests, because both of them need the same answer
     * and the reason is the project's, not the form's: a project name is read by the whole business,
     * not just its owner. The column is a TEXT, so nothing in the database was stopping a name of any
     * length at all, and the board is only half the cost of one - the confirm dialogs on the board
     * build their message by joining the names of every project involved ("Start quoting" names whose
     * work is being taken), so one pasted essay makes a dialog nobody can read or reach the button
     * of. 120 is longer than any real job name and short enough to stay a line.
     *
     * The reference is a varchar(255) with only "nullable" in front of it, so anything over 255
     * characters was a database error on MySQL - a 500 where a validation message belonged.
     */
    public const MAX_NAME_CHARACTERS = 120;

    public const MAX_REFERENCE_CHARACTERS = 255;

    protected $guarded = [];

    protected function casts(): array
    {
        /*
         * items_not_found held a PHP-serialized flat list of descriptions, so a corrupt
         * value was a fatal unserialize() at read time and the two very different reasons
         * a line can fail to import were indistinguishable. It is JSON now, keyed by cause.
         */
        return [
            'items_not_found' => 'array',
        ];
    }

    //Relationships
    /**
     * The project manager - the owner, and the one distinction the app draws between colleagues.
     *
     * Typed generically because callers do more with it than read columns: the notifications call
     * $project->user->notify(), which is a Notifiable method and not a Model one, and without the
     * generic every one of those reads as a call to an undefined method on Model.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Whoever uploaded the first material list, when that was not the project manager.
     *
     * Null for the ordinary case - a manager who created their own project - so a caller asking "who
     * did this on somebody's behalf" gets no answer rather than a misleading one. See the migration
     * that adds created_by_user_id for why both people are recorded.
     *
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    //Optional
    public function quotes(): HasMany
    {
        return $this->hasMany(Quote::class);
    }

    public function pieces(): HasMany
    {
        return $this->hasMany(Piece::class);
    }

    /**
     * Has this job been all the way through and come out the other side?
     *
     * True once the project has reached a batch and every batch it reached is done - which is
     * exactly what Past Batches lists, because PastBatchesController reads the same closed
     * batches. Deliberately not the same question as Project::done: the batch flag is written by
     * MarkAsDone and is one-way, while the project flag is the owner's own filing of it.
     *
     * A project that never reached a batch is not finished, it is simply new - hence the first
     * test. Without it every freshly created project would report itself finished, having no
     * pieces on a live batch to say otherwise.
     */
    public function isFinished(): bool
    {
        $onABatch = $this->pieces()->whereNotNull('batch_id');

        return $onABatch->clone()->exists()
            && $onABatch->clone()
                ->whereHas('batch', fn ($query) => $query->where('done', false))
                ->doesntExist();
    }

    /** @return HasMany<RawMaterialQuote, $this> */
    public function rawMaterialQuotes(): HasMany
    {
        return $this->hasMany(RawMaterialQuote::class);
    }

    /**
     * The spreadsheets the material list was uploaded from.
     *
     * Cumulative, like the list itself: a job's steel often arrives over several files as the model
     * is detailed. Empty for everything imported before uploads started being kept.
     *
     * @return HasMany<MaterialListFile, $this>
     */
    public function materialListFiles(): HasMany
    {
        return $this->hasMany(MaterialListFile::class);
    }

    //Unimported BOM lines
    public function unimportedItems(): array
    {
        /**
         * Descriptions from uploaded BOMs that produced no material row, kept apart
         * by cause: a line nothing in the price book matches reads very differently
         * to one that matched fine but sits outside the business's plan, and both
         * read differently again to one we recognised but could not read off the
         * sheet - an unreadable length, or a row that failed outright.
         */
        $stored = is_array($this->items_not_found) ? $this->items_not_found : [];

        return [
            'notRecognised' => array_values(array_unique($stored['notRecognised'] ?? [])),
            'otherPlan' => array_values(array_unique($stored['otherPlan'] ?? [])),
            'couldNotBeRead' => array_values(array_unique($stored['couldNotBeRead'] ?? [])),
        ];
    }

    public function recordUnimportedItems(array $notRecognised, array $otherPlan, array $couldNotBeRead = []): void
    {
        /**
         * Uploads are cumulative, so these are merged with what earlier uploads left behind.
         */
        $existing = $this->unimportedItems();

        $this->items_not_found = [
            'notRecognised' => array_values(array_unique(array_merge($existing['notRecognised'], $notRecognised))),
            'otherPlan' => array_values(array_unique(array_merge($existing['otherPlan'], $otherPlan))),
            'couldNotBeRead' => array_values(array_unique(array_merge($existing['couldNotBeRead'], $couldNotBeRead))),
        ];

        $this->save();
    }

    public function forgetImportedItems(): void
    {
        /**
         * Drop anything that now has a material row. Without this the warning is
         * append-only: a line the user fixed and re-uploaded stays listed forever.
         */
        $existing = $this->unimportedItems();

        if ($existing['notRecognised'] === [] && $existing['otherPlan'] === [] && $existing['couldNotBeRead'] === []) {
            return;
        }

        $imported = $this->rawMaterialQuotes()->pluck('description')->all();

        $remaining = [
            'notRecognised' => array_values(array_diff($existing['notRecognised'], $imported)),
            'otherPlan' => array_values(array_diff($existing['otherPlan'], $imported)),
            'couldNotBeRead' => array_values(array_diff($existing['couldNotBeRead'], $imported)),
        ];

        if ($remaining !== $existing) {
            $this->items_not_found = $remaining;
            $this->save();
        }
    }

    //Collections
    public function orderedProducts(): Collection
    {
        $products = [];

        foreach ($this->orders as $order) {
            foreach ($order->products as $product) {
                $products[] = $product;
            }
        }

        return collect($products);
    }

    //Integers
    public function percentageOfMaterialsQuoted(): int
    {
        /**
         * Based on raw material quote > piece > quote
         *
         * Note: if used batch orders as reference, these aren't actually created
         * until the user moves the cards along the kanban. So if the user has only
         * created a project and imported materials, but done nothing else, there
         * will be no batch objects.
         */
        $quotedRows = 0;
        $materialListRowsCount = $this->rawMaterialQuotes->count();

        foreach ($this->rawMaterialQuotes as $rawMaterialQuote) {
            $piece = $rawMaterialQuote->piece;
            if ($piece) {
                /*
                 * Counted once per material ROW, not once per sent quote. quotes is a belongsToMany
                 * and AttachPiecesToQuote attaches every matching piece to each supplier's quote, so
                 * a business with two steel merchants gave every piece two quotes - and once both
                 * were sent this reported 200%. The question is whether the row has been quoted at
                 * all, so any one sent quote answers it.
                 */
                if ($piece->quotes->contains('quote_sent', true)) {
                    $quotedRows++;
                }
            }
        }

        return $quotedRows > 0
            ? (int) ceil($quotedRows / $materialListRowsCount * 100)
            : 0;
    }

    public function percentageOfMaterialsOrdered(): int
    {
        /**
         * Based on raw_material_quote > piece > order
         */
        $percentageOfMaterialsOrdered = 0;
        $materialListRows = $this->rawMaterialQuotes->count();

        foreach ($this->rawMaterialQuotes as $rawMaterialQuote) {
            $piece = $rawMaterialQuote->piece;
            if ($piece) {
                //PIECE might not have order object yet
                $order = $piece->order;

                if ($order && $order->order_sent) {
                    $percentageOfMaterialsOrdered++;
                }
            }
        }

        return $percentageOfMaterialsOrdered > 0
            ? ceil($percentageOfMaterialsOrdered / $materialListRows * 100)
            : 0;
    }

    /**
     * Whether there is anything left on this project that quoting or ordering could still do.
     *
     * Not percentageOfMaterialsOrdered() === 100, which is what the two deadline reminders used to ask.
     * That figure counts every material row in its denominator, including one that never matched a
     * product and so has no piece to order - correct as a figure, because such a row genuinely is not
     * ordered, but wrong as a stopping condition: the project sat at 99% or lower forever and the
     * reminders chased its manager daily, telling them to "quote/order this batch today" when every
     * orderable line had already been ordered and delivered. There was no way to silence it.
     *
     * An unmatched row is a real problem, and it is reported where it can actually be acted on - the
     * board's Nesting column and Business::projectsWithUnfinishedImport() list it, and the fix is to
     * clarify the line, not to order something. So it does not hold the chasing open.
     *
     * False for a project with nothing orderable at all, so a project whose BOM has not been matched
     * yet is still chased.
     */
    public function everyOrderableRowOrdered(): bool
    {
        $orderable = 0;
        $ordered = 0;

        foreach ($this->rawMaterialQuotes as $rawMaterialQuote) {
            $piece = $rawMaterialQuote->piece;

            if (! $piece) {
                continue;
            }

            $orderable++;

            if ($piece->order && $piece->order->order_sent) {
                $ordered++;
            }
        }

        return $orderable > 0 && $ordered === $orderable;
    }

    /**
     * How long this job's business takes to get prices back from its merchants.
     *
     * Was 2 for every business on the platform. It is a business's own figure now - the one it set in
     * /profile under "Business preferences" - because a fabricator who rings three merchants and gets
     * numbers the same afternoon and one who waits a week for a formal quotation were being chased on
     * the same schedule, and only one of them was being told the truth.
     *
     * Falls back to the platform default where there is no business to ask. That is a Project built
     * in memory rather than read from the database - `(new Project)->criticalPathDays()` is how the
     * notification tests ask what the window is - and a project whose manager's account has gone.
     */
    public function quotingDays(): int
    {
        $business = $this->leadTimeBusiness();

        if ($business === null) {
            return Business::DEFAULT_QUOTING_DAYS;
        }

        return (int) $business->quoting_days;
    }

    /**
     * And how long the longest-lead material on it takes to turn up once it has been bought.
     *
     * Still the business's one figure rather than the material's - the todo that stood here asking for
     * it to be derived from the actual materials is unchanged by this, and a per-product lead time is
     * what would settle it. What has changed is that the figure is no longer the platform's: a mill
     * rolling and a merchant with the section on the rack are weeks apart, and the business knows
     * which of the two it buys from.
     */
    public function longestDeliveryDays(): int
    {
        $business = $this->leadTimeBusiness();

        if ($business === null) {
            return Business::DEFAULT_DELIVERY_DAYS;
        }

        //todo derive from actual materials, rather than from the business's longest
        return (int) $business->delivery_days;
    }

    /**
     * Whose lead times the two above are: this project's manager's business.
     *
     * A method rather than the chain written out at each call site, because the chain is nullable in
     * two places that static analysis cannot see. projects.user_id is a restricting foreign key and
     * users.business_id is nullable, and neither relation is loaded at all on a Project built in
     * memory - `(new Project)->criticalPathDays()` is how the notification tests ask what the window
     * is. Declaring the nullability here is what lets the callers fall back honestly instead of
     * reading a property off null the first time one of those holds.
     */
    private function leadTimeBusiness(): ?Business
    {
        return $this->user?->business;
    }

    public function criticalPathDays(): int
    {
        /**
         * Critical path is quoting time + delivery time
         */
        $materialQuotingDays = $this->quotingDays();
        $longestDeliveryDays = $this->longestDeliveryDays();

        return $materialQuotingDays + $longestDeliveryDays;
    }

    public function daysUntilCriticalPathDeadline(): ?string
    {
        return $this->criticalPathDeadline()?->diffForHumans();
    }

    /**
     * The day this job's steel has to be at the workshop - however this project says so.
     *
     * The typed-in column where there is one, and the fabrication date less one working day where
     * there is not. Those are the same fact recorded two ways: date_materials_required is what the
     * upload form used to ask for, and date_fabrication_begins is what it asks for now (see the
     * add_date_fabrication_begins migration), so a project created either side of that change has
     * exactly one of them.
     *
     * Everything below that counts back from "the day the materials are wanted" reads this rather
     * than the column, which is what stops the deadlines being answerable only for projects created
     * before October. Carbon::parse(null) answers *now*, so reading the column directly did not fail
     * on a modern project - it silently placed the job's deadline a week either side of today.
     *
     * Null only when the project names neither date, which is a project nothing can chase.
     */
    public function materialsRequiredOn(): ?Carbon
    {
        if (filled($this->date_materials_required)) {
            return Carbon::parse($this->date_materials_required);
        }

        return self::materialsRequiredDate($this->date_fabrication_begins);
    }

    /**
     * Datetime
     *
     * The lead times are counted in working days, not calendar ones.
     *
     * A merchant does not price over the weekend and does not deliver on a Sunday, so two days to
     * quote asked on a Friday means Tuesday - counting it as calendar days quietly spent the shop's
     * weekend on the merchant's behalf and made every deadline two days optimistic once a week. The
     * business sets whole working days in /profile, which is what somebody means when they say their
     * merchant takes three days.
     *
     * Carbon::subWeekdays is what steps over the weekend. A figure of zero leaves the date alone -
     * the shop that collects off the rack the morning it needs the steel - which is why these can be
     * read off a date that is itself already a working day without being nudged off it.
     *
     * Null where the project names no date at all, rather than a deadline counted back from today -
     * see materialsRequiredOn().
     */
    public function quotingDeadline(): ?Carbon
    {
        $materialQuotingDays = $this->quotingDays();
        $longestDeliveryDays = $this->longestDeliveryDays();
        $totalDays = $materialQuotingDays + $longestDeliveryDays;

        return $this->materialsRequiredOn()?->subWeekdays($totalDays);
    }

    public function orderingDeadline(): ?Carbon
    {
        $longestDeliveryDays = $this->longestDeliveryDays();
        $totalDays = $longestDeliveryDays;

        return $this->materialsRequiredOn()?->subWeekdays($totalDays);
    }

    public function deliveryDeadline(): ?Carbon
    {
        return $this->materialsRequiredOn();
    }

    public function criticalPathDeadline(): ?Carbon
    {
        return $this->quotingDeadline();
    }

    /**
     * The day a job's material has to be at the workshop, read off the day its fabrication begins.
     *
     * One working day before the first cut: the steel has to be in the shop before the saw starts,
     * and a Monday start wants it there on the Friday rather than on a Sunday nobody can take a
     * delivery on. Carbon::subWeekdays is what steps over the weekend, so the day this answers is
     * always one the business is open.
     *
     * Static and given the date rather than read off $this, because the three callers hold it in
     * three different shapes: the Nesting page works it off the earliest fabrication date among the
     * jobs on a card, the fabrication deadline warning works it off the trigger project, and the bell
     * works it off the date stored on a notification row that may be weeks old. They were one
     * subtraction of one working day in one place and a second one about to be written somewhere
     * else - which is the drift that leaves two screens printing different days for the same steel.
     *
     * Deliberately not the date_materials_required column. That is a date somebody may have typed in
     * and is null on every project created since the fabrication date replaced it as the thing asked
     * for, where this is always answerable for a job that names a fabrication date at all.
     *
     * Null when it does not - projects created before the date was asked for (see the
     * add_date_fabrication_begins migration). Those have no deadline to print and nothing will chase
     * them.
     */
    public static function materialsRequiredDate(?string $dateFabricationBegins): ?Carbon
    {
        return $dateFabricationBegins
            ? Carbon::parse($dateFabricationBegins)->subWeekdays(1)
            : null;
    }

    /**
     * The same day for a set of jobs bought as one - the earliest of them.
     *
     * A batch is nested, quoted and delivered in one go, so its steel is wanted on the day the first
     * of its jobs needs it; a later job on the same batch is not a reason to hold the lot back. That
     * is the day the Nesting page's cards print and are coloured off.
     *
     * Here rather than in the page that draws it, because it is now asked in two places: by the card,
     * and by the edit that moves a project's fabrication date - which has to know whether the batch's
     * day actually changed before telling the other managers on it that it did. Two copies of this
     * would let the card and the notification name different days for the same steel.
     *
     * Null when no job on the batch names a fabrication date - see materialsRequiredDate.
     *
     * @param  Collection<int, Project>  $projects
     */
    public static function earliestMaterialsRequiredDate(Collection $projects): ?Carbon
    {
        $earliest = $projects
            ->pluck('date_fabrication_begins')
            ->filter()
            ->map(fn ($date) => Carbon::parse($date))
            ->min();

        return self::materialsRequiredDate($earliest?->toDateString());
    }

    /**
     * Is this job inside the window where its materials are due to be quoted and ordered?
     *
     * Critical path = quoting time + delivery time. "Due" is the one day between [critical path + 1]
     * and [critical path] working days before the steel is wanted.
     *
     * This was a query scope - Project::query()->dueForQuotingAndOrdering() - and that is what was
     * wrong with it. A local scope runs on a bare model built by the query builder, so $this->user
     * was null inside it and criticalPathDays() fell all the way back to the platform defaults of
     * 2 + 3. Every business on the platform was chased on a five-working-day path, whatever the two
     * figures it had set in /profile said, while quotingDeadline() on the same project answered with
     * the business's own - the two were documented as having to name the same window and could not.
     * Asked of a real project, criticalPathDays() walks to the manager's business and gets it right.
     *
     * Working days throughout, matching the deadlines above and the card on the Nesting page.
     *
     * False for a project that names no date at all: there is no path to be due against, and nothing
     * should be chasing it.
     */
    public function isDueForQuotingAndOrdering(): bool
    {
        $requiredOn = $this->materialsRequiredOn();

        if ($requiredOn === null) {
            return false;
        }

        $startRange = Carbon::now()->addWeekdays($this->criticalPathDays())->startOfDay();
        $endRange = Carbon::now()->addWeekdays($this->criticalPathDays() + 1)->endOfDay();

        return $requiredOn->betweenIncluded($startRange, $endRange);
    }

    /**
     * And past it: less than [critical path] working days before the steel is wanted.
     *
     * Cut at the START of the day isDueForQuotingAndOrdering() opens on, not the end of it. The two
     * windows used to overlap across that whole day - a materials date exactly the critical path
     * away satisfied both - so on that one day the project manager got "the materials are due to be
     * quoted" and "the deadline has passed, quote today" about the same project in the same hourly
     * run. Two reminders that contradict each other teach people to read neither.
     *
     * "<" against the same instant "due" opens on, so the boundary belongs to exactly one of them
     * and there is no day in between that neither claims. Working days on both sides, for the same
     * reason: the boundary is only shared while the two count the same way.
     */
    public function isOverdueForQuotingAndOrdering(): bool
    {
        $requiredOn = $this->materialsRequiredOn();

        if ($requiredOn === null) {
            return false;
        }

        return $requiredOn->lessThan(
            Carbon::now()->addWeekdays($this->criticalPathDays())->startOfDay(),
        );
    }

    //Local scopes
    public function scopeActive(Builder $query): void
    {
        /**
         * Not done. The four hourly notification checks have always called this - it was
         * never defined, so every one of them died on a BadMethodCallException the moment the
         * job ran, and the "don't chase a project that is done" rule they each document went
         * with them.
         */
        $query->where('done', false);
    }

    /*
     * scopeWithoutBatch() and scopeWithBatch() lived here and are gone with their only caller,
     * Business::currentProjects(). withoutBatch() was whereRelation("pieces.batch", "done", false),
     * which matches a project that HAS a batch - the opposite of its name, and a trap for the next
     * person to reach for it. scopeUnBatchedPieces() below is the one that answers what it claimed to.
     */

    /**
     * The projects the two deadline reminders have to consider, cheaply.
     *
     * Which of them are actually due is isDueForQuotingAndOrdering() and its overdue twin, and both
     * of those have to be asked of a real project - they read the manager's business for its lead
     * times, and half the projects on the platform carry the day the steel is wanted as a fabrication
     * date rather than as the typed-in column. Neither question can be put to the database.
     *
     * So this is the part that can: a project naming no date at all can never be due, and there is no
     * point loading one. The manager and their business come with it because every candidate is about
     * to be asked for both.
     */
    public function scopeDatedAndChaseable(Builder $query): void
    {
        $query->with('user.business')
            ->where(function (Builder $query) {
                $query->whereNotNull('date_materials_required')
                    ->orWhereNotNull('date_fabrication_begins');
            });
    }

    public function scopeThisBusiness(Builder $query, Business $business): void
    {
        $staffIds = $business->users()->get()->pluck('id')->toArray();

        $query->whereIn('user_id', $staffIds);
    }

    /**
     * May this user change the material list on this project?
     *
     * Its manager, or whoever uploaded it for them - and nobody else. Deliberately narrower than the
     * business and deliberately wider than user_id alone:
     *
     *  - Wider, because a draftsman who uploads for a project manager has to be able to finish what
     *    they started. Materials arrive in several files over several days, and an import that stops
     *    at a price book clarification stops mid-upload - leaving it to a manager who has never seen
     *    the spreadsheet is leaving it undone.
     *  - Narrower than the business, because the Nesting column is shared: every colleague can open
     *    every BOM, and the one thing they may not do is change one that is nothing to do with them.
     *
     * This is the question behind PrerequisiteConditions::uploadMaterials and
     * RawMaterialQuote::scopeOwnedByUser, and the two must keep agreeing - the first decides whether
     * the Bill of Materials modal draws its upload dropzone, its row-delete checkboxes and its
     * clarification forms, and the second is what the endpoints behind them scope to. A control drawn
     * by one that the other refuses is a button that can only answer 403.
     *
     * NOT the question behind editProject or markProjectDone. Renaming a job, moving its materials
     * date and taking it off the board are the manager's call, and an uploader has no more say in
     * them than any other colleague.
     */
    public function isManagedBy(User $user): bool
    {
        return $this->user_id === $user->id || $this->created_by_user_id === $user->id;
    }

    /**
     * The query form of isManagedBy - the projects whose material lists this user may change.
     *
     * Grouped, because every caller already has conditions of its own and an unparenthesised orWhere
     * would escape them: "yours or created by you" ANDed with "not done" reads as "yours and not
     * done, or created by you" without the nesting.
     */
    public function scopeManagedBy(Builder $query, int $userId): void
    {
        $query->where(function (Builder $query) use ($userId) {
            $query->where('user_id', $userId)
                ->orWhere('created_by_user_id', $userId);
        });
    }

    public function scopeUnBatchedPieces(Builder $query): void
    {
        $query->whereRelation('pieces', 'batch_id', '=', null);
    }

    public function scopeSortByUserAndLatest(Builder $query): Builder
    {
        return $query->orderByRaw('user_id = ? DESC', [Auth::id()])
            ->orderBy('created_at', 'DESC');
    }
}
