<?php

namespace App\Http\Resources;

use App\Models\Project;
use App\PrerequisiteConditions\PrerequisiteConditions;
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
        $user = auth()->user();
        $project = Project::query()->findOrFail($this->id);

        return [
            'created_at' => $project->created_at,
            'id' => $project->id,
            'name' => $project->name,
            'user_id' => $project->user_id,
            'reference' => $project->reference,
            'date_materials_required' => $project->date_materials_required,
            'tentative' => $project->tentative,
            'archive' => $project->archive,
            'percentageOfMaterialsQuoted' => $this->percentageOfMaterialsQuoted(),
            'percentageOfMaterialsOrdered' => $this->percentageOfMaterialsOrdered(),
            'daysUntilCriticalPathDeadline' => $project->daysUntilCriticalPathDeadline(),
            'criticalPathDeadline' => $project->criticalPathDeadline(),
            'quotingDeadline' => $project->criticalPathDeadline(),
            'orderingDeadline' => $project->orderingDeadline(),
            'deliveryDeadline' => $project->deliveryDeadline(),
            'projectManager' => $project->user,
            'qtyMaterialRows' => $project->rawMaterialQuotes()->count(),
            "prerequisiteUploadMaterials" => $user
                ? (new PrerequisiteConditions())->uploadMaterials($user, $project)
                : false,
            "info" => [],
        ];
    }
}
