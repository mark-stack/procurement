<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use MagicLink\Actions\LoginAction;
use MagicLink\MagicLink;

class ProjectAwardedCheckEmail extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new notification instance.
     */
    public function __construct(
        public Object $project,
        public Object $recipient,
        public string $message,
    ) {}

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail','database'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        //Go to projects page which has notifications for actioning
        $action = new LoginAction($this->recipient);
        $action->response(redirect()->route("projects.index"));
        $magicLink = MagicLink::create($action);

        //MagicLink is being weird making default "localhost" instead of "http://127.0.0.1:8000"
        //$testMode = config("env.test_mode");
        //$baseUrl = $testMode ? 'http://127.0.0.1:8000' : redirect()->route("projects.index");
        //$magicLinkUrl = $magicLink->baseUrl($baseUrl)->url;
        $magicLinkUrl = $magicLink->url;

        return (new MailMessage)
            ->line($this->message)
            ->action("Action this",$magicLinkUrl);
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
