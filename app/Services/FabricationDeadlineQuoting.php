<?php

namespace App\Services;

use App\Formatters\NestingFormatter;
use App\Models\Business;
use App\Models\Project;
use App\PrerequisiteConditions\PrerequisiteConditions;
use App\Sandbox\Sandbox;
use App\Services\NotificationImplementations\NotificationBatchReadyToQuoteImplementation;
use App\Services\NotificationImplementations\NotificationColleagueOrderingTodayImplementation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Say when the Nesting column has to be quoted, because the shop is about to start cutting.
 *
 * The Nesting column is a waiting room, and waiting there is the whole point of it: the longer
 * material sits unbatched, the more of it accumulates, and the better the nest and the bulk price get
 * (KanbanMinimalCard says as much - "wait N days to allow for more possible materials"). But nobody
 * is watching that column at 6am, and a project whose fabrication starts on Monday cannot be waiting
 * in it on Friday: materials still have to be quoted, ordered and delivered before the first cut.
 *
 * So when a project in that column gets within DAYS_BEFORE_FABRICATION of its fabrication start date,
 * this tells the people whose work is waiting - and stops there.
 *
 * It used to press "Start quoting" itself. It does not any more, and that is the point of this class
 * as it stands: starting quoting creates a batch, saves a nest, consumes offcut inventory and takes
 * Edit, Archive and BOM upload off every project it sweeps in. That is a purchasing decision, and a
 * purchasing decision is the user's. The gates below are kept anyway - a warning nobody can act on is
 * noise, so this only speaks up when pressing the button would actually work.
 *
 * Not idempotent by construction any more, either, which is the other thing the motion used to buy:
 * an emptied column had nothing left to batch, so a second run did nothing. Nothing empties now, so
 * the hourly run would say the same thing every hour - the once-a-day window on each notification is
 * what holds that back. See NotificationBatchReadyToQuoteImplementation::hasBeenNotified.
 */
class FabricationDeadlineQuoting
{
    /**
     * How close the first cut has to be before the waiting stops.
     *
     * Five days is the figure asked for. It used to sit just outside the critical path the rest of
     * the board is measured against - Project::criticalPathDays() was quoting time plus delivery
     * time, a fixed two plus four - so a column quoted today had a day in hand before the deadline
     * reminders would start calling it late.
     *
     * Those two are the business's own figures now, set in /profile under "Business preferences",
     * and they default to two plus three. So this constant is no longer reliably outside the critical
     * path: on the defaults the two are the same five days, and a business that sets longer lead
     * times is warned by this sweep after its own critical path has already passed. Left as a
     * constant deliberately - it is the day the *column* stops waiting, which is a decision about
     * batching rather than about any one project's deadline - but it is the next thing to make a
     * preference if a business asks for it.
     */
    public const int DAYS_BEFORE_FABRICATION = 5;

    /**
     * @return array<int, Project> the trigger projects warned about, for the command to report
     */
    public function warnAll(): array
    {
        $warned = [];

        /*
         * Live rows only. The sandbox global scope resolves to "sandbox_user_id is null" when no test
         * mode is active, and nothing here is running inside a request - but say so, because this
         * emails colleagues, and doing that about somebody's test data would be the hardest kind of
         * mistake to explain.
         */
        if (Sandbox::isActive()) {
            return $warned;
        }

        $triggerProjectIds = [];
        $columnProjectIds = [];
        $failed = false;

        foreach (Business::query()->get() as $business) {
            try {
                $column = $this->projectsToWarn($business);

                if ($column->isEmpty()) {
                    continue;
                }

                /** @var Project $trigger */
                $trigger = $column->first();

                $triggerProjectIds[] = $trigger->id;
                $columnProjectIds = array_merge(
                    $columnProjectIds,
                    $column->skip(1)->pluck('id')->all(),
                );

                $this->warn($trigger, $column);

                $warned[] = $trigger;
            } catch (Throwable $e) {
                /*
                 * One business's bad data does not stop the rest. A price book match that throws here
                 * would otherwise take every business behind it in the list down with it, silently, on
                 * a schedule nobody is watching.
                 */
                $failed = true;

                Log::error('Fabrication deadline warning failed', [
                    'business_id' => $business->id,
                    'exception' => $e,
                ]);

                continue;
            }
        }

        /*
         * Only when every business was actually read. The clearing below works off "these are the
         * projects still due", so a business skipped by the catch above would have its live warnings
         * marked read for no better reason than that its own data is broken.
         */
        if (! $failed) {
            $this->clearWarningsNoLongerDue($triggerProjectIds, $columnProjectIds);
        }

        return $warned;
    }

    /**
     * Warn one business, and say which project forced it.
     */
    public function warnBusiness(Business $business): ?Project
    {
        $column = $this->projectsToWarn($business);

        if ($column->isEmpty()) {
            return null;
        }

        /** @var Project $trigger */
        $trigger = $column->first();

        $this->warn($trigger, $column);

        return $trigger;
    }

