<?php

namespace App\Console\Commands;

use App\Billing\Billing;
use App\Models\Business;
use App\Models\NotificationLog;
use App\Models\User;
use App\Notifications\TrialEndingEmail;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Warn people before their free trial runs out, and tell them when it has.
 *
 * A trial that expires without warning is not a trial, it is a trap - the first a customer would
 * otherwise know of it is a refused save halfway through a job.
 *
 * Idempotent by way of notification_logs, so it is safe to run as often as you like and safe to run
 * again after a failure: each business gets each reminder once, whatever the schedule does.
 */
class SendTrialReminders extends Command
{
    protected $signature = 'billing:trial-reminders';

    protected $description = 'Email businesses whose free trial is about to end, and those whose trial has just ended';

    /**
     * How the marks are recorded in notification_logs.type. The number is the bucket, not
     * necessarily the days left when it was sent - see handle().
     */
    private const string ENDING_PREFIX = 'TRIAL_ENDING_';

    private const string ENDED = 'TRIAL_ENDED';

    /**
     * How long after expiry the "your trial has ended" email is still worth sending. Beyond this
     * the account has been read-only long enough that the notice would be news to nobody, and a
     * freshly deployed reminder job should not email years of dormant signups.
     */
    private const int ENDED_WINDOW_DAYS = 3;

    public function handle(Billing $billing): int
    {
        $marks = collect((array) config('billing.reminder_days'))
            ->map(fn ($days): int => (int) $days)
            ->sortDesc()
            ->values();

        $sent = 0;

        $sent += $this->remindEndingSoon($billing, $marks);
        $sent += $this->notifyEnded($billing);

        $this->info("Trial reminders sent: {$sent}");

        return self::SUCCESS;
    }

    /**
     * @param  Collection<int, int>  $marks
     */
    private function remindEndingSoon(Billing $billing, Collection $marks): int
    {
        if ($marks->isEmpty()) {
            return 0;
        }

        $sent = 0;

        /*
         * Cheap first pass in SQL; whether they are really still on trial is Billing's call below.
         *
         * ->activated() used to lead this, to keep the warnings away from a business still waiting on
         * us to write its import templates - being chased for money for a product you have never been
         * able to open. There is no such business now: an upload teaches the importer the format, so
         * every trial is a trial somebody can spend.
         */
        $candidates = Business::query()
            ->whereNotNull('trial_ends_at')
            ->whereBetween('trial_ends_at', [now(), now()->addDays($marks->max())])
            ->get();

        foreach ($candidates as $business) {
            $state = $billing->state($business);

            //Subscribed, or on a paid-by-invoice grant, since the query above was written
            if (! $state->onTrial()) {
                continue;
            }

            $owner = $this->owner($business);

            if ($owner === null) {
                continue;
            }

            $daysRemaining = $state->daysRemaining() ?? 0;

            /*
             * Largest mark first, and stop at the first one that is both due and unsent. So a
             * business first seen with three days left gets the seven-day email now rather than
             * missing it, and still gets the one-day email later. One email per business per run.
             */
            foreach ($marks as $mark) {
                if ($daysRemaining > $mark) {
                    continue;
                }

                if ($this->alreadySent($owner, $business, self::ENDING_PREFIX.$mark)) {
                    continue;
                }

                $owner->notify(new TrialEndingEmail($business, $daysRemaining));
                $this->log($owner, $business, self::ENDING_PREFIX.$mark);
                $sent++;

                break;
            }
        }

        return $sent;
    }

    private function notifyEnded(Billing $billing): int
    {
        $sent = 0;

        //No ->activated() here either - see remindEndingSoon()
        $candidates = Business::query()
            ->whereNotNull('trial_ends_at')
            ->whereBetween('trial_ends_at', [now()->subDays(self::ENDED_WINDOW_DAYS), now()])
            ->get();

        foreach ($candidates as $business) {
            //Read-only is the whole point of the email, so anyone who is not read-only bought
            //something in time and must not be told their account has stopped
            if ($billing->state($business)->allowsWrites()) {
                continue;
            }

            $owner = $this->owner($business);

            if ($owner === null || $this->alreadySent($owner, $business, self::ENDED)) {
                continue;
            }

            $owner->notify(new TrialEndingEmail($business, null));
            $this->log($owner, $business, self::ENDED);
            $sent++;
        }

        return $sent;
    }

    /**
     * The earliest user of the business - whoever signed the company up.
     *
     * Deliberately not every user. The trial belongs to the company, and a ten-person fabrication
     * shop does not need ten copies of an email about a subscription only one of them arranges.
     */
    private function owner(Business $business): ?User
    {
        return $business->users()->oldest('id')->first();
    }

    private function alreadySent(User $owner, Business $business, string $type): bool
    {
        return NotificationLog::query()
            ->where('recipient_user_id', $owner->id)
            ->where('unique_model_id', $business->id)
            ->where('type', $type)
            ->exists();
    }

    private function log(User $owner, Business $business, string $type): void
    {
        NotificationLog::create([
            'recipient_user_id' => $owner->id,
            'unique_model_id' => $business->id,
            'type' => $type,
        ]);
    }
}
