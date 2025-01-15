<?php

namespace App\Services;

use App\Models\Batch;

class OrderService
{
    public function allProjectManagersApproved(Batch $batch): bool
    {
        $totalOrderApprovals = $batch->orderApprovals()->count();

        $orderApprovalsMarkedAsApproved = $batch->orderApprovals()
            ->where("project_manager_approved",true)
            ->count();

        //Exclude when there's 0 order approvals
        return $totalOrderApprovals > 0 && $totalOrderApprovals === $orderApprovalsMarkedAsApproved;
    }
}

