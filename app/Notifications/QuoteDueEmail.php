<?php

namespace App\Notifications;

use App\Notifications\Concerns\BellFirst;
use App\Notifications\Concerns\SignsInByLink;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use MagicLink\Actions\LoginAction;

class QuoteDueEmail extends Notification implements ShouldQueue
{
    use BellFirst, Queueable, SignsInByLink;

    /**
     * Create a new notification instance.
     */
    public function __construct(
        public object $project,
        public object $recipient,
        public string $message,
    ) {}

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        /*
         * The board, which is where the batch this is chasing can actually be quoted. This used to
         * be route('dashboard') back when that name redirected here; it is the material list upload
         * page now, and landing an "action this" link on a file picker would be a dead end.
         */
        $action = new LoginAction($this->recipient);
        $action->response(redirect()->route('dashboard'));
        $magicLinkUrl = $this->loginLinkFor($action);

        return (new MailMessage)
            ->subject('Procurement actions')
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
            /*
             * The day the steel is wanted, resolved rather than copied off the column: a project
             * created since the upload form started asking for a fabrication date instead carries
             * null there, and the bell renders this row's wording off this value for as long as the
             * row is unread. See Project::materialsRequiredOn.
             */
            'date_materials_required' => $this->project->materialsRequiredOn()?->toDateString(),
        ];
    }
}
