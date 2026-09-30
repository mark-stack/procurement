<?php

namespace App\Listeners;

use App\Models\User;
use App\Services\NotificationImplementations\NotificationNewColleagueImplementation;
use Illuminate\Auth\Events\Verified;

/**
 * Tell the business about a new colleague once they have proved they can read the mailbox.
 *
 * This ran in RegisteredUserController, one line after the account was created. A business is every
 * user whose email domain matched, so registering name@somefabricator.com.au - an address the
 * registrant need not own - put a notification in every real employee's bell naming a stranger as
 * their colleague, and there is no invitation step anywhere that would have questioned it.
 *
 * Verification is the only thing in this flow that establishes the person is who the domain says,
 * so it is what the announcement hangs off. The arrival is still announced exactly once:
 * NotificationNewColleagueImplementation::hasBeenNotified() keys on the new user's id per
 * recipient, so a re-sent verification link or a second click on the one in the inbox adds nothing.
 */
class AnnounceVerifiedColleague
{
    public function handle(Verified $event): void
    {
        $user = $event->user;

        if (! $user instanceof User) {
            return;
        }

        (new NotificationNewColleagueImplementation)->notifyColleaguesOf($user);
    }
}
