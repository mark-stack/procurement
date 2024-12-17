<?php

namespace App\Jobs;

use App\Enums\NotificationEnums;
use App\Models\NotificationLog;
use App\Models\Project;
use App\Models\User;
use App\Notifications\NewUserEmail;
use App\Notifications\ProjectAwardedCheckEmail;
use App\Services\Interfaces\NotificationMaterialsDateImplementation;
use App\Services\Interfaces\NotificationNewColleagueImplementation;
use App\Services\Interfaces\NotificationProjectAwardedImplementation;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;

class HourlyNotificationsJob implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new job instance.
     */
    public function __construct()
    {
        //
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        /**
         * Don't stack up notifications.
         * To “re-remind”, mark the previous as read and create new one
         */

        //A new colleague signed up
        (new NotificationNewColleagueImplementation())->hourlyCheck();

        //Has the project been awarded to you?
        (new NotificationProjectAwardedImplementation())->hourlyCheck();

        //Is the tentative materials date still correct?
        (new NotificationMaterialsDateImplementation())->hourlyCheck();

        //$this->aQuoteIsDue(); //todo

        //$this->aQuoteIsOverDue(); //todo

        //$this->didYouSendTheQuote(); //todo

        //todo more
    }

    /**
     * @deprecated
     */
    public function aColleagueJoined(): void
    {
        /**
         * A new colleague signed up. Notify existing staff users of the same business
         * 1) User created within 2 days
         * 2) Not yourself
         */
        $class = "NewUserEmail";

        $newUsers = User::query()
            ->where('created_at', '>=', Carbon::now()->subDays(2))
            ->get();

        foreach($newUsers as $newUser){
            $colleagues = $newUser->business->users()->where("id","!=",$newUser->id)->get();
            foreach($colleagues as $colleague){
                $notifiedAlready = $colleague->notifications()
                    ->where("type","App\Notifications\{$class}")
                    ->where("notifiable_type","App\Models\User")
                    ->exists();

                if(!$notifiedAlready){
                    //Mark all previous as read
                    $this->markAllSimilarAsRead($colleague,$class);

                    //Send notification
                    $colleague->notify(new NewUserEmail($newUser));
                }
            }
        }
    }

    /**
     * @deprecated
     */
    public function hasTheProjectBeenAwardedToYou($interval): void
    {
        /**
         * Has the project been awarded to you?
         * 1) Project is active (not archived)
         * 2) Project "awarded" = false
         * 3) At least 2 days since creating the project (so it doesn't immediate send)
         * 4) At least 2 days since last message
         */
        $class = "ProjectAwardedCheckEmail";

        $nonAwardedProjects = Project::query()
            ->active()                                                  //1) Project is active (not archived)
            ->where("awarded",false)                                    //2) Project "awarded" = false
            ->where('created_at', '>=', Carbon::now()->$interval(2))    //3) At least 2 days since creating the project (so it doesn't immediate send)
            ->get();

        foreach($nonAwardedProjects as $project){
            $projectManager = $project->user;

            //Last message
            $notifiedAlready = $projectManager->notifications()
                ->where("type","App\Notifications\{$class}")
                ->where("notifiable_type","App\Models\User")
                ->where('created_at', '>=', Carbon::now()->$interval(2))  //4) At least 2 days since last message
                ->exists();

            if(!$notifiedAlready){
                //Mark all previous as read
                $this->markAllSimilarAsRead($projectManager,$class);

                //Send notification
                $projectManager->notify(new ProjectAwardedCheckEmail($project,$projectManager));
            }
        }
    }

    /**
     * @deprecated
     */
    public function isTentativeMaterialsDateCorrect($interval): void
    {
        /**
         * Is the materials date still correct?
         * 1) Project is active (not archived)
         * 2) Project tentative = true
         * 3) At least 2 days since creating the project (so it doesn't immediate send)
         * 4) At least 2 days since last message
         */
        $class = "ProjectTentativeDateCheckEmail";

        $tentativeProjects = Project::query()
            ->active()                                                  //1) Project is active (not archived)
            ->where("tentative",true)                                   //2) Project tentative = true
            ->where('created_at', '>=', Carbon::now()->$interval(2))    //3) At least 2 days since creating the project (so it doesn't immediate send)
            ->get();

        foreach($tentativeProjects as $project){
            $projectManager = $project->user;

            //Last message
            $notifiedAlready = $projectManager->notifications()
                ->where("type","App\Notifications\{$class}")
                ->where("notifiable_type","App\Models\User")
                ->where('created_at', '>=', Carbon::now()->$interval(2))  //4) At least 2 days since last message
                ->exists();

            if(!$notifiedAlready){
                //Mark all previous as read
                $this->markAllSimilarAsRead($projectManager,$class);

                //Send notification
                $projectManager->notify(new ProjectAwardedCheckEmail($project,$projectManager));
            }
        }
    }
    /**
     * @deprecated
     */
    public function markAllSimilarAsRead(object $user, string $class): void
    {
        $user->notifications()
            ->where("type","App\Notifications\{$class}")
            ->where("notifiable_type","App\Models\User")
            ->update(['read_at' => now()]);
    }
}
