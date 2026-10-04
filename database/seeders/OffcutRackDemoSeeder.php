<?php

namespace Database\Seeders;

use App\Formatters\UniqueLetterIDGenerator;
use App\Models\Batch;
use App\Models\Business;
use App\Models\Offcut;
use App\Models\Order;
use App\Models\Product;
use App\Models\Quote;
use App\Models\User;
use App\Notifications\OffcutCleanoutDue;
use App\Sandbox\Sandbox;
use App\Services\OffcutCleanout;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Auth;
use RuntimeException;
use Throwable;

/**
 * A rack with some history on it, for whoever is named in OFFCUT_SEED_EMAIL.
 *
 * The quarterly cleanout cannot be looked at on a fresh database, because everything it reasons
 * about is a year old. This writes a yard that has been running for three years: nine offcuts off
 * one long-delivered job, chosen so that the rule is visible rather than merely satisfied.
 *
 * Five are dead stock and four are not, and the four are the point:
 *
 *  - A 1,500mm 530UB is KEPT while a 1,150mm angle is scrapped. The shorter piece goes and the
 *    longer one stays, because a metre of 92kg/m beam is worth far more than the quarter hour it
 *    takes to deal with, and a metre of light angle is not.
 *  - Two pairs are identical in section and length and differ only in age. The old one is dead
 *    stock and the young one is untouched, which is the half of the rule that no amount of
 *    measuring the steel could ever supply.
 *
 * Every length here is checked against the floor the real cost model computes for that section, so
 * the fixture cannot quietly stop demonstrating anything if a coefficient is retuned - see
 * report(), which prints what the service actually made of it.
 */
class OffcutRackDemoSeeder extends Seeder
{
    /**
     * The rack, as [catalogue description, length in mm, how many days ago it was cut, what it is
     * here to show].
     *
     * The floors these are placed against, at the default coefficients: 65x65x6 EA 2,210mm,
     * 100PFC 1,860mm, 150PFC 1,370mm, 250UB31 1,170mm, 530UB92 1,040mm. Shelf life is a year.
     */
    private const SHELF = [
        //Dead stock: old enough, and under the floor for its own section
        ['65x65x6 EA 9m', 1150, 830, 'Dead stock - light angle, half the 2,210mm it would need'],
        ['65x65x6 EA 9m', 1900, 520, 'Dead stock - still under the angle floor at 1.9m, which surprises people'],
        ['100PFC 9m', 1300, 1140, 'Dead stock - three years on the rack'],
        ['150PFC 9m', 1200, 430, 'Dead stock - only just under the 1,370mm floor'],
        ['250 UB 31 9m', 1050, 730, 'Dead stock - under the floor even for a beam'],

        //Kept, each for a different reason
        ['530 UB 92 12m', 1500, 910, 'Kept - LONGER than the angle above and older, but heavy enough to pay its way'],
        ['65x65x6 EA 9m', 1150, 90, 'Kept - identical to the worst row above, but cut this quarter'],
        ['65x65x6 EA 9m', 4200, 730, 'Kept - old, but well over the floor'],
        ['150PFC 9m', 1200, 60, 'Kept - identical to a dead row above, but young'],
    ];

    public function run(): void
    {
        $user = $this->targetUser();
        $business = $user->business;

        if (! $business instanceof Business) {
            throw new RuntimeException("{$user->email} has no business, so there is no rack to fill.");
        }

        /*
         * Seed into whichever mode the rack is going to be looked at in.
         *
         * Batches carry a sandbox stamp and a global scope filters on it (Models\Concerns\
         * BelongsToSandbox), and Sandbox::ownerId() reads that off the authenticated user - which a
         * seeder does not have. So everything written here is LIVE data by default, and to somebody
         * browsing with test mode on the Cleanout tab reads empty, which looks exactly like the
         * feature not working.
         *
         * Authenticating as the target makes this land where their own work would. OFFCUT_SEED_SANDBOX
         * then overrides which side of the toggle that is, IN MEMORY ONLY - the user's own setting is
         * never saved, because a seeder has no business flipping somebody's test mode for them.
         */
        $forceSandbox = env('OFFCUT_SEED_SANDBOX');

        if ($forceSandbox !== null) {
            $user->sandbox_mode = (bool) $forceSandbox;
        }

        Auth::setUser($user);

        $mode = Sandbox::isActive() ? "{$user->name}'s test mode" : 'live data';

        $this->command?->info("Filling the offcut rack for {$user->name} <{$user->email}> (business {$business->id}), in {$mode}.");

        if ($this->alreadySeeded($business)) {
            $this->command?->warn('This rack already carries the demo offcuts. Nothing written.');
            $this->command?->line('Scrap them on the Offcuts page to clear it, or put them back from the Removed tab.');

            $this->report($business, $user);

            return;
        }

        $batch = $this->deliveredBatch($user);
        $marks = new UniqueLetterIDGenerator;

        foreach (self::SHELF as [$description, $lengthMm, $daysOld, $note]) {
            $this->offcut($business, $batch, $marks, $description, $lengthMm, $daysOld, $note);
        }

        $this->report($business, $user);
    }

