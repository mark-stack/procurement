<?php

namespace App\Models;

use Database\Factories\TemplateFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Template extends Model
{
    /** @use HasFactory<TemplateFactory> */
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
        ];
    }

    //Relationships
    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }
}
