<?php

namespace App\Services;

use App\Enums\NotificationEnums;
use Carbon\Carbon;

class NotificationService
{
    public function getNotifications(object $user = null): array
    {
        $notifications = [];

        if($user){
            //todo: general notifications
//            foreach ($user->notifications as $notification) {
//                dd($notification,$notification->type);
//            }


            $business = $user->business;

            /**
             * A colleague joined
             */
            $aColleagueJoined = $this->aColleagueJoined($user,$business);
            if($aColleagueJoined){
                foreach($aColleagueJoined as $notification){
                    $notifications[] = $notification;
                }
            }

            /**
             * Projects
             */
            /*
             * Is the tentative materials date still correct?
             */
            $isTentativeMaterialsDateCorrect = $this->isTentativeMaterialsDateCorrect($user,$business);
            if($isTentativeMaterialsDateCorrect){
                foreach($isTentativeMaterialsDateCorrect as $notification){
                    $notifications[] = $notification;
                }
            }

            /**
             * Quotes
             */
            /*
             * Quote due
             */
            $aQuoteIsDue = $this->aQuoteIsDue($user,$business);
            if($aQuoteIsDue){
                foreach($aQuoteIsDue as $notification){
                    $notifications[] = $notification;
                }
            }

            /*
             * Quote overdue
             */
            $aQuoteIsOverDue = $this->aQuoteIsOverDue($user,$business);
            if($aQuoteIsOverDue){
                foreach($aQuoteIsOverDue as $notification){
                    $notifications[] = $notification;
                }
            }

            /*
             * Did you send the quote?
             */
            $didYouSendTheQuote = $this->didYouSendTheQuote($user,$business);
            if($didYouSendTheQuote){
                foreach($didYouSendTheQuote as $notification){
                    $notifications[] = $notification;
                }
            }

            /*
             * Did you receive & file the quote response?
             */

            /*
             * Quote by a colleague
             */

            /**
             * Orders
             */

            /*
             * Order due
             */

            /*
             * Order overdue
             */

            /*
             * Did you place the order?
             */

            /*
             * Did you receive order confirmation?
             */

            /*
             * Order by a colleague
             */

            /*
             * Order approval required
             */
        }

        return $notifications;
    }

    /**
     * @deprecated
     */
    public function aColleagueJoined(object $user, object $business): null|array
    {
        $result = null;
        /**
         * A staff of this business joined within 2 days
         */
        $newStaff = $business->users()
            ->where('created_at', '>=', Carbon::now()->subDays(2))
            ->get();

        foreach($newStaff as $staff){
            $newStaff[] = [
                "message" => $staff->name." recently joined. You can now batch orders together.",
                "timestamp" => "8 minutes ago",
                "image" => "https://cdn-icons-png.flaticon.com/256/11161/11161548.png",
            ];
        }

        return $result;
    }

    /**
     * @deprecated
     */
    public function isTentativeMaterialsDateCorrect(object $user, object $business): null|array
    {
        return null; //todo
    }

    /**
     * @deprecated
     */
    public function aQuoteIsDue(object $user, object $business): null|array
    {
        return null; //todo
    }

    /**
     * @deprecated
     */
    public function aQuoteIsOverDue(object $user, object $business): null|array
    {
        return null; //todo
    }

    /**
     * @deprecated
     */
    public function didYouSendTheQuote(object $user, object $business): null|array
    {
        return null; //todo
    }
}