    private function targetUser(): User
    {
        $email = env('OFFCUT_SEED_EMAIL');

        if (! $email) {
            throw new RuntimeException(
                'Set OFFCUT_SEED_EMAIL to the address of the user whose rack should be filled, '
                .'e.g. OFFCUT_SEED_EMAIL=someone@example.com php artisan db:seed --class=OffcutRackDemoSeeder'
            );
        }

        $user = User::where('email', $email)->first();

        if (! $user) {
            throw new RuntimeException("No user with the email {$email}.");
        }

        return $user;
    }

    /**
     * Whether this rack is already carrying the demo.
     *
     * Matched on the lengths rather than on a marker column, because an offcut has nowhere to
     * carry one. Two of the nine are distinctive enough to be sure - nothing a real nest produces
     * lands on 1,150mm and 4,200mm of the same section by accident - and being wrong here only
     * ever means refusing to write a second copy.
     */
    private function alreadySeeded(Business $business): bool
    {
        return $business->availableOffcuts()
            ->whereIn('length', [1150, 4200])
            ->distinct()
            ->count('length') >= 2;
    }

    /**
     * The job the rack came off: one batch, quoted and ordered from a steel merchant, delivered
     * three years ago.
     *
     * Delivery is not decoration. Business::availableOffcuts only counts steel whose source batch
     * has a delivered order from a supplier category that stocks the product, because an offcut of
     * a bar that has not turned up yet is not in the yard - so without this the whole rack would
     * be invisible to both the page and the nest.
     */
    private function deliveredBatch(User $user): Batch
    {
        $orderedOn = now()->subDays(self::oldestDays() + 30);

        $batch = Batch::create(['user_id' => $user->id]);
        $batch->forceFill(['created_at' => $orderedOn, 'updated_at' => $orderedOn])->save();

        /*
         * STEEL_MERCHANT, because the offcut is only in the yard if the delivered order came from
         * the group that carries its product - putting this under fasteners would write a rack
         * nothing can see. The group is the whole of who the order went to: there is no suppliers
         * list to pick a merchant out of.
         */
        $quote = Quote::create([
            'user_id' => $user->id,
            'batch_id' => $batch->id,
            'supplier_category' => 'STEEL_MERCHANT',
            'supplier_quote_reference' => 'DEMO-RACK',
            'quote_sent' => true,
            'quoted_price' => null,
            'quoted_lead_time' => null,
        ]);

        Order::create([
            'user_id' => $user->id,
            'batch_id' => $batch->id,
            'quote_id' => $quote->id,
            'order_sent' => true,
            'order_confirmation_received' => true,
            'purchase_order_number' => 'DEMO-RACK',
            'is_delivered' => true,
            'material_cert_numbers' => 'CERT-DEMO-RACK',
        ]);

        return $batch;
    }

    /**
     * One piece on the rack, cut to the spec of a real catalogue row.
     *
     * The spec columns are copied off the product rather than typed out, because they are a join
     * key rather than data (see Services\ProductSpec): the cleanout resolves this section's mass
     * per metre by matching them, and a single field typed differently would cost the offcut at the
     * business default mass instead - which is exactly the "mass assumed" case the page warns about,
     * and not what this fixture is for.
     */
    private function offcut(
        Business $business,
        Batch $batch,
        UniqueLetterIDGenerator $marks,
        string $description,
        int $lengthMm,
        int $daysOld,
        string $note,
    ): void {
        $product = Product::where('description', $description)->first();

        if (! $product instanceof Product) {
            throw new RuntimeException(
                "The catalogue has no \"{$description}\". Seed it first: php artisan db:seed --class=MasterMaterialsSeeder"
            );
        }

        $cutOn = now()->subDays($daysOld);

        $offcut = Offcut::create([
            'batch_from_id' => $batch->id,
            'batch_to_id' => null,
            'business_id' => $business->id,
            'piece_to_id' => null,
            'bar_id' => null,

            //The section, exactly as the catalogue spells it
            'product_category' => $product->product_category,
            'material' => $product->material,
            'grade' => $product->grade,
            'surface' => $product->surface,
            'nominal_length' => $product->nominal_length,
            'precise_length' => $product->precise_length,
            'nominal_width' => $product->nominal_width,
            'precise_width' => $product->precise_width,
            'nominal_height' => $product->nominal_height,
            'precise_height' => $product->precise_height,
            'wall' => $product->wall,

            'length' => $lengthMm,
            'unique_mark' => $marks->generate((string) $product->product_category, $business->id),
        ]);

        //Back-dated after the insert: Eloquent stamps created_at itself on the way in
        $offcut->forceFill(['created_at' => $cutOn, 'updated_at' => $cutOn])->save();

        $this->command?->line(sprintf(
            '  %-4s %-16s %5dmm  cut %s  %s',
            $offcut->unique_mark,
            trim($offcut->product_derived_label),
            $lengthMm,
            $cutOn->format('M Y'),
            $note,
        ));
    }

