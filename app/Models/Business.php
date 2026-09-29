<?php

namespace App\Models;

use App\Billing\Billing;
use App\Billing\SubscriptionState;
use App\Enums\SupplierGroupEnums;
use App\Formatters\SupplierFormatter;
use App\Services\ProductService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Support\Collection;
use Illuminate\Support\Carbon;
use Laravel\Cashier\Billable;

/**
 * The billing columns are declared here because casts() is a method rather than a $casts array, and
 * static analysis cannot see through that - without these, every isFuture() and isPast() call on a
 * trial date reads as a method call on a string.
 *
 * @property Carbon|null $trial_ends_at
 * @property string|null $manual_plan
 * @property Carbon|null $manual_access_until
 * @property string|null $stripe_id
 */
class Business extends Model
{
    /*
     * The one seam where a payment provider touches a model.
     *
     * Cashier is Stripe-specific and laravel/cashier-paddle is a different package with a trait of
     * the same name, so swapping providers swaps this line. Nothing outside app/Billing/Drivers may
     * call what it adds - subscribed(), newSubscription(), onTrial() and the rest. Ask
     * billingState() instead, which answers in the application's own vocabulary and does not care
     * who is taking the money.
     */
    use Billable;

    protected $guarded = [];

    /**
     * Resolved once per instance: the billing banner, the read-only gate and the nav all ask, and
     * with the Stripe driver each ask would otherwise be another subscriptions query.
     */
    private ?SubscriptionState $billingState = null;

    /**
     * Resolved once per instance, for the reason spelled out on projectsRequiringClarification().
     */
    private ?Collection $projectsRequiringClarificationMemo = null;

    /*
     * Defaults for the nesting settings, mirroring the column defaults in the migrations.
     *
     * A column default only fills the ROW. Business::create() is called with a handful of fields, so the
     * instance handed back had no scrap_threshold_mm attribute at all, and reading a missing attribute
     * gives null - which means "$drop >= $business->scrap_threshold_mm" was really "$drop >= 0" and every
     * offcut, down to a millimetre, counted as reusable stock to be banked as an offcut. Nesting only got
     * the intended 1,000mm threshold if the model happened to be re-read from the database first.
     *
     * Declared here rather than guarded against at each use so every consumer sees the same numbers, and
     * so a freshly created business nests the same way it will after the next page load.
     */
    protected $attributes = [
        'scrap_threshold_mm' => 1000,
        'kerf_mm' => 0,
        /*
         * cap_12m_stock has the same problem: null on a newly created business, which is falsy, so the
         * 12m delivery cap silently did not apply until the model was read back from the database.
         */
        'cap_12m_stock' => true,
        'labour_rate_per_hour' => 50.00,
        'material_cost_per_tonne' => 2000.00,
        'scrap_recovery_rate' => 0.13,
        'default_kg_per_m' => 10.0,
        'cut_base_minutes' => 1.5,
        'cut_minutes_per_kg_per_m' => 0.06,
        'offcut_draw_base_minutes' => 4.0,
        'bar_handling_base_minutes' => 3.0,
        'offcut_rack_base_minutes' => 6.0,
        'move_minutes_per_tonne' => 15.0,
        'offcut_retention_cap' => 0.6,
        'purchase_cost_weight' => 1.0,
    ];

    /*
     * These four are boolean columns in the migration, but without a cast the type that
     * reaches json is whatever the driver hands back - int on mysql, the string "0" on
     * sqlite. The admin users page compares admin_setup_complete with ===, so its Ready
     * column silently emptied out under the test connection while it worked in production.
     */
    protected function casts(): array
    {
        return [
            'admin_setup_complete' => 'boolean',
            'cap_12m_stock' => 'boolean',
            'meterage_only' => 'boolean',
            'allow_custom_products' => 'boolean',
            /*
             * Both are compared against now() to decide whether the account is read-only, so a
             * string reaching that comparison would be a lockout or a free ride depending on the
             * driver's date format.
             */
            'trial_ends_at' => 'datetime',
            'manual_access_until' => 'datetime',
        ];
    }

