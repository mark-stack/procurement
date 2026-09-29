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
            'approved_at' => 'datetime',
        ];
    }

    //Relationships
    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class);
    }

    /**
     * Whoever pressed "Sent order", which settles the approval for the whole batch. Not
     * necessarily this project's manager - see UpdateOrderApprovalStatus.
     */
    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }
}
