<?php

namespace App\Jobs;

use App\Enums\NotificationEnums;
use App\Models\NotificationLog;
use App\Models\User;
use App\Notifications\NewUserEmail;
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
        $this->aColleagueJoined();

        //$this->isTentativeMaterialsDateCorrect(); //todo

        //$this->aQuoteIsDue(); //todo

        //$this->aQuoteIsOverDue(); //todo

        //$this->didYouSendTheQuote(); //todo

        //todo more
    }

    public function aColleagueJoined(): void
    {
        /**
         * A new colleague signed up. Notify existing staff users of the same business
         */
        $newUsers = User::query()
            ->where('created_at', '>=', Carbon::now()->subDays(2))
            ->get();

        foreach($newUsers as $newUser){
            $colleagues = $newUser->business->users()->where("id","!=",$newUser->id)->get();
            foreach($colleagues as $colleague){
                $colleagueAlreadyNotified = NotificationLog::query()
                    ->where('recipient_user_id', $colleague->id)
                    ->where('type',NotificationEnums::COLLEAGUE_JOINED)
                    ->where("unique_model_id",$newUser->id)
                    ->exists();

                if(!$colleagueAlreadyNotified){
                    //Send notification
                    $colleague->notify(new NewUserEmail($newUser));

                    //Log that the notification was sent
                    NotificationLog::create([
                        'recipient_user_id' => $colleague->id,
                        'type' => NotificationEnums::COLLEAGUE_JOINED,
                        "unique_model_id" => $newUser->id,
                    ]);
                }
            }
        }
    }
}
