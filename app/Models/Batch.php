<?php

namespace App\Models;

use App\Casts\NestedState;
use App\Formatters\SupplierFormatter;
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
 */
class Batch extends Model
{
    /** @use HasFactory<\Database\Factories\BatchFactory> */
    use HasFactory;

    protected $guarded = [];

    /*
     * Request-scoped memos. The offcuts index reads these once per offcut row while many offcuts share
     * one source batch, and eager loading hands every one of those rows the same Batch instance - so
     * caching here turns "once per row" into "once per batch".
     */
    private ?Collection $newStockCertificatesMemo = null;

    private ?array $offcutCertificatesMemo = null;

    private ?EloquentCollection $projectSummariesMemo = null;

    protected function casts(): array
    {
        return [
            'nested_state' => NestedState::class,
            'letters_project_array' => 'array',
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

    //optional
    public function quotes(): HasMany
    {
        return $this->hasmany(Quote::class);
    }

    //optional
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    //Collection
    public function projects(): EloquentCollection
    {
        /*
         * Read the ids off the pieces rather than walking $piece->project, which loaded one project
         * per piece. The relations are the ones ProjectResource walks for every row it renders.
         */
        return Project::query()
            ->with(['user', 'rawMaterialQuotes.piece.quotes', 'rawMaterialQuotes.piece.order'])
            ->whereIn('id', $this->pieces()->distinct()->pluck('project_id'))
            ->get();
    }

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

    public function newStockOrdersWithCertificates(): Collection
    {
        return $this->newStockCertificatesMemo ??= $this->orders()
            ->where("order_sent",true)
            ->whereNotNull("material_cert_numbers")
            ->with("supplier")
            ->get();
    }

    public function offcutOrdersWithCertificates(Business $business): array
    {
        /*
         * Shape is fixed: {used_offcuts: bool, certificates: [{supplier_name, material_cert_numbers}]}.
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

        //Get original batches of these offcuts
        $originalBatchIds = [];
        $productCategories = [];
        foreach($assignedOffcuts as $assignedOffcut){
            $originalBatchIds[] = $assignedOffcut->batch_from_id;
            $productCategories[] = $assignedOffcut->product_category;
        }
        $productCategories = array_unique($productCategories);
        $originalBatchIds = array_unique($originalBatchIds);
        $originalBatches = Batch::query()
            ->whereIn("id",$originalBatchIds)
            ->get();

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


        //Get certificates from these original batches
        $certificates = [];
        foreach($originalBatches as $originalBatch){
            $newStockOrdersWithCertificates = $originalBatch->newStockOrdersWithCertificates();
            foreach($newStockOrdersWithCertificates as $order){
                $supplierCategory = $order->quote?->supplier_category; //e.g "STEEL_MERCHANT"
                if(in_array($supplierCategory,$supplierCategoriesFromOffcuts)){
                    //supplier_id is nullable, so fall back rather than fatal on a missing supplier
                    $certificates[$order->supplier?->name ?? 'Unknown supplier'] = $order->material_cert_numbers;
                }
            }
        }

        return [
            'used_offcuts' => true,
            'certificates' => collect($certificates)
                ->map(fn (string $certNumbers, string $supplierName) => [
                    'supplier_name' => $supplierName,
                    'material_cert_numbers' => $certNumbers,
                ])
                ->values()
                ->all(),
        ];
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
