<?php

namespace App\Notifications\Concerns;

use MagicLink\Actions\LoginAction;
use MagicLink\MagicLink;

/**
 * The one way this application mints an emailed login.
 *
 * Every notification that carries a magic link called MagicLink::create($action) with no further
 * arguments, which takes the package's defaults: a three-day lifetime and an unlimited number of
 * visits. That is a password-less login to a fabricator's account, reusable by anyone the thread
 * reaches - and procurement mail is forwarded to merchants as a matter of routine.
 *
 * Here rather than repeated at each call site so the six cannot drift apart, and so the numbers live
 * in config/magiclink.php where the reasoning for them is written down.
 */
trait SignsInByLink
{
    /**
     * @param  LoginAction  $action  Already carrying its ->response(), which decides where the
     *                               recipient lands once the link has logged them in.
     * @param  string  $profile  Which set of numbers in config/magiclink.php to mint under.
     *                           "login" is the schedule-chased reminders, which are replaced if
     *                           they go unread. The activation welcome is sent once and is a
     *                           customer's first way in, so it has its own - see the config.
     */
    protected function loginLinkFor(LoginAction $action, string $profile = 'login'): string
    {
        return MagicLink::create(
            $action,
            (int) config("magiclink.{$profile}.lifetime_minutes"),
            (int) config("magiclink.{$profile}.max_visits"),
        )->url;
    }
}
