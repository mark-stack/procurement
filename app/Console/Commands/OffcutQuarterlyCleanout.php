<?php

namespace App\Console\Commands;

use App\Models\Business;
use App\Models\NotificationLog;
use App\Models\User;
use App\Notifications\OffcutCleanoutDue;
use App\Services\OffcutCleanout;
use Illuminate\Console\Command;

/**
 * Once a quarter, tell each business which of its offcuts have stopped earning their place.
 *
 * Quarterly because that is the right cadence for the decision. The rack does not change fast
 * enough to be worth a weekly nag, and a yard that is reviewed once a year has a year's worth of
 * dead stock in it by the time anybody looks.
 *
 * It proposes and nothing more. Deciding that a piece of steel is never going to be used is a
 * judgement about work that has not been quoted yet, and no scheduled job is in a position to make
 * it - see Services\OffcutCleanout. What this does is put the list, and the money, in front of
 * somebody who is.
 */
class OffcutQuarterlyCleanout extends Command
{
    protected $signature = 'offcuts:cleanout';

    protected $description = 'Tell each business which offcuts have sat past their shelf life and are too short to pay for their keep';

    /**
     * How the marks are recorded in notification_logs.type, with the quarter appended.
     */
    private const string PREFIX = 'OFFCUT_CLEANOUT_';

    public function handle(OffcutCleanout $cleanout): int
    {
        $quarter = $this->quarter();
        $raised = 0;

        /*
         * Chunked. This is the one job that reads every business's whole offcut rack, and a
         * platform's worth of them hydrated at once is exactly the kind of thing that runs fine for
         * a year and then falls over on a Sunday night.
         */
        Business::query()->chunkById(50, function ($businesses) use ($cleanout, $quarter, &$raised) {
            foreach ($businesses as $business) {
                $recipient = $this->recipient($business);

                //Nobody to tell. A business with no users has no rack anybody is walking past either
                if ($recipient === null) {
                    continue;
                }

                if ($this->alreadyRaised($recipient, $business, $quarter)) {
                    continue;
                }

                $candidates = $cleanout->candidates($business);

                /*
                 * Nothing to clear, and no mark written. A quarter in which the rack was clean
                 * should not stop the next run noticing that it no longer is, which is what logging
                 * an empty result would do.
                 */
                if ($candidates->isEmpty()) {
                    continue;
                }

                $recipient->notify(new OffcutCleanoutDue(
                    $business,
                    $candidates->count(),
                    (float) $candidates->sum('net_drain'),
                    $quarter,
                ));

                $this->mark($recipient, $business, $quarter);
                $raised++;
            }
        });

        $this->info("Offcut cleanout notices raised: {$raised}");

        return self::SUCCESS;
    }

    /**
     * The quarter this run belongs to, as the idempotency key.
     *
     * Dated rather than counted, so a re-run after a failure - or a second run because somebody
     * triggered the schedule by hand - lands on the same key and sends nothing twice.
     */
    private function quarter(): string
    {
        $now = now();

        return $now->year.'Q'.$now->quarter;
    }

    /**
     * Who hears about it: the earliest user of the business, whoever signed the company up.
     *
     * The same choice SendTrialReminders makes, for the same reason. Anyone in the business may
     * remove an offcut - the steel is shared and so is the rack - but clearing it is one job, and
     * putting the identical red dot in front of ten people makes it nobody's.
     */
    private function recipient(Business $business): ?User
    {
        return $business->users()->oldest('id')->first();
    }

    private function alreadyRaised(User $recipient, Business $business, string $quarter): bool
    {
        return NotificationLog::query()
            ->where('recipient_user_id', $recipient->id)
            ->where('unique_model_id', $business->id)
            ->where('type', self::PREFIX.$quarter)
            ->exists();
    }

    private function mark(User $recipient, Business $business, string $quarter): void
    {
        NotificationLog::create([
            'recipient_user_id' => $recipient->id,
            'unique_model_id' => $business->id,
            'type' => self::PREFIX.$quarter,
        ]);
    }
}
