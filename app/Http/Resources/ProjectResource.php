<?php

namespace App\Http\Resources;

use App\Models\Project;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use App\Services\ProductService;

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

        $productService = new ProductService();

        $qtyMaterialRows = 0;
        $business = $project->user->business;
        foreach($project->rawMaterialQuotes as $rawMaterialQuote){
            $getProductMatchOptions = $productService->getProductMatchOptions($business,$rawMaterialQuote);
            if($getProductMatchOptions && $getProductMatchOptions["status"] === "EXACT"){
                $qtyMaterialRows++;
            }
        }

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
            "percentageOfMaterialsQuoted" => $this->percentageOfMaterialsQuoted(),
            "percentageOfMaterialsOrdered" => $this->percentageOfMaterialsOrdered(),
            "daysUntilCriticalPathDeadline" => $project->daysUntilCriticalPathDeadline(),
            "criticalPathDeadline" => $project->criticalPathDeadline(),
            "quotingDeadline" => $project->criticalPathDeadline(),
            "orderingDeadline" => $project->orderingDeadline(),
            "deliveryDeadline" => $project->deliveryDeadline(),
            "projectManager" => $project->user,
            "qtyMaterialRows" => $qtyMaterialRows,
        ];
    }
}
