<?php

namespace App\Actions\OrderApproval;

use App\Models\Batch;
use App\Models\OrderApproval;
use Lorisleiva\Actions\Concerns\AsAction;

class CreatePendingOrderApprovals
{
    use AsAction;

    /**
     * One pending approval per project this batch actually carries.
     *
     * Read off the batch's own pieces rather than from the list of projects that were ready for
     * batching, because the two are known to differ and QuoteController says so: a project awaiting a
     * price book clarification is excluded from projectsReadyForBatching while its already-matched
     * pieces are swept into the nest anyway. Its owner is the one person on the batch who was never
     * asked, and they were the one with no row recording it - "Sent order" walks $batch->orderApprovals
     * (UpdateOrderApprovalStatus), so nothing was ever written for them, while the confirm dialog named
     * them out loud as somebody being committed on their behalf.
     *
     * firstOrCreate so an approval that already exists keeps whatever it has recorded. Creating a batch
     * is the only caller, but the row carries approved_by_user_id and approved_at now, and those are
     * not something a second pass should quietly reset.
     */
    public function handle(Batch $batch): void
    {
        foreach ($batch->projectApprovalFlags() as $project) {
            OrderApproval::firstOrCreate(
                [
                    'batch_id' => $batch->id,
                    'project_id' => $project->id,
                ],
                [
                    'project_manager_approved' => false,
                ],
            );
        }
    }
}
