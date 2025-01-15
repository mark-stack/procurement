<?php

namespace App\Http\Controllers;

use App\Models\Batch;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ApproveAllProjectManagersController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(Request $request, Batch $batch): RedirectResponse
    {
        foreach($batch->orderApprovals as $orderApproval){
            $orderApproval->project_manager_approved = true;
            $orderApproval->save();
        }

        return back();
    }
}
