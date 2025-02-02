<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Offcut extends Model
{
    /** @use HasFactory<\Database\Factories\OffcutFactory> */
    use HasFactory;

    protected $guarded = [];

    public function batchFrom(): Batch
    {
        return Batch::findOrFail($this->batch_from_id);
    }

    public function batchTo(): Batch
    {
        return Batch::findOrFail($this->batch_to_id);
    }

    public function pieceTo(): Batch
    {
        return Piece::findOrFail($this->piece_to_id);
    }
}
