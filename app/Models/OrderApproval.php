<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderApproval extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'project_manager_approved' => 'boolean',
        ];
    }

    //Relationships
    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class);
    }
}
