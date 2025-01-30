<?php

namespace App\Models;

use App\Services\BatchService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

class Batch extends Model
{
    protected $guarded = [];

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
    public function projects(): Collection
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

    //Local scope
//    public function scopeHasAtLeastOneSentOrder($query)
//    {
//        return $query->whereHas('orders', function ($query) {
//            $query->where('order_sent', true);
//        });
//    }

    public function scopeHasNoSentOrder($query)
    {
        return $query->whereDoesntHave('orders', function ($query) {
            $query->where('order_sent', true);
        });
    }
}
