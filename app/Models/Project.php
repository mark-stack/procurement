<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSandbox;
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
    /** @use HasFactory<\Database\Factories\ProjectFactory> */
    use BelongsToSandbox, HasFactory;

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
    public function suppliers(): Collection
    {
        $suppliers = [];

        foreach ($this->orders as $order) {
            $suppliers[] = $order->supplier;
        }

        return collect($suppliers);
    }

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

    public function quotingDays(): int
    {
        return 2;
    }

    public function longestDeliveryDays(): int
    {
        return 4; //todo derive from actual materials. Fallback = 3 days
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

    public function daysUntilCriticalPathDeadline(): string
    {
        return $this->criticalPathDeadline()->diffForHumans();
    }

    //Datetime
    public function quotingDeadline(): Carbon
    {
        $materialQuotingDays = $this->quotingDays();
        $longestDeliveryDays = $this->longestDeliveryDays();
        $totalDays = $materialQuotingDays + $longestDeliveryDays;

        return Carbon::parse($this->date_materials_required)->subDays($totalDays);
    }

    public function orderingDeadline(): Carbon
    {
        $longestDeliveryDays = $this->longestDeliveryDays();
        $totalDays = $longestDeliveryDays;

        return Carbon::parse($this->date_materials_required)->subDays($totalDays);
    }

    public function deliveryDeadline(): Carbon
    {
        return Carbon::parse($this->date_materials_required);
    }

    public function criticalPathDeadline(): Carbon
    {
        return $this->quotingDeadline();
    }

    //Local scopes
    public function scopeDueForQuotingAndOrdering(Builder $query): void
    {
        /**
         * Critical path = quoting time + delivery time
         * Between [critical path + 1 day] and [critical path] days before planned project material received date
         */
        $startRange = Carbon::now()->addDays($this->criticalPathDays())->startOfDay();
        $endRange = Carbon::now()->addDays($this->criticalPathDays() + 1)->endOfDay();

        $query->whereBetween('date_materials_required', [$startRange, $endRange]);
    }

    public function scopeActive(Builder $query): void
    {
        /**
         * Not archived. The four hourly notification checks have always called this - it was
         * never defined, so every one of them died on a BadMethodCallException the moment the
         * job ran, and the "don't chase an archived project" rule they each document went
         * with them.
         */
        $query->where('archive', false);
    }

    /*
     * scopeWithoutBatch() and scopeWithBatch() lived here and are gone with their only caller,
     * Business::currentProjects(). withoutBatch() was whereRelation("pieces.batch", "done", false),
     * which matches a project that HAS a batch - the opposite of its name, and a trap for the next
     * person to reach for it. scopeUnBatchedPieces() below is the one that answers what it claimed to.
     */

    public function scopeOverdueForQuotingAndOrdering(Builder $query): void
    {
        /**
         * Critical path = quoting time + delivery time
         * Less than [critical path] before planned project material received date
         *
         * Cut at the START of the day dueForQuotingAndOrdering() opens on, not the end of it. The two
         * windows used to overlap across that whole day - a materials date exactly the critical path
         * away satisfied both - so on that one day the project manager got "the materials are due to be
         * quoted" and "the deadline has passed, quote today" about the same project in the same hourly
         * run. Two reminders that contradict each other teach people to read neither.
         *
         * "<" against the same instant "due" opens on, so the boundary belongs to exactly one of them
         * and there is no day in between that neither claims.
         */
        $deadline = Carbon::now()->addDays($this->criticalPathDays())->startOfDay();

        // Query the database
        $query->where('date_materials_required', '<', $deadline);
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
     * NOT the question behind editProject or archiveProject. Renaming a job, moving its materials
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
     * would escape them: "yours or created by you" ANDed with "not archived" reads as "yours and not
     * archived, or created by you" without the nesting.
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
