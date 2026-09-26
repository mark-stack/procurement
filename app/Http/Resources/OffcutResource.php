<?php

namespace App\Http\Resources;

use App\Models\Offcut;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The offcut's own methods are reached through the resource, so say which model is behind it rather
 * than leaving every one of them looking undefined.
 *
 * @mixin Offcut
 */
class OffcutResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        //Resolved once - this used to call batchFrom() five times, each a separate findOrFail
        $batchFrom = $this->batchFrom();
        $business = $batchFrom->user->business;

        return [
            'id' => $this->id,

            //Batch
            'batch_from_id' => $this->batch_from_id,
            'batch_to_id' => $this->batch_to_id,

            //Piece
            'piece_to_id' => $this->piece_to_id,

            //Bar
            'bar_id' => $this->bar_id,

            //Offcut this was cut from, when it came from an offcut rather than a bar
            'offcut_from_id' => $this->offcut_from_id,

            /*
             * How far back the steel is: 1 straight off a bar, 2 an offcut of that offcut, and so on.
             * There is no cap on this - each generation is real steel in the yard - so the yard needs to
             * be able to see how many times a piece has already been cut down, and to read the marks it
             * wore on the way.
             */
            'generation' => $this->generation(),
            'cut_from_marks' => $this->ancestors()->pluck('unique_mark')->all(),

            //Product attributes
            'product_category' => $this->product_category,//PFC
            'material' => $this->material,//PLAIN CARBON STEEL
            'grade' => $this->grade,//GR250
            'surface' => $this->surface,//NONE
            'nominal_length' => $this->nominal_length,//9000
            'precise_length' => $this->precise_length,//
            'nominal_width' => $this->nominal_width,//
            'precise_width' => $this->precise_width,//
            'nominal_height' => $this->nominal_height,//200
            'precise_height' => $this->precise_height,//
            'wall' => $this->wall,//
            'length' => $this->length,

            //Unique mark
            'unique_mark' => $this->unique_mark,

            //Derived
            'bar' => $this->bar,
            //Derived from the offcut's own spec, because bar_id is nullable - see Offcut
            'label' => $this->product_derived_label,
            /*
             * The source batch is identified by batch_from_id/batch_projects above. The whole Batch used
             * to be serialised here, which carries nested_state - the entire saved nest - once per row.
             *
             * Only for an offcut that came off a bar of new stock, because only then did its own batch
             * buy this steel. An offcut cut from another offcut got no new stock at all: its batch may
             * still have bought steel for other products, and crediting those certificates here stamped
             * the offcut with a certificate for material it was never part of. Its trail runs back up
             * the chain instead, through offcutOrdersWithCertificates below.
             */
            'newStockOrdersWithCertificates' => $this->offcut_from_id === null
                ? $batchFrom->newStockOrdersWithCertificates()
                : collect([]),
            'offcutOrdersWithCertificates' => $batchFrom->offcutOrdersWithCertificates($business),
            'batch_projects' => $batchFrom->projectSummaries(),
        ];
    }
}
