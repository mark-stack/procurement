<?php

namespace App\Http\Controllers;

use App\Models\Order;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ApproveAllProjectManagersController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(Request $request, Order $order): RedirectResponse
    {
        $order->all_project_manager_approvals = true;
        $order->save();

        return back();
    }
}