    /**
     * The free trial starts when the business does.
     *
     * Here rather than in the registration controller because a business is created from several
     * places - registration, seeders, the admin screens, test factories - and a business with no
     * trial is read-only from its first minute. One rule, no path that can forget it.
     *
     * Already-set values are respected, so a fixture or a seeder can dictate its own dates.
     */
    protected static function booted(): void
    {
        static::creating(function (Business $business): void {
            if ($business->trial_ends_at === null) {
                $business->trial_ends_at = now()->addDays((int) config('billing.trial_days'));
            }
        });
    }

    /**
     * What this business is entitled to: on trial, paying, lapsed. See App\Billing\Billing.
     */
    public function billingState(): SubscriptionState
    {
        return $this->billingState ??= app(Billing::class)->state($this);
    }

    /**
     * Whether the account may still change anything. False is read-only - every page and download
     * stays open, writes are refused - not locked out. See BillingWriteAccessMiddleware.
     */
    public function allowsWrites(): bool
    {
        return $this->billingState()->allowsWrites();
    }

    /**
     * Who Stripe should show as the customer. Cashier's default reads $this->email, and a business
     * has no email of its own, so without this the Stripe dashboard is a list of blank rows.
     *
     * The earliest user is the one who signed the company up, which is the closest thing to an
     * account owner this schema has.
     */
    public function stripeEmail(): ?string
    {
        return $this->users()->oldest('id')->value('email');
    }

    /**
     * @return array<string, string>
     */
    public function stripeMetadata(): array
    {
        //So a payment in the Stripe dashboard can be traced back to a business without a lookup
        return [
            'business_id' => (string) $this->id,
            'domain' => (string) $this->domain,
        ];
    }

    //Relationships
    /**
     * @return HasMany<User, $this>
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function suppliers(): BelongsToMany
    {
        return $this->belongsToMany(Supplier::class);
    }

    /**
     * Annotated because select() on this relation otherwise hands back a collection of
     * plain Models, so anything mapping over the rows loses the Template type.
     *
     * @return HasMany<Template, $this>
     */
    public function templates(): HasMany
    {
        return $this->hasMany(Template::class);
    }

    /**
     * The templates an upload of this business's is actually matched against.
     *
     * The same two conditions CsvService::eligibleTables() imports under, which is what makes this
     * countable as "templates available to this business": a deactivated row, or one recorded
     * before templates carried the heading row that finds the table, detects nothing.
     *
     * @return HasMany<Template, $this>
     */
    public function detectableTemplates(): HasMany
    {
        return $this->templates()->where('active', true)->detectable();
    }

    public function quotes(): HasManyThrough
    {
        return $this->hasManyThrough(Quote::class, User::class);
    }

    public function projects(): HasManyThrough
    {
        return $this->hasManyThrough(Project::class, User::class);
    }

    public function batches(): HasManyThrough
    {
        return $this->hasManyThrough(Batch::class, User::class);
    }

    //Local scopes


    //Boolean
    public function supplierGroupIsCurrentPlan($supplierGroup): bool
    {
        /**
         * Filter out supplier groups
         */

        $supplierGroupIsCurrentPlan = true;
        if ($supplierGroup === SupplierGroupEnums::PROFILE_CUTTING->value) {
            $supplierGroupIsCurrentPlan = false;
        }

        return $supplierGroupIsCurrentPlan;
    }

