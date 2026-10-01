<?php

namespace App\Http\Resources;

use App\PrerequisiteConditions\PrerequisiteConditions;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProjectResource extends JsonResource
{
    /**
     * A project as a kanban card and the two modals behind it need it.
     *
     * The percentages and the four deadlines are gone. Nothing rendered any of them: the deadline
     * fields were read only by KanbanReadyForNestingCard and KanbanNeedsImportingCard, which no page
     * imports any more, and neither percentage appears in the frontend at all. Between them they
     * walked rawMaterialQuotes > piece > quotes/order for every row of every card, which is the only
     * reason drawing a card needed that tree loaded.
     *
     * percentageOfMaterialsOrdered() is still load-bearing - it routes batches between the Ordering
     * and Delivering columns, and the overdue notifications read it - just not from here.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $user = auth()->user();

        //The resource already holds the project - re-fetching it ran one extra query per row
        $project = $this->resource;

        return [
            'id' => $project->id,
            'name' => $project->name,
            'user_id' => $project->user_id,
            'reference' => $project->reference,
            //The edit modal reuses the card's project, so these come with it
            'date_materials_required' => $project->date_materials_required,
            /*
             * Trimmed to just the date part. The column is a date, but MySQL hands it back as
             * "2026-11-02 00:00:00" through this cast-less model, and a date input shows nothing at
             * all for a value it cannot parse - so the edit form would open empty on a project that
             * has a fabrication date and quietly offer to clear it.
             */
            'date_fabrication_begins' => $project->date_fabrication_begins
                ? substr((string) $project->date_fabrication_begins, 0, 10)
                : null,
            'tentative' => $project->tentative,
            'archive' => $project->archive,
            'projectManager' => $project->user,
            /*
             * Who uploaded the material list, when that was not the manager it is for - a draftsman
             * detailing the job for a colleague. Null for the ordinary case, so the card draws
             * nothing extra on the projects most businesses have.
             *
             * A name rather than the user object: the only thing drawn off it is a sentence saying
             * who to go and ask about the spreadsheet, the same way UnfinishedImportResource carries
             * projectManagerName.
             *
             * The id comes with it because "you" reads better than your own name on your own work,
             * and the card already draws the owner that way off user_id.
             */
            'created_by_user_id' => $project->created_by_user_id,
            'uploadedByName' => $project->createdBy?->name,
            //The relation, not a fresh count query - callers that eager load it then pay nothing here
            'qtyMaterialRows' => $project->rawMaterialQuotes->count(),
            "prerequisiteUploadMaterials" => $user
                ? (new PrerequisiteConditions())->uploadMaterials($user, $project)
                : false,
        ];
    }
}
