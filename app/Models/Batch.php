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
 */
class Batch extends Model
{
    /** @use HasFactory<\Database\Factories\BatchFactory> */
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'nested_state' => NestedState::class,
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
        $pieces = $this->pieces;
        $projectIds = [];

        foreach ($pieces as $piece) {
            $projectIds[] = $piece->project->id;
        }

        $uniqueProjectIds = array_unique($projectIds);

        return Project::query()
            ->whereIn('id', $uniqueProjectIds)
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
        return $this->orders()
            ->where("order_sent",true)
            ->whereNotNull("material_cert_numbers")
            ->with("supplier")
            ->get();
    }

    public function offcutOrdersWithCertificates(Business $business): Collection|string
    {
        //Get all offcuts
        $assignedOffcuts = $this->assignedOffcuts();
        if($assignedOffcuts->count() === 0){
            return "NO_OFFCUTS";
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
                $supplierCategory = $order->quote->supplier_category; //e.g "STEEL_MERCHANT"
                if(in_array($supplierCategory,$supplierCategoriesFromOffcuts)){
                    $certificates[$order->supplier->name] = $order->material_cert_numbers;
                }
            }
        }

        return collect($certificates);
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
