<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use MagicLink\Actions\LoginAction;
use MagicLink\MagicLink;

/*
 * Carries a magic link that signs its recipient in, so every method here reads that recipient
 * off $notifiable. It used to be handed a User in the constructor and ignore $notifiable, which
 * was only ever correct because the caller built a fresh instance inside a loop: sending one
 * instance to a collection - the obvious tidy-up, and the form the caller uses now - would have
 * given every user in the business a link that signed them in as the first of them.
 */
class WelcomeActivatedUserEmail extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(User $notifiable): array
    {
        return ['mail', 'database'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(User $notifiable): MailMessage
    {
        $action = new LoginAction($notifiable);

        /*
         * Following the link verifies the address, because reaching it is the same proof the
         * verification email asks for. Without this the link signed an unverified user in and
         * EnsureEmailIsVerified - which guards projects.index - bounced them straight to the
         * verification prompt, and an unverified signup is exactly who this email welcomes.
         */
        $action->response(function () {
            $user = auth()->user();

            if ($user && ! $user->hasVerifiedEmail() && $user->markEmailAsVerified()) {
                event(new Verified($user));
            }

            return redirect()->route('projects.index');
        });

        $magicLink = MagicLink::create($action)->url;

        return (new MailMessage)
            ->line('Your setup configuration is complete')
            ->action('Instant login', $magicLink)
            ->line('Thanks!');
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(User $notifiable): array
    {
        return [
            'new_user_email' => $notifiable->email,
            'new_user_name' => $notifiable->name,
        ];
    }
}
