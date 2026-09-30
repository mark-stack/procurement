<?php
/**
 * @deprecated
 */
namespace App\Notifications;

use App\Notifications\Concerns\SignsInByLink;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use MagicLink\Actions\LoginAction;

class ProjectAwardedCheckEmail extends Notification implements ShouldQueue
{
    use Queueable, SignsInByLink;

    /**
     * Create a new notification instance.
     */
    public function __construct(
        public object $project,
        public object $recipient,
        public string $message,
    ) {}

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        //Go to projects page which has notifications for actioning
        $action = new LoginAction($this->recipient);
        $action->response(redirect()->route('projects.index'));
        $magicLinkUrl = $this->loginLinkFor($action);

        return (new MailMessage)
            ->line($this->message)
            ->action('Action this', $magicLinkUrl);
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'project_id' => $this->project->id,
            'project_name' => $this->project->name,
        ];
    }
}
