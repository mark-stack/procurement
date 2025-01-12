<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use PhpParser\Node\Scalar\String_;

class SupplierResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'category' => $this->category,
            'created_at' => $this->created_at,
            "isUsed" => $this->isUsed(),
            "categoriesAsCommaString" => $this->categoriesAsCommaString($this->supplier_categories),
            "categoriesForm" => unserialize($this->supplier_categories),
        ];
    }

    private function categoriesAsCommaString($supplier_categories): String
    {
        $resultArray = [];

        $categories = unserialize($supplier_categories);
        foreach($categories as $categoryLabel => $value){
            //Is set TRUE
            if($value){
                $resultArray[] = $categoryLabel;
            }
        }

        return implode(",",$resultArray);
    }
}
