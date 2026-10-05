<?php

namespace Database\Seeders;

use App\Actions\Batch\StartQuoting;
use App\Formatters\TestingFormatter;
use App\Models\Batch;
use App\Models\Business;
use App\Models\Project;
use App\Models\User;
use App\Sandbox\Sandbox;
use App\Services\DataClassificationService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

/**
 * A batch whose bar rows carry more than one offcut mark, for looking at.
 *
 * Identical bars are consolidated into one "3 off" row on the nesting sheet, but each of those bars
 * is cut for real and each leaves its own offcut under its own mark - so the drawing splits a row
 * that produced several offcuts into that many separate bars, each "1 off" and each marked once.
 * None of that is visible on a nest that happens to leave nothing reusable, and whether a nest does
 * is a property of the lengths, not something the screen can be asked for.
 *
 * So this writes a BOM chosen so that all four cases are on one sheet, in one batch:
 *
 *  - 200PFC splits three ways, 250PFC splits two ways - the thing itself.
 *  - 180PFC produced one offcut and stays "1 off", so a single mark still reads as it always did.
 *  - 150PFC leaves 300mm, under this business's 1,000mm scrap threshold, so it produces no offcut
 *    and stays consolidated as "2 off". Consolidation is not being abandoned, only split where the
 *    marks require it, and without this row that is a claim rather than something visible.
 *
 * The lengths are not decoration. Each one is picked against the 1,000mm threshold and the 9m/12m
 * bars this catalogue sells, and the nest is a real nest - the cost model is free to buy whatever it
 * likes, so report() below prints what it actually did rather than what was intended. If a
 * coefficient is retuned and a row stops splitting, that report says so instead of this quietly
 * seeding a sheet that demonstrates nothing.
 */
class BarMarksDemoSeeder extends Seeder
{
    private const PROJECT_NAME = 'Offcut marks demo';

    /**
     * The BOM, as [PFC section, cut length in mm, how many, what the row is here to show].
     *
     * Business::scrap_threshold_mm decides which drops become offcuts, so a business that keeps
     * steel down to 500mm or throws away anything under 2m will nest these differently. That is
     * what report() is for.
     */
    private const BOM = [
        [200, 3400, 9, '3 x 12m, 1,800mm each -> three offcuts, so the row splits three ways'],
        [250, 5200, 4, '2 x 12m, 1,600mm each -> two offcuts, so the row splits two ways'],
        [180, 7400, 1, '1 x 9m, 1,600mm -> one offcut, so the row is left alone'],
        [150, 3900, 6, '2 x 12m, 300mm each -> scrap, so the row stays consolidated as "2 off"'],
    ];

    public function run(): void
    {
        $user = $this->targetUser();
        $business = $user->business;

        if (! $business instanceof Business) {
            throw new RuntimeException("{$user->email} has no business, so there is nothing to nest for.");
        }

        /*
         * Seed into whichever mode the batch is going to be looked at in - the same reasoning as
         * OffcutRackDemoSeeder. Batches carry a sandbox stamp and a global scope filters on it, and
         * the stamp is read off the authenticated user, which a seeder does not have. Authenticating
         * as the target puts this where their own work would land; BAR_MARKS_SEED_SANDBOX overrides
         * that IN MEMORY ONLY, because a seeder has no business saving somebody's test mode for them.
         */
        $forceSandbox = env('BAR_MARKS_SEED_SANDBOX');

        if ($forceSandbox !== null) {
            $user->sandbox_mode = (bool) $forceSandbox;
        }

        Auth::setUser($user);

        $mode = Sandbox::isActive() ? "{$user->name}'s test mode" : 'live data';

        $this->command?->info(
            "Nesting a demo batch for {$user->name} <{$user->email}> (business {$business->id}), in {$mode}."
        );

        $existing = $this->existingDemoBatch($user);

        if ($existing instanceof Batch) {
            $this->command?->warn('This account already has the demo batch. Nothing written.');
            $this->report($existing);

            return;
        }

        $project = $this->project($user);
        $batch = $this->batch($user, $business, $project);

        $this->report($batch);
    }

    private function targetUser(): User
    {
        $email = env('BAR_MARKS_SEED_EMAIL');

        if (! $email) {
            throw new RuntimeException(
                'Set BAR_MARKS_SEED_EMAIL to the address of the user who should own the demo batch, '
                .'e.g. BAR_MARKS_SEED_EMAIL=someone@example.com php artisan db:seed --class=BarMarksDemoSeeder'
            );
        }

        $user = User::where('email', $email)->first();

        if (! $user) {
            throw new RuntimeException("No user with the email {$email}.");
        }

        return $user;
    }

