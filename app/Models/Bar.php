<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string|null $heat_number The mill heat or cast this bar was rolled from, as per the cert
 * @property int|null $order_id The order that bought it, once one has been placed
 */
class Bar extends Model
{
    /** @use HasFactory<\Database\Factories\BarFactory> */
    use HasFactory;

    protected $guarded = [];

    //Relationships
    public function offcuts(): HasMany
    {
        return $this->hasMany(Offcut::class);
    }

    //Nullable - bars cut before batch_id existed could only be traced through the offcuts they produced
    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class);
    }

    /**
     * The order that bought this bar.
     *
     * Nullable, and legitimately so for the whole of a batch's life before anybody presses "Sent
     * order": the bar is part of a cut plan from the moment the nest is saved, and which merchant is
     * supplying it is not decided until later. See App\Actions\Bar\AttachBarsToOrder.
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * The cuts taken out of this bar.
     *
     * @return HasMany<Cut, $this>
     */
    public function cuts(): HasMany
    {
        return $this->hasMany(Cut::class);
    }

    //Traceability
    /**
     * Is this bar's steel traceable to a heat?
     *
     * The heat number is the one-to-one answer. Without it the trail falls back to the certificates the
     * bar's order carries, which identifies the steel as one of several - enough for most purposes and
     * not enough for AS/NZS 5131 or EN 1090.
     */
    public function hasHeatNumber(): bool
    {
        return $this->heat_number !== null && $this->heat_number !== '';
    }
}
