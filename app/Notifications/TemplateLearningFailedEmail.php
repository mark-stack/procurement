<?php

namespace App\Notifications;

use App\Models\TemplateLearningAttempt;
use App\Services\TemplateTestChecklist;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * A customer uploaded a format we could not read, and could not teach ourselves to read.
 *
 * This is the one piece of the old onboarding that still needs a person, and it is now the only
 * thing that puts a customer's spreadsheet in front of us - so it has to arrive by itself. It used
 * to be a sentence shown to the customer asking them to email the file to support, which meant the
 * failures we heard about were the ones a customer bothered to report.
 *
 * It carries no file. The sample is on the server and reachable from the admin templates screen,
 * which checks who is asking; a customer's bill of materials does not go out as an attachment to
 * every admin's inbox.
 */
class TemplateLearningFailedEmail extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public TemplateLearningAttempt $attempt) {}

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
        $business = $this->attempt->business;

        $message = (new MailMessage)
            ->subject('A customer upload could not be read: '.$business->domain)
            ->line(sprintf(
                '%s uploaded "%s" and no import template matched it. Writing one automatically did not get through its test, so it needs a person.',
                $business->domain,
                $this->attempt->file_name,
            ));

        if (filled($this->attempt->headline)) {
            $message->line('What stopped it: '.$this->attempt->headline);
        }

        /*
         * Every failed check, not just the first. The first one explains the rest often enough to be
         * the headline above, and not often enough to be the whole email - "the table was found but
         * none of the rows are in the catalogue" and "no table was found" are different jobs.
         */
        foreach ($this->failedChecks() as $detail) {
            $message->line('- '.$detail);
        }

        return $message
            ->action('Open the templates screen', route('admin.businesses.templates.index', $business))
            ->line('The file they uploaded is on that screen, next to the proposal that failed. Opening it fills the form in with what was proposed, so it can be corrected and tested rather than started from nothing.');
    }

    /**
     * @return list<string>
     */
    private function failedChecks(): array
    {
        $failed = [];

        foreach ($this->attempt->checks ?? [] as $check) {
            if ($check['status'] === TemplateTestChecklist::FAIL) {
                $failed[] = $check['label'].': '.$check['detail'];
            }
        }

        return $failed;
    }

    /*
     * No toArray(): mail is the only channel - see via().
     */
}
