<?php

namespace App\Models;

use App\Casts\NestedState;
use App\Formatters\SupplierFormatter;
use App\Models\Concerns\BelongsToSandbox;
use App\Services\BatchStages;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/**
 * @property array<string, array<int, object>> $nested_state The saved nesting, keyed by nesting algo
 * @property array<int, string>|null $letters_project_array The project letters stamped on that nesting
 * @property int|null $sandbox_user_id The user whose test mode nested this, or null for a real batch
 * @property \Illuminate\Support\Carbon|null $quoted_at When somebody called this batch quoted outright
 * @property \Illuminate\Support\Carbon|null $ordered_at When somebody called it bought outright
 * @property \Illuminate\Support\Carbon|null $delivered_at When somebody called its material arrived
 * @property \Illuminate\Support\Carbon|null $cut_at When somebody recorded that it has been cut
 * @property array<int, string>|null $ordered_supplier_groups The groups bought off the application
 * @property array<int, string>|null $quoted_supplier_groups The groups priced off the application
 * @property array<int, string>|null $delivered_supplier_groups The groups whose steel somebody marked in
 */
class Batch extends Model
{
    /** @use HasFactory<\Database\Factories\BatchFactory> */
    use BelongsToSandbox, HasFactory;

    protected $guarded = [];

    /*
     * Request-scoped memos. The offcuts index reads these once per offcut row while many offcuts share
     * one source batch, and eager loading hands every one of those rows the same Batch instance - so
     * caching here turns "once per row" into "once per batch".
     */
    private ?array $newStockCertificatesMemo = null;

    private ?array $offcutCertificatesMemo = null;

    private ?EloquentCollection $projectSummariesMemo = null;

    private ?EloquentCollection $projectsMemo = null;

    private ?EloquentCollection $projectApprovalFlagsMemo = null;

    private ?bool $hasSentOrderMemo = null;

    private ?bool $producedOffcutsConsumedMemo = null;

    protected function casts(): array
    {
        return [
            'nested_state' => NestedState::class,
            'letters_project_array' => 'array',
            /*
             * The supplier-free marks the Nesting page's card menu sets - "All quoted", "All
             * ordered", "Delivered" and "Cut". Dates rather than flags, because the question asked of
             * them afterwards is when somebody said so, and a boolean cannot answer it. See the
             * 2026_10_03 migrations.
             */
            'quoted_at' => 'datetime',
            'ordered_at' => 'datetime',
            'delivered_at' => 'datetime',
            'cut_at' => 'datetime',
            /*
             * And the same answer one merchant at a time - the supplier groups on this batch that
             * were bought somewhere other than through the quotes screen. Names, because a supplier
             * group is computed rather than stored; see the 2026_10_03_140000 migration.
             */
            'ordered_supplier_groups' => 'array',
            /*
             * And the step before it, counted the same way - the groups on this batch whose price
             * came in somewhere other than through the quotes screen. See the 2026_10_05_100000
             * migration, and BatchMarkGroupQuotedController for the press that writes one.
             */
            'quoted_supplier_groups' => 'array',
            /*
             * And the step after both of them - the groups on this batch whose material somebody
             * watched come off the truck, there being no order on the application to book in against.
             * See the 2026_10_05_110000 migration, and BatchMarkGroupDeliveredController.
             */
            'delivered_supplier_groups' => 'array',
        ];
    }

