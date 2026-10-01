<?php

namespace App\Services;

use App\Actions\Batch\StartQuoting;
use App\Formatters\NestingFormatter;
use App\Models\Batch;
use App\Models\Business;
use App\Models\Project;
use App\Models\User;
use App\PrerequisiteConditions\PrerequisiteConditions;
use App\Sandbox\Sandbox;
use App\Services\NotificationImplementations\NotificationBatchReadyToQuoteImplementation;
use App\Services\NotificationImplementations\NotificationColleagueOrderingTodayImplementation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Press "Start quoting" for a business whose shop is about to start cutting.
 *
 * The Nesting column is a waiting room, and waiting there is the whole point of it: the longer
 * material sits unbatched, the more of it accumulates, and the better the nest and the bulk price get
 * (KanbanMinimalCard says as much - "wait N days to allow for more possible materials"). But nobody
 * is watching that column at 6am, and a project whose fabrication starts on Monday cannot be waiting
 * in it on Friday: materials still have to be quoted, ordered and delivered before the first cut.
 *
 * So when a project in that column gets within DAYS_BEFORE_FABRICATION of its fabrication start date,
 * this stops the waiting - it takes the whole column into one batch, exactly as the button does, and
 * tells the people whose work just moved.
 *
 * The whole column, not just the urgent project, because that is what the button does and what
 * batching is for. A batch is one nest, one set of quotes and one delivery; pulling one project out
 * of the queue on its own would buy its steel at single-project prices and leave its offcuts
 * stranded. The projects behind it were going to be quoted within days anyway.
 *
 * Idempotent by construction. Once the column is emptied into a batch there are no unbatched pieces
 * left, so the next run finds nothing - there is no "already swept" flag to keep, and a batch that is
 * broken open to re-nest is correctly swept again if its deadline is still close.
 */
class FabricationDeadlineQuoting
{
    /**
     * How close the first cut has to be before the waiting stops.
     *
     * Five days is the figure asked for, and it sits just outside the critical path the rest of the
     * board is measured against - Project::criticalPathDays() is quoting time plus delivery time,
     * two plus four - so a batch swept today has a day in hand before the deadline reminders would
     * start calling it late.
     */
    public const int DAYS_BEFORE_FABRICATION = 5;

    /**
     * @return array<int, Batch> the batches created, for the command to report
     */
    public function sweep(): array
    {
        $created = [];

        /*
         * Live rows only. The sandbox global scope resolves to "sandbox_user_id is null" when no test
         * mode is active, and nothing here is running inside a request - but say so, because this
         * writes batches and emails colleagues, and doing either with somebody's test data would be
         * the hardest kind of mistake to explain.
         */
        if (Sandbox::isActive()) {
            return $created;
        }

        foreach (Business::query()->get() as $business) {
            try {
                $batch = $this->sweepBusiness($business);
            } catch (Throwable $e) {
                /*
                 * One business's bad data does not stop the rest. A nest that throws here would
                 * otherwise take every business behind it in the list down with it, silently, on a
                 * schedule nobody is watching.
                 */
                Log::error('Fabrication deadline sweep failed', [
                    'business_id' => $business->id,
                    'exception' => $e,
                ]);

                continue;
            }

            if ($batch) {
                $created[] = $batch;
            }
        }

        return $created;
    }

    public function sweepBusiness(Business $business): ?Batch
    {
        /*
         * A read-only account is read-only here too. Starting quoting is a write the user could not
         * make themselves (BillingWriteAccessMiddleware), and doing it for them would be the one way
         * a lapsed trial still gets to spend money.
         */
        if (! $business->allowsWrites()) {
            return null;
        }

        $piecesReadyForBatching = (new NestingFormatter)->piecesReadyForBatching($business);

        if ($piecesReadyForBatching->isEmpty()) {
            return null;
        }

        //Read before the pieces are claimed, because starting quoting empties this list
        $projectsReadyForBatching = $business->projectsReadyForBatching($piecesReadyForBatching);

        $trigger = $this->triggerProject($projectsReadyForBatching);

        if (! $trigger) {
            return null;
        }

        $triggerUser = $trigger->user;

        if (! $triggerUser) {
            return null;
        }

        /*
         * The same gate the button is behind, asked as the trigger user. It holds by construction -
         * they own a project in this column, the pieces are unbatched, nothing here is archived - but
         * asking it is what keeps the two routes to a batch answering the same question. A gate only
         * one of them consults is a gate.
         */
        $allowed = (new PrerequisiteConditions)->startQuoting(
            $triggerUser,
            $projectsReadyForBatching,
            $piecesReadyForBatching,
        );

        if (! $allowed) {
            return null;
        }

        $batch = StartQuoting::run($triggerUser, $business, $piecesReadyForBatching);

        /*
         * Null means a colleague pressed the button in the seconds this was running. Their batch is
         * the batch, and they have already been told what they did, so there is nothing to report.
         */
        if (! $batch) {
            return null;
        }

        $this->notify($batch, $trigger, $triggerUser);

        return $batch;
    }

    /**
     * The project whose fabrication date forces the batch.
     *
     * The most urgent one in the column: the earliest fabrication start date inside the window. Where
     * several share that date the most recently added wins, which is the rule asked for and the one
     * that reads right - the latest arrival is the project whose BOM the manager was working on, so
     * they are the colleague with the job in front of them today.
     *
     * A date already in the past counts. A project whose fabrication was due to start last week is
     * more urgent than one starting on Friday, not less, and skipping it would leave it waiting in
     * the Nesting column for good.
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
     * Tell the trigger's manager what they have to quote, and everybody else who is quoting it.
     */
    private function notify(Batch $batch, Project $trigger, User $triggerUser): void
    {
        (new NotificationBatchReadyToQuoteImplementation)->notifyTrigger($trigger, $batch, $triggerUser);

        /*
         * Everybody else on the batch. notifyAffectedProjectManagers skips the actor's own projects
         * and any owner already told about this batch, so the trigger user does not get both halves.
         */
        (new NotificationColleagueOrderingTodayImplementation)
            ->notifyAffectedProjectManagers($batch, $triggerUser);
    }
}
