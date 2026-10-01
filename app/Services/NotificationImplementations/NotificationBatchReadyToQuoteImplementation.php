<?php

namespace App\Services\NotificationImplementations;

use App\Models\Batch;
use App\Models\Project;
use App\Models\User;
use App\Notifications\BatchReadyToQuoteEmail;
use App\Services\Interfaces\NotificationInterface;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Notifications\DatabaseNotification;

/**
 * "Your fabrication date is close, so this batch has been moved to Quoting."
 *
 * Addressed to the manager of the project that triggered the move, and only to them -
 * NotificationColleagueOrderingTodayImplementation is what the other project managers on the batch
 * get. See App\Services\FabricationDeadlineQuoting for which project triggers it and why.
 *
 * hourlyCheck() is empty on purpose, the same way the colleague notifications' is. The moment being
 * reported is a batch being created, and the service that creates it says so at the time; there is no
 * column that reads "this batch was auto-quoted and nobody has been told". The sweep itself runs off
 * its own scheduled command rather than the hourly notifications job, because it writes batches - a
 * notification pass that can also nest a business's steel is not a notification pass.
 */
class NotificationBatchReadyToQuoteImplementation implements NotificationInterface
{
    public function hourlyCheck(): void
    {
        //See the class docblock - the sweep is App\Services\FabricationDeadlineQuoting
    }

    /**
     * Tell the project manager whose fabrication date forced this batch.
     */
    public function notifyTrigger(Project $project, Batch $batch, User $recipient): void
    {
        if ($this->hasBeenNotifiedAbout($recipient, $project->id, $batch->id)) {
            return;
        }

        $recipient->notify(new BatchReadyToQuoteEmail(
            $project,
            $batch,
            $recipient,
            $this->message($this->fabricationDate($project), $project->name),
        ));
    }

    /**
     * Once per project per batch. The sweep is idempotent on its own - once the Nesting column is
     * emptied there is nothing left to batch - but a batch that is broken open and re-made would
     * otherwise chase the same person about the same project twice.
     */
    protected function hasBeenNotifiedAbout(User $recipient, int $projectId, int $batchId): bool
    {
        return $recipient->notifications()
            ->where('type', $this->notificationClassWithPath())
            ->where('notifiable_type', "App\Models\User")
            ->where('data->project_id', $projectId)
            ->where('data->batch_id', $batchId)
            ->exists();
    }

    public function hasBeenNotified(object $recipient, int $uniqueModelId): bool
    {
        /*
         * The interface's single-id version cannot express "this project on this batch". Answering by
         * project alone is the safe reading of it: it never sends a second time.
         */
        return $recipient->notifications()
            ->where('type', $this->notificationClassWithPath())
            ->where('notifiable_type', "App\Models\User")
            ->where('data->project_id', $uniqueModelId)
            ->exists();
    }

    public function sendNotification(object $recipient, object $otherObject): void
    {
        /*
         * Not used. This needs three objects - project, batch and recipient - so notifyTrigger() is
         * the way in.
         */
    }

    public function checkProjectChanges(Project $project): void
    {
        /*
         * Nothing an edit can do makes this untrue: it reports something that happened. Pushing the
         * fabrication date out does not un-nest the batch. Archiving the project does clear it, via
         * NotificationService::clearProjectNotifications and the project_id this carries.
         */
    }

    public function getNotificationClass(): string
    {
        return 'BatchReadyToQuoteEmail';
    }

    public function markPreviousAsRead(object $recipient, object $otherObject): void
    {
        //One per project per batch, so there is never a previous one to supersede
    }

    public function trafficLight(DatabaseNotification $notification, string $status): ?RedirectResponse
    {
        if (! $this->isCorrectClass($notification)) {
            return null;
        }

        return match ($status) {
            'GREEN' => $this->markGreen($notification),
            'RED' => $this->markRed($notification),
            default => $this->markYellow($notification),
        };
    }

    public function markGreen(DatabaseNotification $notification): RedirectResponse
    {
        //"Quote it" - the board is where the batch and its quotes are
        $notification->markAsRead();

        return redirect()->route('projects.index');
    }

    public function markRed(DatabaseNotification $notification): RedirectResponse
    {
        //Nothing to refuse. The batch exists; this only reports it
        return $this->markYellow($notification);
    }

    public function markYellow(DatabaseNotification $notification): RedirectResponse
    {
        $notification->markAsRead();

        return back();
    }

    public function isCorrectClass(DatabaseNotification $notification): bool
    {
        return $notification->type === $this->notificationClassWithPath();
    }

    public function notificationData(DatabaseNotification $notification): ?array
    {
        if (! $this->isCorrectClass($notification)) {
            return null;
        }

        $projectName = $notification->data['project_name'] ?? null;

        if (! $projectName) {
            return null;
        }

        $fabricationDate = $notification->data['date_fabrication_begins'] ?? null;

        return [
            'id' => $notification->id,
            'message' => $this->message(
                $fabricationDate ? Carbon::parse($fabricationDate)->format('j M y') : 'soon',
                $projectName,
            ),
            'timestamp' => $notification->created_at->diffForHumans(),
            'trafficLights' => [
                'green' => ['Quote it', '(Go to board)'],
                'yellow' => ['Ok', '(Dismiss)'],
                'red' => null,
            ],
        ];
    }

    public function message(string $string_1, string $string_2): string
    {
        $fabricationDate = $string_1;
        $projectName = $string_2;

        return 'Fabrication on "'.$projectName.'" begins '.$fabricationDate
            .', so its materials have been moved into Quoting with everything else that was waiting to'
            .' be nested. This batch needs to be quoted today.';
    }

    protected function notificationClassWithPath(): string
    {
        return "App\Notifications\\".$this->getNotificationClass();
    }

    private function fabricationDate(Project $project): string
    {
        return $project->date_fabrication_begins
            ? Carbon::parse($project->date_fabrication_begins)->format('j M y')
            : 'soon';
    }
}