    //Relationships
    public function pieces(): HasMany
    {
        return $this->hasMany(Piece::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function orderApprovals(): HasMany
    {
        return $this->hasMany(OrderApproval::class);
    }

    public function bars(): HasMany
    {
        return $this->hasMany(Bar::class);
    }

    //optional
    public function quotes(): HasMany
    {
        return $this->hasmany(Quote::class);
    }

    /**
     * optional
     *
     * @return HasMany<Order, $this>
     */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    //Collection
    /**
     * @return EloquentCollection<int, Project>
     */
    public function projects(): EloquentCollection
    {
        /*
         * Read the ids off the pieces rather than walking $piece->project, which loaded one project
         * per piece.
         *
         * The relations are the ones the callers walk: ProjectResource needs user, rawMaterialQuotes
         * and pieces.batch (that last one for prerequisiteUploadMaterials, which was two N+1s per
         * card until it was added here), and the Ordering/Delivering columns call
         * percentageOfMaterialsOrdered(), which needs rawMaterialQuotes.piece.order.
         *
         * Memoised like projectSummaries() below - undoStartQuoting alone walks this three times on
         * the same batch instance, and each walk is the full eager-loaded tree.
         */
        return $this->projectsMemo ??= Project::query()
            //createdBy for the same resource - the colleague who uploaded the list, where there was one
            ->with(['user', 'createdBy', 'rawMaterialQuotes.piece.order', 'pieces.batch'])
            ->whereIn('id', $this->pieces()->distinct()->pluck('project_id'))
            ->get();
    }

    /**
     * @return EloquentCollection<int, Project>
     */
    public function projectApprovalFlags(): EloquentCollection
    {
        /*
         * Just the owner and done state, for the prerequisite conditions. Those read nothing but
         * $project->user_id and $project->done, while projects() above eager-loads the
         * rawMaterialQuotes/piece/quote/order tree that ProjectResource needs - several queries per
         * call, and markQuoteAsSent/undoMarkQuoteAsSent together call it four times per supplier row.
         */
        /*
         * The manager comes with them, two columns of him: undoStartQuoting asks whose business the
         * projects on the batch belong to, and walking there through $project->user was a user row
         * per project on top of the tree projects() was being loaded for.
         */
        return $this->projectApprovalFlagsMemo ??= Project::query()
            ->select(['id', 'user_id', 'done'])
            ->with('user:id,business_id')
            ->whereIn('id', $this->pieces()->distinct()->pluck('project_id'))
            ->get();
    }

    /**
     * @return EloquentCollection<int, Project>
     */
    public function projectSummaries(): EloquentCollection
    {
        /*
         * Just the id and name, for screens that only label a batch with its projects. projects() above
         * eager-loads the rawMaterialQuotes/piece/quote/order tree that ProjectResource needs, which is
         * several queries per batch and none of it is read here.
         */
        return $this->projectSummariesMemo ??= Project::query()
            ->select(['id', 'name'])
            ->whereIn('id', $this->pieces()->distinct()->pluck('project_id'))
            ->get();
    }

    public function oldOffcuts(): Collection
    {
        return Offcut::query()
            ->where("batch_from_id",$this->id)
            ->get();
    }

    public function assignedOffcuts(): Collection
    {
        return Offcut::query()
            ->where("batch_to_id",$this->id)
            ->get();
    }

    public function scrap(): Collection
    {
        //todo scrap is not tracked as its own record yet - see the per-bar scrap totals in
        //NestingFormatter::singleRun, which are what the nesting screens report
        return collect([]);
    }

    /**
     * The certificates behind the new steel this batch bought.
     *
     * Shape matches offcutOrdersWithCertificates() below: {supplier_group, material_cert_numbers,
     * material_cert_files}. It used to hand back Order models, so the two trails - which are printed
     * side by side on the same spec sheet - read their supplier off different keys, and the Order
     * rows carried the whole orders table out to the page to have two fields read off them.
     *
     * The merchant's own name used to head each line. There is no longer a suppliers list to read one
     * from, so the line is headed by the supplier group the order was placed under - which is what the
     * order was always grouped by, and the only identity of it the application still keeps.
     *
     * @return array<int, array{supplier_group: string, material_cert_numbers: ?string, material_cert_files: array<int, array{id: int, filename: string}>}>
     */
    public function newStockOrdersWithCertificates(): array
    {
        return $this->newStockCertificatesMemo ??= $this->orders()
            ->where("order_sent",true)
            ->hasMaterialCerts()
            ->with(["quote:id,supplier_category","materialCertificates"])
            ->get()
            ->map(fn (Order $order) => [
                //quote_id is nullable, and ?? short-circuits the whole chain rather than fatal
                'supplier_group' => $order->quote->supplier_category ?? 'UNKNOWN',
                'material_cert_numbers' => $order->material_cert_numbers,
                'material_cert_files' => $order->materialCertificates
                    ->map(fn (MaterialCertificate $certificate) => [
                        'id' => $certificate->id,
                        'filename' => $certificate->original_filename,
                    ])
                    ->values()
                    ->all(),
            ])
            ->values()
            ->all();
    }

    public function offcutOrdersWithCertificates(Business $business): array
    {
        /*
         * Shape is fixed: {used_offcuts: bool, certificates: [{supplier_group, material_cert_numbers}]}.
         * This used to return either the "NO_OFFCUTS" string or a collection KEYED by supplier name, and
         * both arrive in JSON as a truthy object - so consumers could not tell the two apart, and a
         * keyed collection has no .forEach for the ones that guessed it was a list.
         */
        return $this->offcutCertificatesMemo ??= $this->resolveOffcutOrdersWithCertificates($business);
    }

    private function resolveOffcutOrdersWithCertificates(Business $business): array
    {
        //Get all offcuts
        $assignedOffcuts = $this->assignedOffcuts();
        if($assignedOffcuts->count() === 0){
            return [
                'used_offcuts' => false,
                'certificates' => [],
            ];
        }

        /*
         * Get the batches these offcuts came out of.
         *
         * The whole ancestry, not just the batch that cut each offcut. An offcut of an offcut of an
         * offcut was cut by a batch that bought nothing at all - it nested straight out of inventory -
         * so the purchase the mill certificate hangs off is two or more batches back up the
         * offcut_from_id chain. Reading only batch_from_id found no certificated order from the third
         * generation on, and the print spec answered "Offcuts are not traceable!" for steel that is
         * fully traceable.
         *
         * Like the single-generation case this reports every certificate in the trail rather than the
         * one bar the offcut came off: the bar a cut was taken from is not recorded against an order,
         * so the honest answer is the certificates the material could have come from.
         */
        $assignedOffcuts = Offcut::loadAncestry($assignedOffcuts);

        $originalBatchIds = [];
        $productCategories = [];
        foreach($assignedOffcuts as $assignedOffcut){
            $originalBatchIds[] = $assignedOffcut->batch_from_id;
            $productCategories[] = $assignedOffcut->product_category;

            foreach($assignedOffcut->ancestors() as $ancestor){
                $originalBatchIds[] = $ancestor->batch_from_id;
            }
        }
        $productCategories = array_unique($productCategories);
        $originalBatchIds = array_unique($originalBatchIds);

        //Get list of ALL supplier categories with contained products. e.g "steel merchant" contains "PFC, UB, etc"
        $categories = (new SupplierFormatter)->supplierGroups($business);

        //Find supplier categories of these offcuts
        $supplierCategoriesFromOffcuts = [];
        foreach($categories as $supplierCategory => $includedProducts){
            foreach($includedProducts as $includedProduct){
                if(in_array($includedProduct,$productCategories)){
                    $supplierCategoriesFromOffcuts[] = $supplierCategory;
                }
            }
        }
        $supplierCategoriesFromOffcuts = array_unique($supplierCategoriesFromOffcuts);


        /*
         * Get certificates from these original batches.
         *
         * One query for the whole set. This used to load the batches, then call
         * newStockOrdersWithCertificates() on each one, then read $order->quote per order - a query per
         * batch plus a query per order, none of them eager-loaded.
         */
        $ordersWithCertificates = Order::query()
            ->whereIn("batch_id",$originalBatchIds)
            ->where("order_sent",true)
            ->hasMaterialCerts()
            ->with(["quote:id,supplier_category","materialCertificates"])
            ->get();

        $certificates = [];
        foreach($ordersWithCertificates as $order){
            $supplierCategory = $order->quote?->supplier_category; //e.g "STEEL_MERCHANT"
            if(in_array($supplierCategory,$supplierCategoriesFromOffcuts)){
                //Headed by the group the order was placed under - there is no suppliers list to name
                //a merchant from. Never null here: the in_array above matched it against a real group.
                $supplierGroup = $supplierCategory;

                /*
                 * Keyed on the group/certificate PAIR. Keying on the group alone meant one
                 * group supplying two of the source batches kept only the last certificate read -
                 * silently dropping the others from the traceability trail.
                 *
                 * The attached files are part of that identity now. An order certified only by a PDF
                 * has no cert numbers at all, so two of them under one group would collide on the
                 * same "group\0null" key and the trail would report one of the two.
                 */
                $files = $order->materialCertificates
                    ->map(fn (MaterialCertificate $certificate) => [
                        'id' => $certificate->id,
                        'filename' => $certificate->original_filename,
                    ])
                    ->values()
                    ->all();

                $fingerprint = $supplierGroup."\0".$order->material_cert_numbers
                    ."\0".implode(",",array_column($files,'id'));

                $certificates[$fingerprint] = [
                    'supplier_group' => $supplierGroup,
                    'material_cert_numbers' => $order->material_cert_numbers,
                    'material_cert_files' => $files,
                ];
            }
        }

        return [
            'used_offcuts' => true,
            'certificates' => array_values($certificates),
        ];
    }

    /**
     * The mill certificates attached to the batch itself rather than to one of its orders.
     *
     * Only a shop buying off the application has these - there is no order for the merchant's PDF to
     * hang off, so it hangs here. See the 2026_10_03_130000 migration.
     *
     * @return HasMany<MaterialCertificate, $this>
     */
    public function certificates(): HasMany
    {
        return $this->hasMany(MaterialCertificate::class);
    }

    /**
     * Whether an order has gone out on this batch - the one question the whole board turns on.
     *
     * It decides which column the batch is in (BatchStages::of), whether it can still be unpicked
     * (PrerequisiteConditions::undoStartQuoting) and whether the supplier-free marks may be given at
     * all (canMarkBatchMilestone). Every one of those asked it with its own `exists` query, so the
     * Nesting page paid for it four times per card - a grouped page that does nothing but draw a list.
     *
     * Memoised per instance, the way projectApprovalFlags() below is and for the same reason: within
     * one request the answer cannot change under the four callers, because sending an order is a POST
     * that ends in a redraw. The memo is an instance field, so a batch re-read from the database - the
     * next request, or the next pass of the auto-done sweep - asks again.
     */
    public function hasSentOrder(): bool
    {
        return $this->hasSentOrderMemo ??= $this->orders()
            ->where('order_sent', true)
            ->exists();
    }

    /**
     * The same answer, handed in by a caller that asked it of a whole column at once.
     *
     * App\Services\BatchStages::forBusiness reads it for every batch of a business in one query and
     * seeds it here, which is what keeps a page of cards from running the `exists` above per card.
     * Deliberately a separate method rather than a public property: it is the same memo, filled from
     * outside, and nothing may overwrite an answer this instance has already given.
     */
    public function seedHasSentOrder(bool $hasSentOrder): void
    {
        $this->hasSentOrderMemo ??= $hasSentOrder;
    }

    /**
     * Whether the offcuts this batch cut have been nested into since.
     *
     * The question PrerequisiteConditions::undoStartQuoting asks before it offers the way back:
     * unpicking a batch deletes the offcuts it produced, so one a later batch has already cut into
     * cannot be handed back without stripping that batch of its material and of the certificate trail
     * behind it.
     *
     * Memoised and seedable like hasSentOrder() above, for the same reason - a page drawing a card per
     * batch asks it once per card, and NestingIndexController asks it once for the column.
     */
    public function producedOffcutsConsumed(): bool
    {
        return $this->producedOffcutsConsumedMemo ??= Offcut::query()
            ->where('batch_from_id', $this->id)
            ->whereNotNull('batch_to_id')
            ->exists();
    }

    public function seedProducedOffcutsConsumed(bool $consumed): void
    {
        $this->producedOffcutsConsumedMemo ??= $consumed;
    }

    /**
     * The jobs on this batch as the prerequisite gates read them, handed in by a caller that has
     * already loaded them.
     *
     * The page drawing these cards selects every project on every batch in one query and knows which
     * belongs where, so each batch is given its own rather than going back for them. The rows have to
     * carry what projectApprovalFlags() selects - the manager, the done flag, and the manager's
     * business - or the gates read a null and refuse something they should allow.
     *
     * @param  EloquentCollection<int, Project>  $projects
     */
    public function seedProjectApprovalFlags(EloquentCollection $projects): void
    {
        $this->projectApprovalFlagsMemo ??= $projects;
    }

    /**
     * Whether every merchant this batch has to buy from already has a price against it.
     *
     * The question "All quoted" exists to answer, asked of a batch that may have been answering it a
     * block at a time: the order list marks one supplier group quoted (BatchMarkGroupQuotedController),
     * the quotes screen sends one quote per group, and a group somebody has bought was priced before
     * they bought it. When none of the three leaves a merchant unpriced there is nothing for the
     * batch-wide mark to record, and the card greys it.
     *
     * Measured the three ways the Nesting page's pill measures it, and each of them all-or-nothing,
     * because the card and the press have to give the same answer - see
     * NestingIndexController::fullyQuotedBatchIds and the two methods beside it.
     *
     * False for a batch whose material belongs to no supplier group the business's plan covers, for
     * the reason those methods answer no: an empty list of merchants is nobody to have been priced
     * by, not a finished job.
     *
     * Asked of one batch, like isDelivered() below and for the same reason: the page that draws a
     * card per batch has worked this out for the whole column in a handful of queries and passes its
     * answer in. This is for the press - BatchMarkQuotedController - which has one batch and must not
     * take the card's word for it.
     */
    public function everySupplierGroupPriced(Business $business): bool
    {
        $required = $this->supplierGroupsRequired($business);

        if ($required === []) {
            return false;
        }

        //A sent quote, not a quote row: a row is minted the moment anybody opens the quotes screen
        $sentQuoteGroups = $this->quotes()
            ->where('quote_sent', true)
            ->pluck('supplier_category')
            ->all();

        foreach ([$sentQuoteGroups, $this->quoted_supplier_groups ?? [], $this->ordered_supplier_groups ?? []] as $priced) {
            if (array_diff($required, $priced) === []) {
                return true;
            }
        }

        return false;
    }

    /**
     * And the step after it: whether every merchant on this batch has already been bought from.
     *
     * The same thing "All ordered" claims in one press, arrived at a block at a time - see
     * BatchMarkGroupOrderedController. A card whose every block says "Ordered" has had its material
     * bought, which is what its pill says, and the menu item that would say it again has nothing left
     * to record.
     *
     * The marks alone, where the priced question above reads three things. A real order sent to a
     * merchant is the one route this does not have to look at, because the gate in front of it stops
     * there already: PrerequisiteConditions::canMarkBatchMilestone refuses every supplier-free mark
     * the moment a sent order exists on the batch, a batch with one having left the Quoting column
     * for a stage where the card does not offer these presses at all.
     *
     * Measured against the same merchants as everySupplierGroupPriced, and false for a batch with no
     * merchants, for the same reason - see NestingIndexController::fullyMarkedOrderedBatchIds, which
     * is where the card gets its own answer.
     */
    public function everySupplierGroupBought(Business $business): bool
    {
        $required = $this->supplierGroupsRequired($business);

        if ($required === []) {
            return false;
        }

        return array_diff($required, $this->ordered_supplier_groups ?? []) === [];
    }

    /**
     * And the step after that one: whether every merchant on this batch has had its steel marked in.
     *
     * The same thing "All delivered" claims in one press, arrived at a block at a time - see
     * BatchMarkGroupDeliveredController. A card whose every block says "Delivered" has its material
     * in the rack, which is what its pill says, and the menu item that would say it again has nothing
     * left to record.
     *
     * The marks alone, like the bought question above, and for the same reason: the batches these are
     * asked of have no order on the application to book in. A merchant with a sent order is delivered
     * when that order is received, the mark is refused on its block, and a batch carrying one has left
     * the Quoting column for a card that does not offer "All delivered" at all.
     *
     * Measured against the same merchants as the two questions above, and false for a batch with no
     * merchants, for the same reason - see NestingIndexController::fullyMarkedDeliveredBatchIds.
     */
    public function everySupplierGroupDelivered(Business $business): bool
    {
        $required = $this->supplierGroupsRequired($business);

        if ($required === []) {
            return false;
        }

        return array_diff($required, $this->delivered_supplier_groups ?? []) === [];
    }

    /**
     * The merchants this batch has to buy from - the supplier groups its material falls into.
     *
     * The card's own "Material order" count, and the list both questions above are measured against:
     * a batch is fully priced or fully bought when nothing on this list is outstanding. Read off the
     * pieces and the business's plan, the way NestingIndexController::supplierGroupsFor reads it for
     * a whole column at once.
     *
     * Empty where the business's plan covers none of the material on the batch, which both callers
     * treat as "no" rather than as "nothing outstanding": there is nobody here to have been quoted or
     * bought from.
     *
     * @return array<int, string>
     */
    private function supplierGroupsRequired(Business $business): array
    {
        $productCategories = $this->pieces()
            ->distinct()
            ->pluck('product_category')
            ->all();

        $required = [];

        foreach ((new SupplierFormatter)->supplierGroups($business) as $supplierGroup => $includedProducts) {
            if (array_intersect($productCategories, $includedProducts) !== []) {
                $required[] = (string) $supplierGroup;
            }
        }

        return $required;
    }

    /**
     * Whether the steel on this batch is in the rack, however the shop got it there.
     *
     * Three ways to be delivered, because there are two ways to buy and the phone has two spellings.
     * A batch ordered through the application is delivered when every sent order on it has been
     * booked in and nothing on the job is still unbought - the same test the Nesting page's DELIVERED
     * pill is drawn from (see NestingIndexController::fullyDeliveredBatchIds). A batch bought over
     * the phone has no order to book in and says so with a mark instead: "All delivered" on the card
     * menu for the whole job, or the order list's own mark on every one of its blocks.
     *
     * That last one has to count here or the block marks are a trap. They are what puts DELIVERED on
     * the card and what greys "All delivered" once the last block is in, so a batch answered a
     * merchant at a time would reach a pill saying its steel is in, over a menu offering "Cut" and
     * "Move to done" that both 403 - the one state in the application a shop could not get out of.
     *
     * Asked of one batch, so it runs BatchStages::of rather than the page's grouped query: the pages
     * that draw a card per batch already know the answer and pass it in. This is for the press -
     * BatchMarkCutController - which has one batch and must not take the card's word for it.
     */
    public function isDelivered(Business $business): bool
    {
        if ($this->delivered_at !== null || $this->everySupplierGroupDelivered($business)) {
            return true;
        }

        if ((new BatchStages)->of($this) !== BatchStages::DELIVERING) {
            return false;
        }

        return $this->orders()
            ->where('order_sent', true)
            ->where('is_delivered', false)
            ->doesntExist();
    }

    //Local scope
    public function scopeActive(Builder $query): void
    {
        $query->where('done', false);
    }

    public function scopeInactive(Builder $query): void
    {
        $query->where('done', true);
    }

    public function scopeHasNoSentOrder($query)
    {
        return $query->whereDoesntHave('orders', function ($query) {
            $query->where('order_sent', true);
        });
    }
}
