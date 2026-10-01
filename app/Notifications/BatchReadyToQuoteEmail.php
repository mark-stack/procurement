<?php

namespace App\Notifications;

use App\Models\Batch;
use App\Models\Project;
use App\Models\User;
use App\Notifications\Concerns\SignsInByLink;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use MagicLink\Actions\LoginAction;

/**
 * Your project's fabrication start date got close enough that the batch was moved to Quoting for you.
 *
 * Sent by App\Services\FabricationDeadlineQuoting, to the manager of the project that triggered it.
 * Nobody pressed anything: the sweep took every project in the Nesting column into one batch because
 * waiting for more material to accumulate would have cost this project its critical path. So this has
 * to carry everything the recipient needs to act on it without first working out what happened -
 * which project forced it, when the shop starts cutting, and the material tables themselves.
 *
 * Mail unconditionally, not behind config('notifications.mail_reminders') like the deadline
 * reminders. Those chase something the board already shows and are safe to leave in the bell; this
 * reports an action the application took on the user's behalf while they were not looking, and the
 * steel has to be ordered today. A red dot they might see tomorrow is not good enough for that.
 */
class BatchReadyToQuoteEmail extends Notification implements ShouldQueue
{
    use Queueable, SignsInByLink;

    public function __construct(
        public Project $project,
        public Batch $batch,
        public User $recipient,
        public string $message,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        /*
         * Straight into Quotes / Orders for this batch, not just the board.
         *
         * The material tables used to be printed in the email itself. They are not, any more: a
         * supplier group's list is a dozen-odd lines of section and length that the mail client
         * reflows into one grey paragraph, and it is unusable in that form - it cannot be sent to a
         * merchant, and re-typing it from an email is exactly the work this application exists to
         * remove. The modal already generates a ready-addressed draft per supplier, so the email's
         * job is to say where that is and get the recipient there in one tap.
         */
        $action = new LoginAction($this->recipient);
        $action->response(redirect()->route('projects.index', ['quotes' => $this->batch->id]));
        $magicLinkUrl = $this->loginLinkFor($action);

        return (new MailMessage)
            ->subject('This batch needs quoting today')
            ->line($this->message)
            ->line('Open Quotes / Orders on this batch to send it out. Each supplier has an "Email'
                .' tables" button beside it, which opens an email already written with that'
                .' supplier\'s material order list in it - one per supplier category on the batch.')
            ->action('Open Quotes for this batch', $magicLinkUrl);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            /*
             * project_id is not decoration: NotificationService::clearProjectNotifications and the
             * project observer both key off it, so archiving the project that triggered this takes it
             * out of the bell with everything else about it.
             */
            'project_id' => $this->project->id,
            'project_name' => $this->project->name,
            'batch_id' => $this->batch->id,
            'date_fabrication_begins' => $this->project->date_fabrication_begins
                ? Carbon::parse($this->project->date_fabrication_begins)->toDateString()
                : null,
        ];
    }
}