    /**
     * What the cleanout actually made of it, read back through the real service.
     *
     * Printed rather than asserted, because this is a seeder - but it is the difference between
     * "nine rows were written" and "the feature you wanted to look at is now looking at them".
     */
    private function report(Business $business, User $target): void
    {
        $candidates = (new OffcutCleanout)->candidates($business);

        $this->command?->newLine();
        $this->command?->info(sprintf(
            'On the rack: %d offcuts, of which %d are up for scrapping (%s of handling they will not earn back).',
            $business->availableOffcuts()->count(),
            $candidates->count(),
            '$'.number_format((float) $candidates->sum('net_drain'), 2),
        ));

        foreach ($candidates as $candidate) {
            /** @var Offcut $offcut */
            $offcut = $candidate['offcut'];

            $this->command?->line(sprintf(
                '  %-4s %-16s %5dmm  %4d days  pays its way from %s  worth $%s vs $%s to keep',
                $offcut->unique_mark,
                trim($offcut->product_derived_label),
                $candidate['length_mm'],
                $candidate['age_days'],
                $candidate['floor_mm'] === null ? 'never' : number_format($candidate['floor_mm']).'mm',
                number_format($candidate['worth'], 2),
                number_format($candidate['keep_cost'], 2),
            ));
        }

        $this->raiseNotice($business, $target, $candidates->count(), (float) $candidates->sum('net_drain'));

        $this->command?->newLine();
        $this->command?->line('Look at it on /offcuts, under the Cleanout tab.');

        /*
         * Said out loud, because the two racks cannot see each other and an empty Cleanout tab
         * looks identical whether the fixture is missing or merely in the other mode.
         */
        $this->command?->line(Sandbox::isActive()
            ? "  This went into {$target->name}'s TEST MODE. Turn test mode off and the tab reads whatever the live rack holds."
            : "  This went into LIVE data. If {$target->name} is browsing in test mode the tab will look empty - turn it off, or re-run with test mode on.");
    }

    /**
     * Put the quarterly notice in the bell, so the whole loop can be seen and not just the page.
     *
     * The scheduled command sends to the business's EARLIEST user - the rack is one job, and ten
     * copies of the same red dot makes it nobody's - so on a business where the person being shown
     * this is not that user, the command would fill somebody else's bell and leave theirs empty.
     * This sends it to them directly instead, and says so, rather than quietly changing who the
     * real command addresses.
     */
    private function raiseNotice(Business $business, User $target, int $count, float $netDrain): void
    {
        if ($count < 1) {
            return;
        }

        $owner = $business->users()->oldest('id')->first();

        try {
            $target->notify(new OffcutCleanoutDue($business, $count, $netDrain, now()->year.'Q'.now()->quarter));
        } catch (Throwable $exception) {
            $this->command?->warn('Could not raise the bell notice: '.$exception->getMessage());

            return;
        }

        $this->command?->newLine();
        $this->command?->info("Bell notice raised for {$target->name}.");

        if ($owner && $owner->id !== $target->id) {
            $this->command?->line(
                "  Note: in production `offcuts:cleanout` addresses the business's earliest user, "
                ."who here is {$owner->name}. This went to {$target->name} so it is in the bell you are logged into."
            );
        }
    }

    /**
     * The age of the oldest piece on the shelf, so the job it came off predates all of it.
     */
    private static function oldestDays(): int
    {
        return max(array_map(fn (array $row): int => $row[2], self::SHELF));
    }
}
