<?php

namespace App\Http\Controllers;

use App\Models\Order;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class MarkOrderConfirmationReceivedController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(Request $request, Order $order): RedirectResponse
    {
        $order->order_confirmation_received = true;
        $order->save();

        return back();
    }
}
