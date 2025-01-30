<?php

namespace App\Services;

use App\Models\Batch;
use Carbon\Carbon;

class QuoteService
{
    public function batchQuotingDeadline(Batch $batch): ?Carbon
    {
        $dates = [];
        foreach ($batch->projects() as $project) {
            $dates[] = Carbon::parse($project->date_materials_required);
        }

        return collect($dates)->min();
    }
}