    /**
     * The batch this seeder already wrote for this account, if it is still there.
     *
     * Found through the project rather than by a marker on the batch, because a batch has nowhere to
     * carry one and the project name is this seeder's own. Deleting the project, or unwinding the
     * batch, lets it be seeded again.
     */
    private function existingDemoBatch(User $user): ?Batch
    {
        return Project::query()
            ->where('user_id', $user->id)
            ->where('name', self::PROJECT_NAME)
            ->get()
            ->flatMap(fn (Project $project) => $project->pieces()->whereNotNull('batch_id')->pluck('batch_id'))
            ->map(fn ($batchId) => Batch::find($batchId))
            ->filter()
            ->first();
    }

    /**
     * The job the steel is for: one project, carrying the four BOM lines above.
     *
     * Written through TestingFormatter because a piece is not a standalone row - it is the confirmed
     * half of a raw material quote, which is the line somebody uploaded - and a piece without one is
     * a shape the importer could never produce.
     */
    private function project(User $user): Project
    {
        $classifier = new DataClassificationService;
        $testing = new TestingFormatter;

        $project = Project::create([
            'name' => self::PROJECT_NAME,
            'user_id' => $user->id,
            'reference' => 'DEMO-MARKS',
            'date_materials_required' => null,
            //Required of every project created through the form, so a fixture without one is unreal
            'date_fabrication_begins' => now()->addMonth()->toDateString(),
            'tentative' => false,
            'done' => false,
        ]);

        $rows = [];
        foreach (self::BOM as [$section, $length, $qty, $note]) {
            $rows[] = $testing->piecePfc($section, $length, $qty, $project, $classifier);
        }

        $testing->createPieces($rows, $project, $classifier);

        return $project;
    }

    /**
     * Press "Lock batch for quoting" for this project, and nothing else.
     *
     * StartQuoting is the real write - it claims the pieces, creates the batch, writes the order
     * approvals and runs SaveNesting, which is what actually cuts the bars, banks the offcuts and
     * issues their marks. Nothing here reproduces any of that, so the sheet this seeds is a real
     * sheet rather than a hand-built imitation of one.
     *
     * What it is NOT given is piecesReadyForBatching. The press sweeps every unbatched piece in the
     * business into one batch, which is correct for a person pressing it and wrong for a seeder:
     * running this would quietly take whatever else is waiting on the Nesting page into the demo
     * batch. It is handed this project's pieces instead - the same write, over a smaller claim.
     */
    private function batch(User $user, Business $business, Project $project): Batch
    {
        $batch = StartQuoting::run($user, $business, $project->pieces()->get());

        if (! $batch instanceof Batch) {
            throw new RuntimeException('StartQuoting claimed nothing, so no batch was written.');
        }

        return $batch;
    }

    /**
     * What the nest actually did, printed row by row.
     *
     * The point of the batch is that some rows split and others do not, and that is decided by a
     * cost model weighing steel against handling - not by the lengths in self::BOM. So this reads
     * the saved nest back and says which rows carry several marks, which carry one and which carry
     * none, and complains if none of them split.
     */
    private function report(Batch $batch): void
    {
        $this->command?->newLine();
        $this->command?->info("Batch {$batch->id} - /batch-nesting/{$batch->id}/current/1");

        $split = 0;

        /*
         * A product is an object and everything under it is an array - see Casts\NestedState, which
         * casts only that one level back, because the readers want $product->nested['utilisedBars'].
         */
        foreach ($batch->nested_state['METERAGE'] ?? [] as $spec) {
            $label = $spec->product_derived_label ?? '?';

            foreach ($spec->nested['utilisedBars'] ?? [] as $bar) {
                $marks = $bar['result']['unique_marks'] ?? array_filter([$bar['result']['unique_mark'] ?? null]);

                if (count($marks) > 1) {
                    $split++;
                }

                $this->command?->line(sprintf(
                    '  %-8s %d off %smm, %smm unused -> %s',
                    $label,
                    $bar['count'],
                    number_format((int) $bar['result']['bar_length']),
                    number_format((int) $bar['result']['unused']),
                    count($marks) > 0
                        ? count($marks).' offcut(s), drawn as '.count($marks).' separate bar(s): '
                            .implode(', ', array_map(fn ($mark) => '"'.$mark.'"', $marks))
                        : 'no offcut, drawn as one row',
                ));
            }
        }

        $this->command?->newLine();

        if ($split === 0) {
            $this->command?->error(
                'No row produced more than one offcut, so this batch does not show the split. The '
                .'scrap threshold or the catalogue lengths have moved away from what self::BOM was '
                .'chosen against - adjust the lengths until a row carries several marks.'
            );

            return;
        }

        $this->command?->info("{$split} row(s) carry several marks. Open the link above to see them drawn apart.");
    }
}
