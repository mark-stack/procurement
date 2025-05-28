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
        $order->is_delivered = !$order->is_delivered;
        $order->save();

        //Generate offcuts
        //GenerateOffcuts::run($order->batch);

        return back();
    }
}