    //Collection
    public function projectsReadyForBatching(Collection $piecesReadyForBatching): Collection
    {
        $requiringClarification = $this->projectsRequiringClarification()->pluck("id")->toArray();

        /*
         * Eager loaded the way Batch::projects() is, and for the same reason: ProjectResource walks
         * user, rawMaterialQuotes and pieces for every card it draws. Unloaded, that was two queries
         * per material row per project - a project with 50 BOM lines cost 110 queries to draw one card.
         */
        return Project::query()
            ->with(['user', 'rawMaterialQuotes', 'pieces.batch'])
            ->has("rawMaterialQuotes")
            ->whereNotIn("id",$requiringClarification)
            ->whereIn("id",$piecesReadyForBatching->pluck("project_id")->toArray())
            ->get();
    }

    /**
     * Projects with a partial price book match still to be confirmed.
     *
     * Memoised: this runs a price book match per material row of every project the business has
     * ever had, and the board asks for it three times over on a single render -
     * projectsReadyForBatching() is called twice by ProjectController, and the Nesting column
     * asks again to list the ones stuck here.
     */
    public function projectsRequiringClarification(): Collection
    {
        if ($this->projectsRequiringClarificationMemo !== null) {
            return $this->projectsRequiringClarificationMemo;
        }

        $projectsRequiringClarification = [];

        //Services
        $productService = new ProductService();

        foreach($this->projects as $project){
            $partialProductMatches = [];
            foreach ($project->rawMaterialQuotes as $rawMaterialQuote) {
                $getProductMatchOptions = $productService->getProductMatchOptions($this, $rawMaterialQuote);

                if ($getProductMatchOptions) {
                    /*
                     * Price book partial match (requires confirmation)
                     */
                    if ($getProductMatchOptions['status'] === 'PARTIAL') {
                        $partialProductMatches[] = [
                            'selected' => null,
                            'data' => $rawMaterialQuote,
                            'options' => $getProductMatchOptions['decodedOptions'],
                            'custom' => $getProductMatchOptions['custom'],
                        ];
                    }
                }
            }

            if (count($partialProductMatches) > 0) {
                $projectsRequiringClarification[] = $project;
            }
        }

        return $this->projectsRequiringClarificationMemo = collect($projectsRequiringClarification);
    }

    /**
     * The subset of those that belong on the board: still live, and not already nested.
     *
     * projectsReadyForBatching() excludes a project with an unconfirmed partial match, and the
     * Nesting column is the only place a pre-batch project is ever drawn - so an import left half
     * finished fell off the board entirely, for its owner as well as for everybody else. Nothing
     * else lists it, and the archived list holds only your own archived projects, so there was no
     * route back to it at all: a colleague could not so much as discover it existed.
     */
    public function projectsWithUnfinishedImport(): Collection
    {
        return $this->projectsRequiringClarification()
            ->reject(fn (Project $project) => $project->archive)
            ->filter(fn (Project $project) => $project->pieces()->whereNotNull('batch_id')->doesntExist())
            ->sortBy('created_at')
            ->values();
    }

    public function currentProjects(): Collection
    {
        /**
         * Projects without batch (pre-nesting) + Active batches (not 'done')
         */

        //Projects without batch yet
        $projectsWithoutBatchIds = $this->projects()
            ->where("archive",false)
            ->withoutBatch()
            ->get()
            ->pluck("id")
            ->toArray();

        //Projects in Active batches
        $activeBatches = $this->batches()
            ->active()
            ->get();
        $projectsInActiveBatchesIds = [];
        foreach($activeBatches as $activeBatch){
            foreach($activeBatch->projects() as $project){
                $projectsInActiveBatchesIds[] = $project->id;
            }
        }

        $combinedCurrentProjectIds = array_merge($projectsWithoutBatchIds,$projectsInActiveBatchesIds);
        $combinedCurrentProjectIds = array_unique($combinedCurrentProjectIds);

        return Project::query()
            ->whereIn("id",$combinedCurrentProjectIds)
            ->get();
    }

    public function pastProjects(): Collection
    {
        /**
         * Projects which have batch, and batch = "done"
         */
        $inactiveBatches = $this->batches()
            ->inactive()
            ->get();

        $pastProjects = [];
        foreach($inactiveBatches as $inactiveBatch){
            foreach($inactiveBatch->projects() as $project){
                $pastProjects[] = $project->id;
            }
        }

        return Project::query()
            ->whereIn("id",$pastProjects)
            ->get();
    }

