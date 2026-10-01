<?php

namespace App\Models;

use App\Enums\GoodsReceiptNonconformanceEnums;
use App\Models\Concerns\RecordsChanges;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Declared because casts() is a method rather than a $casts array, and static analysis cannot see
 * through that - without these, every received_at?->format() reads as a method call on a string.
 *
 * @property Carbon|null $order_sent_at
 * @property Carbon|null $order_confirmation_received_at
 * @property Carbon|null $received_at
 * @property int|null $received_by_user_id
 * @property string|null $delivery_docket_number
 * @property bool|null $quantity_verified
 * @property bool|null $grade_verified
 * @property string|null $receipt_nonconformance
 * @property string|null $receipt_note
 */
class Order extends Model
{
    /** @use HasFactory<\Database\Factories\OrderFactory> */
    use HasFactory, RecordsChanges;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'order_sent' => 'boolean',
            'order_confirmation_received' => 'boolean',
            'is_delivered' => 'boolean',
            'order_sent_at' => 'datetime',
            'order_confirmation_received_at' => 'datetime',
            'received_at' => 'datetime',
            /*
             * Tri-state on purpose, like products.certificates: null is "nobody has answered",
             * false is "checked, and it was wrong". A boolean with a default would turn every
             * delivery recorded before this existed into one somebody had verified.
             */
            'quantity_verified' => 'boolean',
            'grade_verified' => 'boolean',
        ];
    }

    //Relationships
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    //Optional
    public function quote(): BelongsTo
    {
        return $this->belongsTo(Quote::class);
    }

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class);
    }

    public function pieces(): BelongsToMany
    {
        return $this->belongsToMany(Piece::class);
    }

    /**
     * optional
     *
     * Annotated because callers do more with it than read columns - OrderUndoSentController asks the
     * batch for its orders and its approvals - and without the generic every one of those reads as a
     * call to an undefined method on Model.
     *
     * @return BelongsTo<Batch, $this>
     */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class);
    }

    /**
     * @return HasMany<MaterialCertificate, $this>
     */
    public function materialCertificates(): HasMany
    {
        return $this->hasMany(MaterialCertificate::class);
    }

    /**
     * The bars this order bought.
     *
     * Attached when the order is marked placed rather than when the nest cut them - the bars exist
     * from the moment a batch is nested, and which supplier is buying them is not settled until
     * somebody presses "Sent order". See App\Actions\Bar\AttachBarsToOrder.
     *
     * @return HasMany<Bar, $this>
     */
    public function bars(): HasMany
    {
        return $this->hasMany(Bar::class);
    }

    /**
     * Whoever booked the steel in at the gate.
     *
     * Nullable, and stays nullable after the account is gone, for the reason
     * material_certificates.user_id does: the receipt is a fact about the delivery and it outlives
     * the person who recorded it.
     */
    public function receivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by_user_id');
    }

    //Goods receipt
    /**
     * Has this delivery been booked in, as opposed to merely ticked as arrived?
     *
     * is_delivered answers "did the steel turn up". This answers "did somebody check it", which is
     * the question ISO 9001 8.6 asks before material is released for use, and the two can disagree:
     * every order marked delivered before the receipt columns existed is_delivered with no receipt
     * behind it, and that is reported rather than assumed.
     */
    public function hasReceiptRecord(): bool
    {
        return $this->received_at !== null;
    }

    /**
     * Did what arrived match what was ordered?
     *
     * Null where nobody has said - an unanswered check is not a pass. Both halves have to be
     * explicitly true, and a recorded nonconformance makes it false whatever the two flags say: a
     * delivery can be the right grade and the right count and still have arrived bent.
     */
    public function receiptAccepted(): ?bool
    {
        if (! $this->hasReceiptRecord()) {
            return null;
        }

        if ($this->receipt_nonconformance !== null) {
            return false;
        }

        if ($this->quantity_verified === null || $this->grade_verified === null) {
            return null;
        }

        return $this->quantity_verified && $this->grade_verified;
    }

    /**
     * What was wrong with the delivery, as the enum rather than the stored string.
     *
     * Null for a clean receipt, and also for a value the enum no longer has - a reason retired from
     * the picker should not fatal the order screen for the deliveries that were booked in under it.
     */
    public function receiptNonconformance(): ?GoodsReceiptNonconformanceEnums
    {
        return GoodsReceiptNonconformanceEnums::tryFrom((string) $this->receipt_nonconformance);
    }

    //Local scopes

    /**
     * Orders that are certified, in either of the two ways an order can be.
     *
     * "Certified" used to mean one thing - material_cert_numbers is not null - because a written
     * reference was all this could hold. Attached files count for exactly as much: a merchant who
     * emails the PDF and never quotes a number leaves the steel just as traceable as one who does
     * the reverse.
     *
     * Every read of this question goes through here, so the two cannot drift apart. They already
     * did once, in three separate places: the board's "no certs yet" warning, the new-stock trail
     * on the print spec, and the ancestry trail behind a reused offcut.
     */
    public function scopeHasMaterialCerts(Builder $query): Builder
    {
        return $query->where(function (Builder $query) {
            $query->whereNotNull('material_cert_numbers')
                ->orWhereHas('materialCertificates');
        });
    }

    public function scopeMissingMaterialCerts(Builder $query): Builder
    {
        return $query->whereNull('material_cert_numbers')
            ->whereDoesntHave('materialCertificates');
    }
}
