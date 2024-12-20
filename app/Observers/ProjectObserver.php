<?php

namespace App\Observers;

use App\Models\Project;
use App\Services\NotificationService;
use Illuminate\Support\Facades\Log;

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
         * Check if any notifications are now redundant because of this update
         */
        $implementations = (new NotificationService())->getImplementations();
        foreach($implementations as $implementation){
            $className = 'App\\Services\\NotificationImplementations\\'.$implementation;

            // Check if the class exists
            if (class_exists($className)) {
                $service = new $className();
                $service->checkProjectChanges($project);
            }
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
