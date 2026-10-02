<?php

namespace App\Listeners;

use App\Models\NotificationDelivery;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Mail\SentMessage;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Notifications\Events\NotificationSent;
use Symfony\Component\Mime\Email;

/**
 * Write down what went out.
 *
 * Laravel fires NotificationSent once per channel per recipient, after the channel has done its
 * work - which is the only place in the application that knows a mail was actually built and handed
 * to the mailer. Recording here rather than at the call sites means the log cannot drift: the
 * seventeen notification classes, two scheduled commands, the hourly job and the one admin button
 * that resends a login link all go through it without knowing it exists.
 *
 * Not queued. It already runs wherever the send ran - inside the queue worker for the sixteen
 * ShouldQueue classes - and pushing a second job to record the first would make the log the thing
 * most likely to be lost.
 */
class RecordNotificationDelivery
{
    public function handle(NotificationSent $event): void
    {
        $notifiable = $event->notifiable;

        /*
         * A log of who was notified needs somebody to point at. Notification::route() sends to a
         * bare address with no row behind it, and nothing in this application does that today - the
         * five callers all notify a User - so this is the guard for the day one does, not a case
         * being quietly dropped.
         */
        if (! $notifiable instanceof Model) {
            return;
        }

        NotificationDelivery::create([
            /*
             * Laravel sets this on the notification instance before the first channel runs, so both
             * rows of a ['mail', 'database'] send carry the same id and the database row that send
             * wrote uses it as its primary key. That is the whole join between this log and the bell.
             */
            'notification_id' => $event->notification->id,
            'notifiable_type' => $notifiable->getMorphClass(),
            'notifiable_id' => $notifiable->getKey(),
            'channel' => $event->channel,
            'type' => $event->notification::class,
            //The address as at the send. See the migration for why it is not read back off the user
            'recipient_email' => $notifiable->email ?? null,
            'subject' => $this->subjectOf($event->response),
            'payload' => $this->payloadOf($event),
        ]);
    }

    /**
     * The subject line as sent, taken off the built message rather than by calling toMail() again.
     *
     * Calling toMail() a second time would not be free: six of these classes mint a MagicLink login
     * token in it, so logging the subject would mean issuing a second password-less login to the same
     * account for every email that goes out - see App\Notifications\Concerns\SignsInByLink.
     *
     * Null whenever there is no built message to read: every database send, and a mail send under
     * Mail::fake(), whose mailer returns nothing.
     */
    private function subjectOf(mixed $response): ?string
    {
        if (! $response instanceof SentMessage) {
            return null;
        }

        $message = $response->getOriginalMessage();

        return $message instanceof Email ? $message->getSubject() : null;
    }

    /**
     * What the notification carried.
     *
     * The database channel has already serialized it, so that row is the authority on what was
     * stored. For mail it comes from toArray(), which the three mail-only classes do not define -
     * NewUserEmail says why - and which is pure data on the fourteen that do.
     *
     * @return array<string, mixed>|null
     */
    private function payloadOf(NotificationSent $event): ?array
    {
        if ($event->response instanceof DatabaseNotification) {
            return $event->response->data;
        }

        if (! method_exists($event->notification, 'toArray')) {
            return null;
        }

        return $event->notification->toArray($event->notifiable);
    }
}
