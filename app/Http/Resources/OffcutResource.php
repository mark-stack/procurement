<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

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
            //The source batch is identified by batch_from_id/batch_projects above. The whole Batch used
            //to be serialised here, which carries nested_state - the entire saved nest - once per row
            'newStockOrdersWithCertificates' => $batchFrom->newStockOrdersWithCertificates(),
            'offcutOrdersWithCertificates' => $batchFrom->offcutOrdersWithCertificates($business),
            'batch_projects' => $batchFrom->projectSummaries(),
        ];
    }
}
