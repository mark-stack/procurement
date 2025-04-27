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
        $business = $this->batchFrom()->user->business;

        return [
            'id' => $this->id,

            //Batch
            'batch_from_id' => $this->batch_from_id,
            'batch_to_id' => $this->batch_to_id,

            //Piece
            'piece_to_id' => $this->piece_to_id,

            //Bar
            'bar_id' => $this->bar_id,

            //Product attributes
            'product_category' => $this->roduct_category,//PFC
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
            "unique_mark" => $this->unique_mark,

            //Derived
            "bar" => $this->bar,
            'label' => $this->bar->product_derived_label,
            "batch_from" => $this->batchFrom(),
            "newStockOrdersWithCertificates" => $this->batchFrom()->newStockOrdersWithCertificates(),
            "offcutOrdersWithCertificates" => $this->batchFrom()->offcutOrdersWithCertificates($business),
            "batch_projects" => $this->batchFrom()->projects(),
        ];
    }
}
