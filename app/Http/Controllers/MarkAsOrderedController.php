<?php

namespace App\Http\Controllers;

use App\Models\Order;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class MarkAsOrderedController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(Request $request, Order $order): RedirectResponse
    {
        $order->order_sent = true;
        $order->save();

        return back();
    }
}
