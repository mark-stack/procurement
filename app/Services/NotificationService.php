<?php

namespace App\Services;

use App\Models\Project;
use App\Services\Interfaces\NotificationInterface;
use App\Services\NotificationImplementations\NotificationColleagueOrderedImplementation;
use App\Services\NotificationImplementations\NotificationColleagueQuotedImplementation;
use App\Services\NotificationImplementations\NotificationMaterialsDateImplementation;
use App\Services\NotificationImplementations\NotificationNewColleagueImplementation;
use App\Services\NotificationImplementations\NotificationOffcutCleanoutImplementation;
use App\Services\NotificationImplementations\NotificationProjectAwardedImplementation;
use App\Services\NotificationImplementations\NotificationQuotingOrderingDueImplementation;
use App\Services\NotificationImplementations\NotificationQuotingOrderingOverDueImplementation;
use Illuminate\Notifications\DatabaseNotification;

class NotificationService
{
    /**
     * Every notification type the bell knows how to render and act on.
     *
     * A list, not a scan of the directory. The scan read every file in
     * app/Services/NotificationImplementations and excluded one class by name -
     * "ProductBaseImplementation" - which does not exist and never has, so membership was really
     * "the file is on disk". Being a list is what lets a type be readable without being sendable
     * (see hourlyImplementations) and lets ColleagueActionImplementation exist as a shared base
     * without the scan trying to run it.
     *
     * It is also what a notification type costs: a type that is not listed is not rendered, so its
     * rows sit unread and invisible. That is why the bell counts what this produces rather than
     * $user->unreadNotifications - see getUnreadNotifications().
     *
     * @return array<int, NotificationInterface>
     */
    public function implementations(): array
    {
        return [
            //Hourly deadline chasing, addressed to the project's own manager
            new NotificationMaterialsDateImplementation,
            new NotificationQuotingOrderingDueImplementation,
            new NotificationQuotingOrderingOverDueImplementation,

            /*
             * Event-driven, and addressed to a colleague. These are the multi-staff half: the two
             * actions that deliberately act on a project whose owner does not own the batch, plus
             * the arrival of a colleague at all. Their hourlyCheck() is a no-op - there is no state
             * to sweep for, because the controller that did the thing says so at the time.
             */
            new NotificationNewColleagueImplementation,
            new NotificationColleagueQuotedImplementation,
            new NotificationColleagueOrderedImplementation,

            /*
             * Quarterly, and driven by its own scheduled command rather than by the hourly sweep -
             * see hourlyImplementations(). Listed here because this list is what the bell can
             * render, which is a separate question from what can send.
             */
            new NotificationOffcutCleanoutImplementation,

            /*
             * Deprecated, and listed on purpose. It sends nothing any more - see
             * hourlyImplementations() and its own hourlyCheck() - but rows it sent before that are
             * still sitting unread in people's bells, and its "Lost it" button archives the project
             * it names. Dropping it off this list would leave those rows rendered by nobody: no
             * wording, no buttons, no way to clear them, and the ownership check that stops one
             * user's notification id archiving another business's project never reached.
             */
            new NotificationProjectAwardedImplementation,
        ];
    }

    /**
     * The subset HourlyNotificationsJob runs.
     *
     * Sending and rendering used to be the same list, so the only way to stop a notification type
     * being created was to delete the class that could also still read it.
     *
     * Two are left out. The awarded reminder is deprecated and sends nothing. The offcut cleanout
     * runs four times a year off its own command - costing every aged offcut in every yard against
     * the floor for its section, hourly, to decide to do nothing would be the most expensive no-op
     * here - and its hourlyCheck() is empty in any case.
     *
     * @return array<int, NotificationInterface>
     */
    public function hourlyImplementations(): array
    {
        return array_values(array_filter(
            $this->implementations(),
            fn (NotificationInterface $implementation) => ! $implementation instanceof NotificationProjectAwardedImplementation
                && ! $implementation instanceof NotificationOffcutCleanoutImplementation,
        ));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getUnreadNotifications(?object $user = null): array
    {
        if (! $user) {
            return [];
        }

        //Built once, not once per notification per type as this used to be
        $implementations = $this->implementations();

        $notifications = [];

        foreach ($user->unreadNotifications as $notification) {
            foreach ($implementations as $implementation) {
                $notificationData = $implementation->notificationData($notification);

                if ($notificationData) {
                    $notifications[] = $notificationData;

                    //A row has exactly one type, so the first implementation to claim it is the one
                    break;
                }
            }
        }

        return $notifications;
    }

    public function clearProjectNotifications(Project $project): void
    {
        /**
         * Everything still outstanding about one project, marked read. Each implementation
         * clears its own notification when the thing it asks about is answered - awarded,
         * tentative date confirmed - and none of them treats archiving as an answer, so a
         * project taken off the board kept asking whether it had been awarded.
         *
         * This is also what takes the colleague notifications down: they carry project_id for
         * exactly this reason, so a project that leaves the board stops being talked about.
         */
        DatabaseNotification::query()
            ->where('notifiable_type', "App\Models\User")
            ->where('data->project_id', $project->id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);
    }

    /**
     * Reminders of one class about projects that have stopped deserving them.
     *
     * Replaces clearPreviousNotifications(), which was called from the else branch of each hourly
     * check - "no project is due, so clear this class" - and was wrong in both directions.
     *
     * It fired only when *no project in any business* was due, which on a live multi-tenant board is
     * approximately never, so in practice nothing was ever cleared: a project that was chased and
     * then had its materials date pushed out kept its unread reminder for good, because some other
     * business's project was still due. And on the rare occasion it did fire it marked read every row
     * of that class for every user on the platform, read ones included, which is a mass update of
     * updated_at on a table that only grows.
     *
     * Saying it positively - these project ids are still due, so reminders about any other project
     * are stale - is correct whether one business is live or a thousand.
     *
     * @param  array<int, int>  $stillDueProjectIds
     */
    public function clearStaleProjectReminders(string $class, array $stillDueProjectIds): void
    {
        $classWithPath = "App\Notifications\\".$class;

        //Normalised once, so the strict comparison below cannot miss on 5 vs "5"
        $stillDueProjectIds = array_map('intval', $stillDueProjectIds);

        $unread = DatabaseNotification::query()
            ->where('type', $classWithPath)
            ->where('notifiable_type', "App\Models\User")
            ->whereNull('read_at')
            ->get(['id', 'data']);

        /*
         * Compared in PHP rather than with whereNotIn('data->project_id', ...).
         *
         * A JSON path in a where clause is wrapped differently per driver - mysql unquotes the
         * extraction to a string, sqlite hands back the number - and a string/int mismatch inside
         * NOT IN fails silently in the direction that marks a live reminder read. The tests run on
         * sqlite and production is mysql, so that is a difference no test here would catch. The set
         * is unread reminders of one class, bounded by the number of live projects.
         */
        $stale = $unread
            ->filter(function (DatabaseNotification $notification) use ($stillDueProjectIds) {
                $projectId = $notification->data['project_id'] ?? null;

                //A reminder naming no project cannot be matched to one, so leave it alone
                return $projectId !== null && ! in_array((int) $projectId, $stillDueProjectIds, true);
            })
            ->pluck('id');

        if ($stale->isEmpty()) {
            return;
        }

        DatabaseNotification::query()
            ->whereIn('id', $stale)
            ->update(['read_at' => now()]);
    }
}
