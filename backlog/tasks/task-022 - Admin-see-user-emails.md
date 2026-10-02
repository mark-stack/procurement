---
id: TASK-022
title: 'Admin: see user emails'
status: Done
assignee: []
created_date: '2026-10-01 09:11'
updated_date: '2026-10-02 08:05'
labels: []
dependencies: []
ordinal: 28000
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
Add "Emails" to "/admin/users". This opens an index with all notifications. Notifications is bell notifications and emails and the index shows pill indicating which. This link from users index will filter list to email type only
<!-- SECTION:DESCRIPTION:END -->

## Decisions

<!-- SECTION:DECISIONS:BEGIN -->
Nothing recorded an email, so the index had nothing to read for half of itself. `notifications` is
Laravel's database channel and holds the bell's rows only; `notification_logs` is a two-column "have
I already sent this" mark kept by two commands, with no channel, recipient or subject. So the log came
first and the screen second.

`notification_deliveries`: one row per notification PER CHANNEL, written by
App\Listeners\RecordNotificationDelivery off Laravel's NotificationSent - the one event fired once per
channel per recipient, after the channel has run. Recorded there rather than at the call sites so the
log cannot drift: seventeen notification classes, two scheduled commands, the hourly job and the
resend button all go through it without knowing it exists.

A notification sent by both channels is two rows sharing one notification_id, which is also the
primary key of the bell row that send wrote - that is the join between this log and the bell. One row
would have to pick a channel to call it, and either choice is a lie about half of what happened.

The subject is read off the already-built message, never by calling toMail() again: six classes mint a
MagicLink in there, so logging a subject that way would issue a second password-less login per email
sent. There is a test pinning that.

The migration backfills existing bell notifications, so the screen is not empty on a platform that has
been sending for months. Mail has nothing to recover - its history starts here - and the empty state
says so, because the alternative reading is that mail is broken.

/admin/notifications shows both channels in one list, newest first, 50 a page, with a Bell/Email pill
per row and All/Bell/Email filter pills carrying counts. One list rather than a page each because the
question is about a person, not a channel: seeing both rows of one send is how "we told them twice" is
distinguishable from "the bell lit up and no mail went". An unrecognised ?channel= (email, say, which
is not what the mail channel is called) widens to everything rather than drawing an empty table on the
one page whose subject is whether mail is going out.

The users list links per row, like every other link on that page - ?channel=mail&user={id}. Counted on
the user and not the business, because mail is addressed to a person: an owner with four emails and a
draftsman with none is the normal case. Not coloured red at 0, unlike Templates, where 0 means a
customer cannot import - a signup that has needed nothing sent to it is not a problem to go and fix.

Read-only, with no resend button. The one thing worth sending by hand is the welcome and login link,
which has its own button and its own confirmation on the users list.

Fixed on the way: listener auto-discovery is ON (the framework default for app/Listeners), so the
hand-written Event::listen(Verified::class, ...) in AppServiceProvider was a SECOND registration -
`artisan event:list` showed AnnounceVerifiedColleague twice, and handle() ran twice per verification.
Nothing came of it only because hasBeenNotified() refused the second announcement. It cost something
immediately here: the new log, registered the same careful way, wrote every row twice. Discovery is
the one mechanism now and the misleading comment is replaced.
<!-- SECTION:DECISIONS:END -->
