<?php

namespace App\Actions\OrderApproval;

use App\Models\Batch;
use App\Models\User;
use Lorisleiva\Actions\Concerns\AsAction;

class UpdateOrderApprovalStatus
{
    use AsAction;

    /**
     * One person presses "Sent order" and this settles the approval for every project on the
     * batch, colleagues' included. That is what the button has always done; what it did not do
     * was record whose press it was, which left a row asserting that every project manager had
     * approved when only one of them had been in the room.
     *
     * $approver is that person. Null where there is nobody to name - undoing a sent order clears
     * the approval rather than transferring it.
     */
    public function handle(Batch $batch, bool $status, ?User $approver = null): void
    {
        foreach ($batch->orderApprovals as $orderApproval) {
            $orderApproval->project_manager_approved = $status;
            $orderApproval->approved_by_user_id = $status ? $approver?->id : null;
            $orderApproval->approved_at = $status ? now() : null;
            $orderApproval->save();
        }
    }
}
