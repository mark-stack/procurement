<?php

namespace App\Notifications;

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
 * Your project's fabrication start date is close enough that its materials have to be quoted today.
 *
 * Sent by App\Services\FabricationDeadlineQuoting, to the manager of the project that triggered it.
 * It used to report a batch the schedule had already made on their behalf; it asks now, because
 * creating that batch is a purchasing decision and those are the user's. So it has to carry
 * everything the recipient needs to decide without first working out what this is about - which
 * project is forcing it, and when the shop starts cutting.
 *
 * The class name still says "ready to quote" rather than "please quote", and is kept that way
 * deliberately: it is the `type` column of every row already sitting in somebody's bell, and renaming
 * it would leave those rows rendered by nobody.
 *
 * Mail unconditionally, not behind config('notifications.mail_reminders') like the deadline
 * reminders. Those chase something the board already shows and are safe to leave in the bell; the
 * whole reason this exists is that nobody is looking at the Nesting column at 6am, and a red dot
 * seen the day after the steel should have been ordered is no use at all.
 */
class BatchReadyToQuoteEmail extends Notification implements ShouldQueue
{
    use Queueable, SignsInByLink;

    public function __construct(
        public Project $project,
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
         * The board, which is where "Start quoting" is.
         *
         * It used to deep link into Quotes / Orders for the batch, because there was a batch by the
         * time this went out. There is not one now - the whole point of the change is that nobody has
         * pressed anything - so the one tap this can save is getting them to the column.
         */
        $action = new LoginAction($this->recipient);
        $action->response(redirect()->route('dashboard'));
        $magicLinkUrl = $this->loginLinkFor($action);

        return (new MailMessage)
            ->subject('These materials need quoting today')
            ->line($this->message)
            ->line('Pressing "Start quoting" on the Nesting column takes everything waiting in it into'
                .' one batch and opens Quotes / Orders. Each supplier there has an "Email tables"'
                .' button beside it, which opens an email already written with that supplier\'s'
                .' material order list in it - one per supplier category on the batch.')
            ->action('Open the board', $magicLinkUrl);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            /*
             * project_id is not decoration: NotificationService::clearProjectNotifications, the
             * project observer and FabricationDeadlineQuoting::clearWarningsNoLongerDue all key off
             * it, so archiving or quoting the project this names takes it out of the bell.
             */
            'project_id' => $this->project->id,
            'project_name' => $this->project->name,
            'date_fabrication_begins' => $this->project->date_fabrication_begins
                ? Carbon::parse($this->project->date_fabrication_begins)->toDateString()
                : null,
        ];
    }
}
