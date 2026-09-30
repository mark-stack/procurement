<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class NewUserEmail extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new notification instance.
     */
    public function __construct(
        public object $user,
        public string $message,
    ) {}

    /**
     * Mail only.
     *
     * The database channel was here too, and the row it wrote was never rendered: the bell draws
     * what NotificationService::implementations() claims, and no implementation claims this type.
     * So every signup left an unread row in the admin's notifications table that no wording, no
     * button and no count ever reached - it could not even be marked read. The signal an admin
     * actually works from is this email and the users list it links to.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->line($this->message)
            ->line($this->user->email);
    }

    /*
     * toArray() went with the database channel. It shaped a row nothing rendered, and with mail
     * as the only channel nothing calls it.
     */
}
