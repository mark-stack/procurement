<?php

namespace App\Notifications;

use App\Models\Business;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use MagicLink\Actions\LoginAction;
use MagicLink\MagicLink;

/**
 * The free trial is nearly up, or is up.
 *
 * Mail only, no database channel. The in-app notification list is driven by the
 * NotificationImplementations classes, each of which claims the notification types it understands;
 * a type none of them recognises is stored, shown nowhere, and stays unread forever.
 */
class TrialEndingEmail extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  int|null  $daysRemaining  null once the trial has actually run out
     */
    public function __construct(
        public Business $business,
        public ?int $daysRemaining,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        /*
         * Straight into the billing page, already logged in. The same LoginAction the activation
         * email uses - someone who last signed in a month ago should not have to remember a
         * password to keep an account they are trying to pay for.
         */
        $action = new LoginAction($notifiable);
        $action->response(redirect()->route('billing.index'));
        $magicLink = MagicLink::create($action)->url;

        if ($this->daysRemaining === null) {
            return (new MailMessage)
                ->subject('Your SteelNesting trial has ended')
                ->line('Your free trial has ended, so your account is now read-only.')
                ->line('Nothing has been deleted. Every project, batch, nesting plan and cutting list is still there to open, print and download - what is paused is creating and changing things.')
                ->action('Subscribe', $magicLink)
                ->line('If you would rather be invoiced than pay by card, reply to this email and one will be raised.');
        }

        $when = $this->daysRemaining <= 0
            ? 'today'
            : ($this->daysRemaining === 1 ? 'tomorrow' : "in {$this->daysRemaining} days");

        return (new MailMessage)
            ->subject("Your SteelNesting trial ends {$when}")
            ->line("Your free trial ends {$when}.")
            ->line('Subscribe before then and nothing changes. If you do not, the account goes read-only - you keep everything you have built, but you cannot add to it.')
            ->action('See plans', $magicLink)
            ->line('If you would rather be invoiced than pay by card, reply to this email and one will be raised.');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'business_id' => $this->business->id,
            'days_remaining' => $this->daysRemaining,
        ];
    }
}
