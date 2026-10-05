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
         * Signed in, and on the page the button is on. Nothing more.
         *
         * It made the press itself for a while - the link landing on a page that posted itself, so
         * that the batch was nested and its material order list open by the time the reader looked.
         * That page was a flash of a screen on the way to this one, and it bought less than it cost:
         * the press is one button away on the card they land on, the bell's green action still makes
         * it in one, and a batch is a purchase. Doing it from a link in an email, where a mistimed
         * tap and a mail filter look alike, is not where that should happen quietly.
         *
         * So the url is the whole of it, which is also all MagicLink keeps: it serialises the
         * response it is handed and rebuilds it from the target url and status code alone
         * (ResponseAction::formattedResponse), so anything carried on this redirect would be dropped
         * on the way. A flash set here is lost twice over - redirect()->with() flashes when the
         * object is built, which for this notification is inside a queued job with no session to
         * flash into.
         */
        $action = new LoginAction($this->recipient);
        $action->response(redirect()->route('dashboard'));
        $magicLinkUrl = $this->loginLinkFor($action);

        return (new MailMessage)
            ->subject('These materials need quoting today')
            ->line($this->message)
            ->action('Start quoting', $magicLinkUrl);
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
             * it, so marking the project done or quoting it this names takes it out of the bell.
             */
            'project_id' => $this->project->id,
            'project_name' => $this->project->name,
            'date_fabrication_begins' => $this->project->date_fabrication_begins
                ? Carbon::parse($this->project->date_fabrication_begins)->toDateString()
                : null,
        ];
    }
}