    /**
     * The Nesting column that has run out of time, trigger project first.
     *
     * Empty when there is nothing to say: nothing waiting, no fabrication date close enough, or a
     * column nobody could start quoting anyway. Separate from warnBusiness() because warnAll() needs
     * the answer before it sends anything - the projects it names are what stops yesterday's warning
     * being marked read while it is still true.
     *
     * @return Collection<int, Project>
     */
    public function projectsToWarn(Business $business): Collection
    {
        /*
         * A read-only account is read-only here too. Starting quoting is a write the user cannot make
         * (BillingWriteAccessMiddleware), so chasing them to make it would be telling them to press a
         * button that answers 403. Their trial lapsing is SendTrialReminders' news to break, not this
         * one's.
         */
        if (! $business->allowsWrites()) {
            return collect();
        }

        $piecesReadyForBatching = (new NestingFormatter)->piecesReadyForBatching($business);

        if ($piecesReadyForBatching->isEmpty()) {
            return collect();
        }

        $projectsReadyForBatching = $business->projectsReadyForBatching($piecesReadyForBatching);

        $trigger = $this->triggerProject($projectsReadyForBatching);

        if (! $trigger) {
            return collect();
        }

        $triggerUser = $trigger->user;

        if (! $triggerUser) {
            return collect();
        }

        /*
         * The same gate the button is behind, asked as the trigger user. It holds by construction -
         * they own a project in this column, the pieces are unbatched, nothing here is archived - but
         * asking it is what keeps the warning honest: it is addressed to the person who has to press
         * the button, so a column they could not press it on is a column to say nothing about.
         */
        $allowed = (new PrerequisiteConditions)->startQuoting(
            $triggerUser,
            $projectsReadyForBatching,
            $piecesReadyForBatching,
        );

        if (! $allowed) {
            return collect();
        }

        //Trigger first. It is the project whose date forces the quote, and everything below reads it
        //off the front rather than working it out again
        return collect([$trigger])
            ->concat($projectsReadyForBatching->reject(fn (Project $project) => $project->id === $trigger->id))
            ->values();
    }

    /**
     * The project whose fabrication date forces the quote.
     *
     * The most urgent one in the column: the earliest fabrication start date inside the window. Where
     * several share that date the most recently added wins, which is the rule asked for and the one
     * that reads right - the latest arrival is the project whose BOM the manager was working on, so
     * they are the colleague with the job in front of them today.
     *
     * A date already in the past counts. A project whose fabrication was due to start last week is
     * more urgent than one starting on Friday, not less, and skipping it would leave it waiting in
     * the Nesting column with nobody ever told.
     *
     * @param  Collection<int, Project>  $projects
     */
    public function triggerProject(Collection $projects): ?Project
    {
        $deadline = Carbon::now()->addDays(self::DAYS_BEFORE_FABRICATION)->endOfDay();

        return $projects
            ->filter(fn (Project $project) => $project->date_fabrication_begins !== null
                && Carbon::parse($project->date_fabrication_begins)->lessThanOrEqualTo($deadline))
            ->sort(function (Project $a, Project $b) {
                //Earliest fabrication date first - the one with the least time left
                $byDate = Carbon::parse($a->date_fabrication_begins)
                    <=> Carbon::parse($b->date_fabrication_begins);

                if ($byDate !== 0) {
                    return $byDate;
                }

                //Then the most recently added of those sharing the date, newest first
                $byCreated = $b->created_at <=> $a->created_at;

                //Two projects created in the same second would otherwise order arbitrarily
                return $byCreated !== 0 ? $byCreated : $b->id <=> $a->id;
            })
            ->first();
    }

    /**
     * Tell the trigger's manager what they have to quote, and everybody else whose work is in with it.
     *
     * @param  Collection<int, Project>  $column
     */
    private function warn(Project $trigger, Collection $column): void
    {
        $triggerUser = $trigger->user;

        //Checked in projectsToWarn, which is the only way into here - said again for the type
        if (! $triggerUser) {
            return;
        }

        (new NotificationBatchReadyToQuoteImplementation)->notifyTrigger($trigger, $triggerUser);

        /*
         * Everybody else in the column. notifyColumnManagers skips the trigger user's own projects,
         * so the person being asked to press the button does not also get told that somebody will be
         * pressing it.
         */
        (new NotificationColleagueOrderingTodayImplementation)
            ->notifyColumnManagers($column, $trigger, $triggerUser);
    }

    /**
     * Mark read the warnings about columns that have since been quoted.
     *
     * The whole point of a warning rather than an action is that somebody goes and presses the
     * button - and the moment they do, "these materials are still waiting in Nesting" is false. Left
     * alone it would sit unread in the bell underneath the notification saying the batch was quoted.
     *
     * The same mechanism the hourly deadline reminders use, for the same reason. It is driven off the
     * projects still due rather than off the press, because a column leaves the window in more ways
     * than one: quoted, archived, or a fabrication date moved out.
     *
     * @param  array<int, int>  $triggerProjectIds
     * @param  array<int, int>  $columnProjectIds
     */
    private function clearWarningsNoLongerDue(array $triggerProjectIds, array $columnProjectIds): void
    {
        $notificationService = new NotificationService;

        $notificationService->clearStaleProjectReminders(
            (new NotificationBatchReadyToQuoteImplementation)->getNotificationClass(),
            $triggerProjectIds,
        );

        $notificationService->clearStaleProjectReminders(
            (new NotificationColleagueOrderingTodayImplementation)->getNotificationClass(),
            $columnProjectIds,
        );
    }
}
