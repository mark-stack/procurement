<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProjectResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'created_at' => $this->created_at,
            'id' => $this->id,
            'name' => $this->name,
            'user_id' => $this->user_id,
            'awarded' => $this->awarded,
            'reference' => $this->reference,
            'date_materials_required' => $this->date_materials_required,
            'tentative' => $this->tentative,
            'archive' => $this->archive,
            "hasRawMaterialQuotes" => $this->rawMaterialQuotes()->count() > 0,
            "percentageOfMaterialsQuoted" => $this->percentageOfMaterialsQuoted(),
            "percentageOfMaterialsOrdered" => $this->percentageOfMaterialsOrdered(),
        ];
    }
}
