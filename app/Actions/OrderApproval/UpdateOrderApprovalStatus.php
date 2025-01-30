<?php

namespace App\Actions\OrderApproval;

use App\Models\Batch;
use Lorisleiva\Actions\Concerns\AsAction;

class UpdateOrderApprovalStatus
{
    use AsAction;

    public function handle(Batch $batch, bool $status): void
    {
        foreach ($batch->orderApprovals as $orderApproval) {
            $orderApproval->project_manager_approved = $status;
            $orderApproval->save();
        }
    }
}