    /**
     * @return Builder<Offcut>
     */
    public function availableOffcuts(): Builder
    {
        /**
         * Offcuts from this business's batches that are still unassigned, and whose source batch has a
         * delivered order from the supplier category that stocks the offcut's product.
         *
         * Ordered, because nesting consumes this list and an unordered query made the nest depend on
         * whatever order the database happened to return rows in. The same pieces could be nested twice
         * (once for the suggestion, once by Actions/Batch/SaveNesting) and produce different cut plans.
         */
        return $this->offcutsInInventory()
            /*
             * Steel somebody took, cut up off-system, damaged or cannot find. The row is still there -
             * its descendants' certificates run through it - but the material is not in the yard, so
             * neither the inventory page nor the nest may promise it. See Offcut::removeFromInventory.
             */
            ->whereNull('removed_at')
            //Shortest first, then by id, so the list a nest is built from is always the same list
            ->orderBy('length')
            ->orderBy('id');
    }

    /**
     * The offcuts somebody took out of inventory by hand, most recently removed first.
     *
     * The same set availableOffcuts() draws on, on the other side of the removal flag: these are the
     * rows the offcuts page has to be able to show and put back, not a separate kind of record.
     *
     * @return Builder<Offcut>
     */
    public function removedOffcuts(): Builder
    {
        return $this->offcutsInInventory()
            ->whereNotNull('removed_at')
            ->orderByDesc('removed_at')
            ->orderByDesc('id');
    }

    /**
     * Every offcut this business has that is steel rather than history: cut from one of its batches,
     * not yet consumed by a nest, and delivered.
     *
     * Resolved in SQL. This used to hydrate every unassigned offcut and call Offcut::deliveredOrder()
     * on each one - a batch lookup, a full supplierGroups() rebuild and an orders query per offcut -
     * then throw the models away and re-query by id.
     *
     * @return Builder<Offcut>
     */
    private function offcutsInInventory(): Builder
    {
        //Supplier categories with the products they stock. e.g "STEEL_MERCHANT" contains "PFC, UB, etc"
        $categories = (new SupplierFormatter)->supplierGroups($this);

        if (count($categories) === 0) {
            return Offcut::query()->whereRaw('1 = 0');
        }

        return Offcut::query()
            ->whereIn('batch_from_id', $this->batches()->select('batches.id'))
            ->whereNull('batch_to_id')
            ->where(function (Builder $query) use ($categories) {
                foreach ($categories as $supplierCategory => $productCategories) {
                    $query->orWhere(function (Builder $query) use ($supplierCategory, $productCategories) {
                        $query->whereIn('product_category', $productCategories)
                            ->where(function (Builder $query) use ($supplierCategory) {
                                $query
                                    //Cut from new stock: it is only in the yard once that order lands
                                    ->whereExists(function ($query) use ($supplierCategory) {
                                        $query->selectRaw('1')
                                            ->from('orders')
                                            ->whereColumn('orders.batch_id', 'offcuts.batch_from_id')
                                            ->where('orders.is_delivered', true)
                                            ->whereExists(function ($query) use ($supplierCategory) {
                                                $query->selectRaw('1')
                                                    ->from('quotes')
                                                    ->whereColumn('quotes.id', 'orders.quote_id')
                                                    ->where('quotes.supplier_category', $supplierCategory);
                                            });
                                    })
                                    /*
                                     * Cut from an offcut that was already in the yard. The material was
                                     * delivered against the SOURCE offcut, not against this batch - and a
                                     * batch that nested entirely out of inventory places no order at all,
                                     * so requiring a delivered order here hid these offcuts forever.
                                     */
                                    ->orWhereNotNull('offcut_from_id');
                            });
                    });
                }
            });
    }
}
