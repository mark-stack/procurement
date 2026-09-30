<?php

namespace App\Http\Resources;

use App\Models\Supplier;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SupplierResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $supplier = Supplier::findOrFail($this->id); // @phpstan-ignore-line

        return [
            'id' => $supplier->id,
            'name' => $supplier->name,
            'category' => $supplier->category,
            'created_at' => $supplier->created_at,
            'isUsed' => $supplier->isUsed(),
            'categoriesAsCommaString' => $this->categoriesAsCommaString($supplier),
            'categoriesForm' => $supplier->categories(),
        ];
    }

    private function categoriesAsCommaString(Supplier $supplier): string
    {
        $resultArray = [];

        foreach ($supplier->categories() as $categoryLabel => $value) {
            //Is set TRUE
            if ($value) {
                $resultArray[] = $categoryLabel;
            }
        }

        return implode(',', $resultArray);
    }
}
