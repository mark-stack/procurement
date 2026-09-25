<?php

namespace App\Http\Controllers;

use App\Models\Order;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class OrderMarkDeliveredController extends Controller
{
    public function __invoke(Request $request, Order $order): RedirectResponse
    {
        Gate::authorize('owned', $order);

        //Mark delivered
        //Offcuts are created up front by Actions/Bar/CreateBarsAndOffcuts when the batch is nested;
        //delivery only decides whether they count as available (see Business::availableOffcuts)
        $order->is_delivered = !$order->is_delivered;
        $order->save();

        return back();
    }
}
