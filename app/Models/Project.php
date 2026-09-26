<?php

namespace App\Models;

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
 * @property array{notRecognised?: list<string>, otherPlan?: list<string>}|null $items_not_found
 */
#[ObservedBy([ProjectObserver::class])]
class Project extends Model
{
    /** @use HasFactory<\Database\Factories\ProjectFactory> */
    use HasFactory;

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
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
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

    //Unimported BOM lines
    public function unimportedItems(): array
    {
        /**
         * Descriptions from uploaded BOMs that produced no material row, kept apart
         * by cause: a line nothing in the price book matches reads very differently
         * to one that matched fine but sits outside the business's plan.
         */
        $stored = is_array($this->items_not_found) ? $this->items_not_found : [];

        return [
            'notRecognised' => array_values(array_unique($stored['notRecognised'] ?? [])),
            'otherPlan' => array_values(array_unique($stored['otherPlan'] ?? [])),
        ];
    }

    public function recordUnimportedItems(array $notRecognised, array $otherPlan): void
    {
        /**
         * Uploads are cumulative, so these are merged with what earlier uploads left behind.
         */
        $existing = $this->unimportedItems();

        $this->items_not_found = [
            'notRecognised' => array_values(array_unique(array_merge($existing['notRecognised'], $notRecognised))),
            'otherPlan' => array_values(array_unique(array_merge($existing['otherPlan'], $otherPlan))),
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

        if ($existing['notRecognised'] === [] && $existing['otherPlan'] === []) {
            return;
        }

        $imported = $this->rawMaterialQuotes()->pluck('description')->all();

        $remaining = [
            'notRecognised' => array_values(array_diff($existing['notRecognised'], $imported)),
            'otherPlan' => array_values(array_diff($existing['otherPlan'], $imported)),
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
        $percentageOfMaterialsQuoted = 0;
        $materialListRowsCount = $this->rawMaterialQuotes->count();

        foreach ($this->rawMaterialQuotes as $rawMaterialQuote) {
            $piece = $rawMaterialQuote->piece;
            if ($piece) {
                //PIECE might not have quote objects yet
                foreach ($piece->quotes as $quote) {
                    if ($quote->quote_sent) {
                        $percentageOfMaterialsQuoted++;
                    }
                }
            }
        }

        return $percentageOfMaterialsQuoted > 0
            ? ceil($percentageOfMaterialsQuoted / $materialListRowsCount * 100)
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

    public function scopeWithoutBatch(Builder $query): void
    {
        // batch > piece > project
        $query->whereRelation("pieces.batch","done","=",false);
    }

    public function scopeWithBatch(Builder $query): void
    {
        // batch > piece > project
        $query->whereRelation("pieces.batch","done","=",true);
    }

    public function scopeOverdueForQuotingAndOrdering(Builder $query): void
    {
        /**
         * Critical path = quoting time + delivery time
         * Less than [critical path] before planned project material received date
         */
        $deadline = Carbon::now()->addDays($this->criticalPathDays())->endOfDay();

        // Query the database
        $query->where('date_materials_required', '<', $deadline);
    }

    public function scopeThisBusiness(Builder $query, Business $business): void
    {
        $staffIds = $business->users()->get()->pluck('id')->toArray();

        $query->whereIn('user_id', $staffIds);
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
