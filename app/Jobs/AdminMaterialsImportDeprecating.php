<?php

namespace App\Jobs;

use App\Models\User;
use App\Notifications\AdminImportFinalised;
use Illuminate\Bus\Batch;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * @deprecated
 */
class AdminMaterialsImportDeprecating implements ShouldQueue
{
    use Queueable;

    public Collection $dataCollection;
    public object $allCurrentMasterProductRecords;

    public function __construct($dataCollection,$allCurrentMasterProductRecords)
    {
        $this->dataCollection = $dataCollection;
        $this->allCurrentMasterProductRecords = $allCurrentMasterProductRecords;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        /*
       * 2) Deprecate: not present in master sheet anymore
       * Loop DB looking for spreadsheet matches
       */
        $jobs2 = [];
        foreach($this->allCurrentMasterProductRecords as $productObject){
            $jobs2[] = new AdminMaterialsImportSubJob2($productObject, $this->dataCollection);
        }

        $batch2 = Bus::batch($jobs2)
            ->before(function (Batch $batch) {
                // The batch has been created but no jobs have been added...
            })->progress(function (Batch $batch) {
                // A single job has completed successfully...
            })->then(function (Batch $batch) {
                // All jobs completed successfully...
            })->catch(function (Batch $batch, Throwable $e) {
                // First batch job failure detected...
            })->finally(function (Batch $batch){
                // The batch has finished executing...
                /**
                 * Send completion email
                 */
                //Admin notify
                $adminUser = User::query()->where("email",config("env.admin_email"))->first();
                if($adminUser){
                    $message = "The import finalised.";
                    Notification::send($adminUser, new AdminImportFinalised($message));
                }
            })->dispatch();
    }
}
