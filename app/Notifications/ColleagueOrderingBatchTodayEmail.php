<?php

namespace App\Notifications;

use App\Models\Batch;
use App\Models\Project;
use App\Models\User;
use App\Notifications\Concerns\SignsInByLink;
use App\Services\NotificationImplementations\NotificationColleagueOrderingTodayImplementation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use MagicLink\Actions\LoginAction;

/**
 * Your project is in a batch somebody else is ordering today.
 *
 * The other half of App\Services\FabricationDeadlineQuoting. The sweep takes the whole Nesting column
 * into one batch owned by one colleague - the manager of the project whose fabrication date forced
 * it - so everybody else on that batch loses Edit, Archive and BOM upload on their own project the
 * moment its pieces are nested, and the suppliers and delivery dates become that colleague's call.
 *
 * ColleagueQuotedYourMaterials says the same thing about the button being pressed, and is bell only
 * because the press is somebody's deliberate decision that the owner can go and talk to them about.
 * This one is mailed as well, because nobody decided anything: a colleague's deadline moved the
 * recipient's materials, today, and the last chance to say "wait, that BOM is not final" is before
 * the order goes out.
 */
class ColleagueOrderingBatchTodayEmail extends Notification implements ShouldQueue
{
    use Queueable, SignsInByLink;

    public function __construct(
        public Project $project,
        public Batch $batch,
        public User $colleague,
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
        //Straight into Quotes / Orders for the batch the button names, not just the board
        $action = new LoginAction($notifiable);
        $action->response(redirect()->route('projects.index', ['quotes' => $this->batch->id]));
        $magicLinkUrl = $this->loginLinkFor($action);

        /*
         * The wording lives on the implementation, which is where every other notification's does -
         * the bell renders from there, and two copies of a sentence is two chances to change one of
         * them.
         */
        $message = (new NotificationColleagueOrderingTodayImplementation)
            ->message($this->colleague->name, $this->project->name);

        return (new MailMessage)
            ->subject($this->colleague->name.' is ordering materials for your project today')
            ->line($message)
            ->line('Quotes / Orders on this batch has the suppliers and, beside each one, an "Email'
                .' tables" button that opens an email already written with that supplier\'s material'
                .' order list. '.$this->colleague->name.' is placing the orders, so anything that still'
                .' needs changing on this project has to be raised with them today.')
            ->action('Open Quotes for this batch', $magicLinkUrl);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'project_id' => $this->project->id,
            'project_name' => $this->project->name,
            'batch_id' => $this->batch->id,
            'colleague_name' => $this->colleague->name,
        ];
    }
}
