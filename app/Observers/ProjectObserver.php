<?php

namespace App\Observers;

use App\Models\Project;
use App\Services\NotificationService;

class ProjectObserver
{
    /**
     * Handle the Project "created" event.
     */
    public function created(Project $project): void
    {
        //dd("created",$project);
    }

    /**
     * Handle the Project "updated" event.
     */
    public function updated(Project $project): void
    {
        /*
         * An archived project is off the board, so nothing it is being chased about can be
         * acted on. No implementation below reads the archive flag, so without this its
         * reminders sit unread in the bell for a project the user cannot even see.
         */
        if ($project->wasChanged('archive') && $project->archive) {
            (new NotificationService)->clearProjectNotifications($project);
        }

        /*
         * Check if any notifications are now redundant because of this update
         */
        foreach ((new NotificationService)->implementations() as $implementation) {
            $implementation->checkProjectChanges($project);
        }
    }

    /**
     * Handle the Project "deleted" event.
     */
    public function deleted(Project $project): void
    {
        //
    }

    /**
     * Handle the Project "restored" event.
     */
    public function restored(Project $project): void
    {
        //
    }

    /**
     * Handle the Project "force deleted" event.
     */
    public function forceDeleted(Project $project): void
    {
        //
    }
}
