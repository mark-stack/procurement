<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ArchivedProjectResource extends JsonResource
{
    /**
     * An archived project as the board's archived list needs it: a name to show, an id to
     * restore by, and the archive flag the restore confirmation reads. ProjectResource
     * answers a card - deadlines, percentages quoted and ordered, material counts - and
     * walks the piece/quote/order tree per row to do it. None of that is drawn here.
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
            //The column is an unmodelled int, and the restore confirmation branches on this
            'archive' => (bool) $project->archive,
        ];
    }
}
