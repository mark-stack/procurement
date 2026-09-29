<?php

return [
    /*
     * Whether the deadline reminders also go out by email, on top of the bell.
     *
     * Off by default, and that is the point. The hourly checks were switched off wholesale while
     * the bell was switched off, so turning the schedule back on turns five notification classes
     * back into outbound email to real project managers in one commit. The bell is in-app, costs
     * nobody an inbox, and is what was asked for - so the database channel is unconditional and
     * mail is the thing you opt into once somebody has read the wording and decided they want it.
     *
     * Only the reminders read this. The colleague notifications are bell-only by design and do not
     * consult it: they say "somebody just did this to your project", which is worth a red dot next
     * time you look and is not worth an email.
     */
    'mail_reminders' => (bool) env('NOTIFICATIONS_MAIL_REMINDERS', false),
];
