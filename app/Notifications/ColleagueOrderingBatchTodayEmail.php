<?php

namespace App\Notifications;

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
 * Your project is in a column a colleague has to quote today.
 *
 * The other half of App\Services\FabricationDeadlineQuoting. Starting quoting takes the whole Nesting
 * column into one batch owned by one colleague - the manager of the project whose fabrication date
 * forced it - so everybody else in the column loses Edit, Archive and BOM upload on their own project
 * the moment it happens, and the suppliers and delivery dates become that colleague's call.
 *
 * It now warns before that rather than reporting it afterwards, because the schedule stopped pressing
 * the button: the press is somebody's decision again, and the last chance to say "wait, that BOM is
 * not final" is before it. ColleagueQuotedYourMaterials is what reports the press itself, from
 * QuoteController, as it does for any other.
 *
 * Mailed as well as belled, like the warning to the colleague being asked to press it. The owner of a
 * project about to be swept into somebody else's batch has less time to react than anybody, and no
 * reason to be looking at the board today.
 *
 * The class name is kept as it is: it is the `type` column of every row already in somebody's bell.
 */
class ColleagueOrderingBatchTodayEmail extends Notification implements ShouldQueue
{
    use Queueable, SignsInByLink;

    public function __construct(
        public Project $project,
        public Project $trigger,
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
        //The board, where the column is. There is no batch to deep link into until somebody presses
        $action = new LoginAction($notifiable);
        $action->response(redirect()->route('projects.index'));
        $magicLinkUrl = $this->loginLinkFor($action);

        /*
         * The wording lives on the implementation, which is where every other notification's does -
         * the bell renders from there, and two copies of a sentence is two chances to change one of
         * them.
         */
        $message = (new NotificationColleagueOrderingTodayImplementation)
            ->message($this->colleague->name, $this->project->name);

        return (new MailMessage)
            ->subject($this->colleague->name.' has to quote materials for your project today')
            ->line($message)
            ->line('Fabrication on "'.$this->trigger->name.'" is what forces it. Once '
                .$this->colleague->name.' starts quoting, the batch fixes this project\'s suppliers'
                .' and delivery dates and you can no longer edit it or upload to it - so anything'
                .' that still needs changing has to be raised with them today.')
            ->action('Open the board', $magicLinkUrl);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'project_id' => $this->project->id,
            'project_name' => $this->project->name,
            'colleague_name' => $this->colleague->name,
        ];
    }
}
