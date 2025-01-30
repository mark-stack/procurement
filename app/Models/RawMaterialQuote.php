<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class RawMaterialQuote extends Model
{
    protected $guarded = [];

    /**
     * Relationships
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function piece(): HasOne
    {
        return $this->hasOne(Piece::class);
    }

    /**
     * Strings
     */
    public function status(): ?string
    {
        $piece = $this->piece;
        $orderedOrder = false;
        $sentQuotes = false;

        if ($piece) {
            $orderedOrder = $piece->order ? $piece->order->order_sent === 1 : null;

            $sentQuotes = $piece->quotes()
                ->where('quote_sent', true)
                ->exists();
        }

        $status = null;
        if ($sentQuotes) {
            $status = 'QUOTED';
        }
        if ($orderedOrder) {
            $status = 'ORDERED';
        }

        return $status;
    }
}
