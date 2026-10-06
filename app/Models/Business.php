<?php

namespace App\Models;

use App\Billing\Billing;
use App\Billing\SubscriptionState;
use App\Enums\SupplierGroupEnums;
use App\Formatters\SupplierFormatter;
use App\Services\ProductService;
use App\Services\SupplierGroupCosts;
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

    /**
     * The lead times a business runs on until somebody sets its own.
     *
     * Quoting is how long it takes to get prices back from the merchants; delivery is how long the
     * longest-lead material on a job takes to turn up once it is bought. Added together they are the
     * critical path (Project::criticalPathDays), which is what every deadline notification counts
     * back from.
     *
     * Both are working days. A merchant does not price over the weekend and does not deliver on a
     * Sunday, so every deadline counted off these steps over it - see Project::quotingDeadline().
     *
     * Constants rather than two literals in $attributes, because Project falls back to them for a
     * model with no business behind it and the two have to be the same numbers. The column defaults
     * in the migration are the third copy and are deliberately written out there - a migration that
     * reads a constant changes what it did to existing rows the day somebody edits the constant.
     */
    public const int DEFAULT_QUOTING_DAYS = 2;

    public const int DEFAULT_DELIVERY_DAYS = 3;

    /**
     * Everything is mass assignable except what decides whether this business has paid.
     *
     * No route mass assigns a business today - there is no businesses.update - so this is a fence
     * rather than a fix. It is worth having because the fence is what makes the next one safe: one
     * `$business->update($request->validated())` on a settings screen, with manual_plan or
     * manual_access_until anywhere in the payload, is a business granting itself the product.
     *
     * trial_ends_at is deliberately NOT here. booted() only fills it where one was not asked for,
     * so seeders and fixtures dictate their own dates, and there is a test that says so.
     * stripe_id, pm_type and pm_last_four are Stripe's to write - Cashier sets them by attribute
     * and by forceFill(), so guarding them costs nothing.
     */
    protected $guarded = [
        'id',
        'stripe_id',
        'pm_type',
        'pm_last_four',
        'manual_plan',
        'manual_access_until',
    ];

    /**
     * Kept out of json everywhere a business is serialised.
     *
     * The shared Inertia prop used to carry the whole row to the browser on every response, which
     * is how a customer id and the last four digits of their card ended up somewhere neither was
     * needed. HandleInertiaRequests now sends named fields instead; this is the belt to that
     * braces, for the admin screens that still hand a whole business to a page.
     *
     * @var list<string>
     */
    protected $hidden = [
        'stripe_id',
        'pm_type',
        'pm_last_four',
    ];

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
        /*
         * Zero on purpose, unlike the rest: material_cost_per_tonne used to mean the DELIVERED price, so
         * any other default would charge freight twice for a business already carrying a figure there.
         * See the migration that added these.
         */
        'delivery_cost_per_tonne' => 0.00,
        'delivery_cost_per_order' => 0.00,
        'scrap_recovery_rate' => 0.13,
        'default_kg_per_m' => 10.0,
        'cut_base_minutes' => 1.5,
        'cut_minutes_per_kg_per_m' => 0.06,
        'offcut_draw_base_minutes' => 4.0,
        'bar_handling_base_minutes' => 3.0,
        'receive_base_minutes' => 5.0,
        'order_admin_minutes' => 15.0,
        'offcut_rack_base_minutes' => 6.0,
        'move_minutes_per_tonne' => 15.0,
        'offcut_retention_cap' => 0.6,
        'purchase_cost_weight' => 1.0,
        //The two halves of the critical path - see the constants above
        'quoting_days' => self::DEFAULT_QUOTING_DAYS,
        'delivery_days' => self::DEFAULT_DELIVERY_DAYS,
    ];

    /*
     * These are boolean columns in the migration, but without a cast the type that reaches json is
     * whatever the driver hands back - int on mysql, the string "0" on sqlite. The admin users page
     * compared admin_setup_complete with ===, so its Ready column silently emptied out under the test
     * connection while it worked in production. That column is gone; the lesson it taught is why the
     * other three are still listed.
     */
    protected function casts(): array
    {
        return [
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

            /*
             * What each merchant charges, where it is not what the steel merchant charges - see the
             * migration that added it and Services\SupplierGroupCosts.
             *
             * The cast earns its keep twice over. Services\NestingSettings hands a retained snapshot
             * back as an unsaved Business built from a plain array, so this column arrives already
             * decoded there and as JSON text everywhere else; the cast is what makes those two the
             * same object to read from.
             */
            'cost_overrides' => 'array',
        ];
    }

    /**
     * What this business has said one merchant charges, cleaned up.
     *
     * Empty for a merchant it has said nothing about, which is every merchant for almost every
     * business - a yard that buys only steel never needs to answer this.
     *
     * @return array<string, float>
     */
    public function merchantCoefficients(string $supplierGroup): array
    {
        return SupplierGroupCosts::normalise($this->getAttribute('cost_overrides'))[$supplierGroup] ?? [];
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

    /**
     * The uploads of this business's that matched no template and could not be made to match one.
     *
     * @return HasMany<TemplateLearningAttempt, $this>
     */
    public function templateLearningAttempts(): HasMany
    {
        return $this->hasMany(TemplateLearningAttempt::class);
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
    /*
     * scopeActivated() stood here: the businesses an admin had switched on, which was the only kind
     * that could reach the product. SendTrialReminders asked for it so that a customer waiting on us
     * to write their import templates was not warned that the trial they had never been able to use
     * was running out.
     *
     * It is gone with the column. A business can import from its first upload now, so every business
     * on a trial is a business using one.
     */

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
            //createdBy is null on all but the projects one colleague uploaded for another, and an
            //unloaded null relation costs no query - but the ones that do have it are drawn here too
            ->with(['user', 'createdBy', 'rawMaterialQuotes', 'pieces.batch'])
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

        /*
         * The material rows come with the projects. Walked off the relation as $this->projects, each
         * project went back for its own rawMaterialQuotes - a query per project the business has ever
         * had, every time the Nesting page is drawn, because the open batch card is built on this.
         * That is the one cost on that page which grows with the age of the business rather than with
         * the work in front of somebody.
         */
        foreach($this->projects()->with('rawMaterialQuotes')->get() as $project){
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
     * else lists it, and the done list holds only your own done projects, so there was no
     * route back to it at all: a colleague could not so much as discover it existed.
     */
    public function projectsWithUnfinishedImport(): Collection
    {
        return $this->projectsRequiringClarification()
            ->reject(fn (Project $project) => $project->done)
            ->filter(fn (Project $project) => $project->pieces()->whereNotNull('batch_id')->doesntExist())
            ->sortBy('created_at')
            ->values();
    }

    /*
     * currentProjects() and pastProjects() lived here and are gone. Nothing called either of them -
     * the board builds its columns from KanbanFormatter and past batches from PastBatchesController -
     * and currentProjects() was quietly wrong in a way that would have bitten whoever reached for it
     * next: its "projects without batch yet" half used Project::scopeWithoutBatch, which was
     * whereRelation("pieces.batch", "done", false) and so matched projects that DO have a batch. It
     * returned the projects in active batches twice over and left out every project that had not been
     * nested yet. StoreProjectRequest had already had to work around exactly that.
     *
     * The two scopes went with them.
     */

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
