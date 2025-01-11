<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;
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

    //optional
    public function quote(): HasOne
    {
        return $this->hasOne(Quote::class);
    }

    //optional
    public function order(): HasOne
    {
        return $this->hasOne(Order::class);
    }

    //Collection
    public function projects(): Collection
    {
        $pieces = $this->pieces;
        $projectIds = [];

        foreach($pieces as $piece){
            $projectIds[] = $piece->project->id;
        }

        $uniqueProjectIds = array_unique($projectIds);

        return Project::query()
            ->whereIn("id",$uniqueProjectIds)
            ->get();
    }
}
