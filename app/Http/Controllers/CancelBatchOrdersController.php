<?php

namespace App\Http\Controllers;

use App\Models\Batch;
use App\Models\Order;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class CancelBatchOrdersController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(Request $request, Batch $batch): RedirectResponse
    {
        /**
         * Detach orders from a batch & reset project manager approvals if:
         * 1) No orders sent
         */
        $validated = $request->validate([
            'orders' => ['required'],
        ]);

        //Check no orders sent
        $totalOrders = count($validated['orders']);
        $ordersNotSent = [];
        foreach($validated['orders'] as $orderData){
            //Not ordered
            $orderObject = Order::findOrFail($orderData["id"]);
            if(!$orderObject->order_sent){
                $ordersNotSent[] = $orderObject;
            }
        }

        //Detach orders from a batch & reset project manager approvals
        if(count($ordersNotSent) === $totalOrders){
            //Detach batch
            foreach($ordersNotSent as $order){
                $order->batch_id = null;
                $order->save();
            }

            //Reset project manager approvals
            foreach($batch->orderApprovals as $orderApproval){
                $orderApproval->project_manager_approved = false;
                $orderApproval->save();
            }
        }

        return back();
    }
}
