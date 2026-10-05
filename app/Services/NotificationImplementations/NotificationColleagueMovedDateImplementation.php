<?php

namespace App\Services\NotificationImplementations;

use App\Models\Batch;
use App\Models\Project;
use App\Models\User;
use App\Notifications\ColleagueMovedMaterialsDate;
use App\Sandbox\Sandbox;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Carbon;

/**
 * The A_COLLEAGUE_MOVED_THE_BATCH_DATE case - told by ProjectController::update at the moment it
 * happens.
 *
 * See ColleagueMovedMaterialsDate for why a date somebody moves on their own job is news to everyone
 * else on the batch, and ColleagueNotificationImplementation for the parts this shares with the two
 * notifications about a colleague quoting or ordering your materials.
 *
 * Deliberately not a ColleagueActionImplementation, which the other two extend. That base sends once
 * per project per batch, ever, because the things it reports happen to a batch once. A date can move
 * as often as a job slips, and only the first move would ever be reported.
 */
class NotificationColleagueMovedDateImplementation extends ColleagueNotificationImplementation
{
    public function getNotificationClass(): string
    {
        return 'ColleagueMovedMaterialsDate';
    }

    /**
     * Tell the manager of every other job on this batch what its steel is now wanted by.
     *
     * @param  \Illuminate\Support\Collection<int, Project>  $projectsOnBatch  as Batch::projectDates gives them
     */
    public function notifyBatchManagers(
        Batch $batch,
        $projectsOnBatch,
        User $actor,
        ?Carbon $requiredBy,
    ): void {
        /*
         * Test mode, not the real board - the same guard, for the same reason, as
         * ColleagueActionImplementation::notifyAffectedProjectManagers. Sandbox rows are scoped to
         * one user, so notifying a colleague about one would put a project in their bell that they
         * cannot open and did not know existed.
         */
        if (Sandbox::isActive()) {
            return;
        }

        foreach ($projectsOnBatch as $project) {
            $owner = $project->user;

            //Not the person who moved it, and not somebody whose account has gone
            if (! $owner || $owner->id === $actor->id) {
                continue;
            }

            if ($this->hasBeenToldOf($owner, $project->id, $batch->id, $requiredBy)) {
                continue;
            }

            $owner->notify(new ColleagueMovedMaterialsDate($project, $batch, $actor, $requiredBy));
        }
    }

    /**
     * Once per project per batch per date landed on.
     *
     * Not once ever: a job slips more than once, and each slip moves the day this batch's steel is
     * wanted. Not once per press either - a manager correcting a typo by moving a date out and
     * straight back lands on a day the batch has already been told about, and saying it twice would
     * teach people the bell repeats itself.
     */
    private function hasBeenToldOf(User $recipient, int $projectId, int $batchId, ?Carbon $requiredBy): bool
    {
        return $recipient->notifications()
            ->where('type', $this->notificationClassWithPath())
            ->where('notifiable_type', "App\Models\User")
            ->where('data->project_id', $projectId)
            ->where('data->batch_id', $batchId)
            ->where('data->required_by', $requiredBy?->toDateString())
            ->exists();
    }

    /**
     * The bell's wording, which has a date in it as well as the two names the base class renders.
     *
     * So notificationData is overridden below rather than this being made to carry three facts in
     * two arguments: the interface's message() is (string, string), and every other implementation
     * on the list reads those two the same way.
     */
    public function message(?string $string_1, ?string $string_2): string
    {
        $colleagueName = $string_1;
        $projectName = $string_2;

        return $colleagueName.' moved a fabrication date on the batch carrying "'.$projectName.'".';
    }

    /**
     * @return array<string, mixed>|null
     */
    public function notificationData(DatabaseNotification $notification): ?array
    {
        if (! $this->isCorrectClass($notification)) {
            return null;
        }

        $projectName = $notification->data['project_name'] ?? null;
        $colleagueName = $notification->data['colleague_name'] ?? null;

        if (! $projectName || ! $colleagueName) {
            return null;
        }

        $requiredBy = ($notification->data['required_by'] ?? null)
            ? Carbon::parse($notification->data['required_by'])->format('j M y')
            : null;

        /*
         * The new day, which is the whole point of the message - and the batch having no dated job
         * left on it said as that rather than as a blank where a date belongs.
         */
        $message = $this->message($colleagueName, $projectName)
            .($requiredBy
                ? ' Its materials are now needed by '.$requiredBy.'.'
                : ' That batch no longer has a date for its materials.');

        return [
            'id' => $notification->id,
            'message' => $message,
            'timestamp' => $notification->created_at->diffForHumans(),
            'trafficLights' => [
                'green' => ['Show me', '(Go to board)'],
                'yellow' => ['Ok', '(Dismiss)'],
                'red' => null,
            ],
        ];
    }
}
