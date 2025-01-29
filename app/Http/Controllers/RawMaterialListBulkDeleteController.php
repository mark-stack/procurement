<?php

namespace App\Http\Controllers;

use App\Models\Piece;
use App\Models\RawMaterialQuote;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class RawMaterialListBulkDeleteController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(Request $request): RedirectResponse
    {
        /**
         * Delete imported material list and derived PIECE objects
         */
        $ids = $request->selectedRawMaterialQuoteIds;

        $rawMaterialQuotes = RawMaterialQuote::query()
            ->whereIn("id",$ids)
            ->get();


        //Detach pieces from quote
        $allAssociatedQuotes = [];
        $allAssociatedOrders = [];
        foreach($rawMaterialQuotes as $rawMaterialQuote){
            $piece = $rawMaterialQuote->piece;
            if($piece){
                //Associated quotes
                foreach($piece->quotes as $quote){
                    //if sent
                    if($quote->quote_sent){
                        $allAssociatedQuotes = $quote;
                    }
                }
                //Associated orders
                if($piece->order){
                    //if sent
                    if($piece->order->order_sent){
                        $allAssociatedOrders[] = $piece->order;
                    }
                }

                $piece->quotes()->detach();
            }
        }

//        dd([
//            "allAssociatedQuotes" => $allAssociatedQuotes,
//            "allAssociatedOrders" => $allAssociatedOrders,
//        ]);

        //todo if all items/pieces from a supplier category were deleted, then delete the associated quote and order

        /*
         * PIECE's have a QUOTE and ORDER that may not be sent. e.g order->order_sent = false.
         *
         */
        //Delete pieces
        Piece::query()
            ->whereIn("raw_material_quote_id",$ids)
            ->delete();

        //Delete Material list
        RawMaterialQuote::query()
            ->whereIn("id",$ids)
            ->delete();

        return back();
    }
}
