<?php

namespace App\Http\Resources;

use App\PrerequisiteConditions\PrerequisiteConditions;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UnfinishedImportResource extends JsonResource
{
    /**
     * A project whose import stopped at an unconfirmed price book match, as the Nesting column's
     * "finish importing" list needs it: a name, an owner to name when it is not yours, and an id
     * to open the Bill of Materials by.
     *
     * Slim for the same reason ArchivedProjectResource is - this list only grows, and none of
     * ProjectResource's per-row piece/quote/order walking is drawn here.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $project = $this->resource;

        return [
            'id' => $project->id,
            'name' => $project->name,
            'user_id' => $project->user_id,
            /*
             * Whose it is, so a colleague knows who to go and ask rather than just seeing it
             * stuck. A name, not the user object ProjectResource's "projectManager" carries -
             * nothing here draws anything else off it.
             */
            'projectManagerName' => $project->user->name,
            /*
             * The same flag ProjectResource carries, under the same name, because the Bill of
             * Materials modal this list opens reads it to decide whether to offer the upload and
             * clarification forms at all.
             */
            'prerequisiteUploadMaterials' => $request->user()
                ? (new PrerequisiteConditions())->uploadMaterials($request->user(), $project)
                : false,
        ];
    }
}
