<?php

namespace App\Http\Resources;

use App\Models\Project;
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
        $project = Project::query()->findOrFail($this->id);

        return [
            'created_at' => $project->created_at,
            'id' => $project->id,
            'name' => $project->name,
            'user_id' => $project->user_id,
            'awarded' => $project->awarded,
            'reference' => $project->reference,
            'date_materials_required' => $project->date_materials_required,
            'tentative' => $project->tentative,
            'archive' => $project->archive,
//            "hasRawMaterialQuotes" => $this->rawMaterialQuotes()->count() > 0,
//            "percentageOfMaterialsQuoted" => $this->percentageOfMaterialsQuoted(),
//            "percentageOfMaterialsOrdered" => $this->percentageOfMaterialsOrdered(),
            "daysUntilQuoteRequestDeadline" => $project->daysUntilQuoteRequestDeadline(),
            "quoteRequestDeadline" => $project->quoteRequestDeadline(),
            "orderDeadline" => $project->orderDeadline(),
            "projectManager" => $project->user,
            "qtyMaterialRows" => $project->rawMaterialQuotes()->count(),
        ];
    }
}
