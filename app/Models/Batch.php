<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

class Batch extends Model
{
    /** @use HasFactory<\Database\Factories\BatchFactory> */
    use HasFactory;

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
