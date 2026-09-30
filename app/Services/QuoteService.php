<?php

namespace App\Services;

use App\Models\Batch;
use App\Models\Project;
use Carbon\Carbon;

class QuoteService
{
    /**
     * The earliest date any project on this batch needs its materials by, or null when none of them
     * says.
     *
     * A tentative project legitimately carries no date - StoreProjectRequest has
     * date_materials_required nullable, and the board is built around projects whose dates are not
     * settled yet. Carbon::parse(null) is now(), so one such project used to drag the whole batch's
     * quoting deadline to this minute and report it as already overdue. A date nobody has given is not
     * a deadline of today; it is the absence of one, and the caller is the right place to decide what to
     * show for that.
     */
    public function batchQuotingDeadline(Batch $batch): ?Carbon
    {
        $dates = [];

        foreach ($batch->projects() as $project) {
            /** @var Project $project */
            if ($project->date_materials_required === null || $project->date_materials_required === '') {
                continue;
            }

            $dates[] = Carbon::parse($project->date_materials_required);
        }

        return collect($dates)->min();
    }
}
