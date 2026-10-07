<?php

namespace App\Notifications;

use App\Models\Template;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * A customer's upload wrote its own import template, and it is live.
 *
 * The failure is emailed because it needs a person - see TemplateLearningFailedEmail. This is the
 * other one, and it went unannounced: a template written by a model from one spreadsheet went live
 * on the strength of its own test and started deciding how that business's bills of materials are
 * read, with the only trace a badge on one business's templates screen. The customer was told its
 * name; nobody here was told anything.
 *
 * It is not a problem to fix, which is why the wording does not read like one. It is a reading list:
 * these are the templates in production that nobody has looked at, and knowing one exists is what
 * makes looking at it possible.
 */
class TemplateLearnedEmail extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Template $template) {}

    /**
     * Mail only, for the reason given on NewUserEmail::via(): the bell renders only the types
     * NotificationService::implementations() lists, so a row for this one would be an unread
     * notification with no wording, no button and no way to clear it.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $business = $this->template->business;

        return (new MailMessage)
            ->subject('An import template wrote itself: '.$business->domain)
            ->line(sprintf(
                '%s uploaded a spreadsheet format no template matched, so one was written for it automatically and saved as "%s". It passed every check that stops a template being created by hand, and it is live - uploads of that format now import without anybody here doing anything.',
                $business->domain,
                $this->template->name,
            ))
            ->line('Nobody has read it. That is worth half a minute: it was written from one spreadsheet by a model, and it now decides which column of that customer\'s bills of materials is the length and which is the quantity.')
            ->action('Open the templates screen', route('admin.businesses.templates.index', $business))
            ->line('Opening it shows the cells it recorded next to a picture of the sheet it read them from. Marking it reviewed takes it off the unread list.');
    }

    /*
     * No toArray(): mail is the only channel - see via().
     */
}
