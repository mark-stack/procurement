<?php

namespace App\Notifications\Concerns;

/**
 * A reminder that always reaches the bell, and reaches the inbox only if asked to.
 *
 * Every reminder class hard-coded ['mail', 'database'], so there was no way to have the in-app
 * notifications without the email. That is why reinstating the bell and re-enabling the hourly
 * checks would otherwise be the same decision as starting to email customers again.
 *
 * Worth knowing: the reminder mails mint a MagicLink login token in toMail(). Laravel only calls
 * toMail() for channels via() returns, so with mail off no token is created either.
 *
 * See config/notifications.php.
 */
trait BellFirst
{
    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return config('notifications.mail_reminders')
            ? ['mail', 'database']
            : ['database'];
    }
}
