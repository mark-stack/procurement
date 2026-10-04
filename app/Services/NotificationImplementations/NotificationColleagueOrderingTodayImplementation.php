<?php

namespace App\Services\NotificationImplementations;

use App\Models\Project;
use App\Models\User;
use App\Notifications\ColleagueOrderingBatchTodayEmail;
use App\Sandbox\Sandbox;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * "A colleague's deadline means your project is about to be quoted with theirs."
 *
 * The other half of App\Services\FabricationDeadlineQuoting. Starting quoting takes the whole Nesting
 * column into one batch owned by one person - the manager of the project whose fabrication date forced
 * it - so everybody else in that column loses Edit, Done and BOM upload on their own project the
 * moment it happens, and the suppliers and delivery dates become that colleague's call.
 *
 * What changed is the tense. This used to report a batch the schedule had already created; now the
 * schedule only asks, so this warns about something that has not happened yet - which is the more
 * useful of the two, because the last chance to say "wait, that BOM is not final" is before the button
 * is pressed rather than after. Once it is pressed, ColleagueQuotedYourMaterials reports it from
 * QuoteController, as it does for any other press.
 *
 * So it no longer extends ColleagueActionImplementation: there is no batch to key on or link to. The
 * notification class name is kept regardless - it is the `type` of every row already in somebody's
 * bell.
 */
class NotificationColleagueOrderingTodayImplementation extends ColleagueNotificationImplementation
{
    /**
     * How far back hasBeenNotified() looks. Minutes in test mode, as the hourly reminders do.
     */
    public string $subInterval;

    public function __construct()
    {
        $this->subInterval = config('env.test_mode') ? 'subMinutes' : 'subDays';
    }

    public function getNotificationClass(): string
    {
        return 'ColleagueOrderingBatchTodayEmail';
    }

    /**
     * Tell the owner of every other project in the column that it is about to be quoted without them.
     *
     * The trigger project's own manager is skipped: they are the one being asked to press the button,
     * and NotificationBatchReadyToQuoteImplementation has already said so. Anything else they own in
     * the column goes with their own project anyway.
     *
     * @param  Collection<int, Project>  $column
     */
    public function notifyColumnManagers(Collection $column, Project $trigger, User $triggerUser): void
    {
        /*
         * Test mode, not the real board - the same guard ColleagueActionImplementation carries.
         * FabricationDeadlineQuoting::warnAll() refuses to run in a sandbox at all, but this method is
         * reachable on its own, and a sandbox project landing in a colleague's bell is a project they
         * cannot open and did not know existed.
         */
        if (Sandbox::isActive()) {
            return;
        }

        foreach ($column as $project) {
            $owner = $project->user;

            //Not the person being asked to press it, and not a project nobody owns
            if (! $owner || $owner->id === $triggerUser->id) {
                continue;
            }

            if ($this->hasBeenNotified($owner, $project->id)) {
                continue;
            }

            //Yesterday's copy of the same warning, superseded
            $this->markPreviousAsRead($owner, $project);

            $owner->notify(new ColleagueOrderingBatchTodayEmail($project, $trigger, $triggerUser));
        }
    }

    /**
     * Once a day per project.
     *
     * The old answer was "once per project per batch", which worked because the batch existed by the
     * time this was sent. There is no batch now and nothing empties the column, so the warning is
     * still true an hour later - this is what keeps an hourly command to one a day while the column
     * waits. The same window the hourly quoting reminders use.
     */
    public function hasBeenNotified(object $recipient, int $uniqueModelId): bool
    {
        $subInterval = $this->subInterval;

        return $recipient->notifications()
            ->where('type', $this->notificationClassWithPath())
            ->where('notifiable_type', "App\Models\User")
            ->where('data->project_id', $uniqueModelId)
            ->whereBetween('created_at', [Carbon::now()->$subInterval(1), Carbon::now()])
            ->exists();
    }

    public function markPreviousAsRead(object $recipient, object $otherObject): void
    {
        $recipient->notifications()
            ->where('type', $this->notificationClassWithPath())
            ->where('notifiable_type', "App\Models\User")
            ->where('data->project_id', $otherObject->id)
            ->update(['read_at' => now()]);
    }

    public function message(string $string_1, string $string_2): string
    {
        $colleagueName = $string_1;
        $projectName = $string_2;

        return $colleagueName.' has a fabrication start date that means everything waiting to be'
            .' nested has to be quoted today, and that includes "'.$projectName.'". Once quoting'
            .' starts, this project\'s suppliers and delivery dates are set by that batch - so raise'
            .' anything that still needs changing on it now.';
    }
}
