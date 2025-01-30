<?php

namespace App\Actions\OrderApproval;

use App\Models\Batch;
use App\Models\OrderApproval;
use Illuminate\Support\Collection;
use Lorisleiva\Actions\Concerns\AsAction;

class CreatePendingOrderApprovals
{
    use AsAction;

    public function handle(Collection $projectsReadyForBatching, Batch $batch): void
    {
        foreach ($projectsReadyForBatching as $project) {
            OrderApproval::create([
                'batch_id' => $batch->id,
                'project_id' => $project->id,
                'project_manager_approved' => false,
            ]);
        }
    }
}
