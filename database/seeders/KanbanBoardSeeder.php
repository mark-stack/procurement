<?php

namespace Database\Seeders;

use App\Enums\SupplierGroupEnums;
use App\Formatters\KanbanFormatter;
use App\Formatters\NestingFormatter;
use App\Formatters\TestingFormatter;
use App\Models\Batch;
use App\Models\Business;
use App\Models\Order;
use App\Models\Piece;
use App\Models\Project;
use App\Models\Quote;
use App\Models\Supplier;
use App\Models\User;
use App\Services\DataClassificationService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

/**
 * One card in each of the board's four columns, for whoever is named in KANBAN_SEED_EMAIL.
 *
 * The board is worth looking at in all four states at once, and a freshly registered business
 * reaches the fourth only by importing a BOM, nesting it, quoting it, sending the orders and
 * marking them delivered. This is that end state, written directly.
 *
 * It tops up rather than building a set of four. The columns are the whole business's, not one
 * user's, so on a database that already has a batch out for quotes a full set would leave two
 * cards in that column and the point of the exercise - one card, each column - is lost. Each
 * column is asked whether it already draws a card and only the empty ones are filled, which also
 * makes this safe to run twice.
 *
 * Nothing here is deleted or edited: every row it writes is new.
 */
class KanbanBoardSeeder extends Seeder
{
    public function run(): void
    {
        $user = $this->targetUser();
        $business = $user->business;

        if (! $business) {
            throw new RuntimeException("{$user->email} has no business, so it has no board to fill.");
        }

        /*
         * BatchService::sortByUserAndLatest, which every batch column runs through, reads
         * auth()->user() to decide whose cards sort first. Unauthenticated - a console command is -
         * that is a null it calls getKey() on, so the columns cannot even be read without this.
         */
        Auth::setUser($user);

        $this->command?->info("Filling the board for {$user->name} <{$user->email}> (business {$business->id}).");

        $this->fillNesting($user, $business);
        $this->fillQuoting($user, $business);
        $this->fillOrdering($user, $business);
        $this->fillDelivered($user, $business);

        $this->report($user, $business);
    }

    private function targetUser(): User
    {
        $email = env('KANBAN_SEED_EMAIL');

        if (! $email) {
            throw new RuntimeException(
                'Set KANBAN_SEED_EMAIL to the address of the user whose board should be filled, '
                .'e.g. KANBAN_SEED_EMAIL=someone@example.com php artisan db:seed --class=KanbanBoardSeeder'
            );
        }

        $user = User::where('email', $email)->first();

        if (! $user) {
            throw new RuntimeException("No user with the email {$email}.");
        }

        return $user;
    }

    /**
     * Column 1: a project whose pieces are not on a batch yet.
     *
     * The column draws one card listing every such project in the business, so this is the one
     * column where a second project would not mean a second card - it is topped up on the same
     * "is it empty" test as the rest for consistency.
     */
    private function fillNesting(User $user, Business $business): void
    {
        $piecesReadyForBatching = (new NestingFormatter)->piecesReadyForBatching($business);

        if ($business->projectsReadyForBatching($piecesReadyForBatching)->isNotEmpty()) {
            $this->command?->line('  Nesting: already drawn, left alone.');

            return;
        }

        $this->projectWithPieces($user, 'Mezzanine handrails', 'MEZZ-01', [
            [7000, 2],
            [1700, 7],
            [15000, 1],
            [900, 13],
        ]);

        $this->command?->line('  Nesting: added "Mezzanine handrails".');
    }

    /**
     * Column 2: a batch with no order sent.
     *
     * A draft order per supplier is minted the first time the quote screen is opened, so an unsent
     * order is the ordinary shape here rather than no order at all - and KanbanFormatter selects
     * this column on hasNoSentOrder(), not on having none.
     */
    private function fillQuoting(User $user, Business $business): void
    {
        if (count((new KanbanFormatter)->quotedColumn($business, $user)) > 0) {
            $this->command?->line('  Quoting: already drawn, left alone.');

            return;
        }

        $project = $this->projectWithPieces($user, 'Walkway stringers', 'WALK-04', [
            [6000, 4],
            [2400, 6],
        ]);

        $batch = $this->batchFor($user, $project);
        $this->orderOnBatch($user, $batch, orderSent: false, isDelivered: false, materialCertNumbers: null);

        $this->command?->line("  Quoting: added \"Walkway stringers\" as batch {$batch->id}.");
    }

    /**
     * Column 3: a batch with an order sent and its materials not all covered.
     *
     * Ordering and Delivering read the same active batches and are told apart by order coverage
     * alone, so this project has to be short of 100% - half its material rows point at the sent
     * order and half at nothing, the state a batch is in between sending the first order and the
     * last.
     */
    private function fillOrdering(User $user, Business $business): void
    {
        if (count((new KanbanFormatter)->orderedColumn($business)) > 0) {
            $this->command?->line('  Ordering: already drawn, left alone.');

            return;
        }

        $project = $this->projectWithPieces($user, 'Conveyor gantry', 'CONV-11', [
            [9000, 3],
            [4500, 5],
            [1200, 8],
            [3000, 2],
        ]);

        $batch = $this->batchFor($user, $project);
        $order = $this->orderOnBatch($user, $batch, orderSent: true, isDelivered: false, materialCertNumbers: null);

        //Half the rows ordered. percentageOfMaterialsOrdered() counts raw material rows, not pieces
        $pieces = $project->pieces()->orderBy('id')->get();
        $pieces->take((int) floor($pieces->count() / 2))->each(function (Piece $piece) use ($order) {
            $piece->order_id = $order->id;
            $piece->save();
        });

        $this->command?->line("  Ordering: added \"Conveyor gantry\" as batch {$batch->id}, half ordered.");
    }

    /**
     * Column 4: a batch whose every material row is on a sent order.
     *
     * Delivered and certified, so the card offers "Move to done" rather than the missing-certs
     * warning - the two states the column has, and the finished one is the one worth seeing.
     */
    private function fillDelivered(User $user, Business $business): void
    {
        if (count((new KanbanFormatter)->deliveredColumn($business)) > 0) {
            $this->command?->line('  Delivering: already drawn, left alone.');

            return;
        }

        $project = $this->projectWithPieces($user, 'Silo ladder cage', 'SILO-07', [
            [5000, 4],
            [2000, 9],
        ]);

        $batch = $this->batchFor($user, $project);
        $order = $this->orderOnBatch($user, $batch, orderSent: true, isDelivered: true, materialCertNumbers: 'CERT-40119');

        $project->pieces()->update(['order_id' => $order->id]);

        $this->command?->line("  Delivering: added \"Silo ladder cage\" as batch {$batch->id}, all in.");
    }

    /**
     * A project with a 200 PFC bill of materials, built the way DatabaseSeeder builds its samples.
     *
     * Through TestingFormatter rather than by hand because the material rows have to match the
     * price book: a row that only partially matches puts the whole project behind a clarification,
     * and projectsReadyForBatching() then keeps it off the board altogether.
     *
     * @param  array<int, array{0: int, 1: int}>  $nest  [length, qty] per material row
     */
    private function projectWithPieces(User $user, string $name, string $reference, array $nest): Project
    {
        $testingFormatter = new TestingFormatter;
        $dataClassificationService = new DataClassificationService;

        $project = Project::create([
            'name' => $name,
            'user_id' => $user->id,
            'reference' => $reference,
            'date_materials_required' => now()->addWeeks(3),
            'tentative' => false,
            'archive' => false,
        ]);

        $bom = $testingFormatter->sampleBOM($project, $dataClassificationService, $nest);
        $testingFormatter->createPieces($bom, $project, $dataClassificationService);

        return $project;
    }

    /**
     * Nests a project, the way "Start quoting" does: a batch, with the project's pieces on it.
     *
     * Batch::projects() reads its projects off the pieces, so the batch_id is the whole of the
     * relationship - there is no column on the project.
     */
    private function batchFor(User $user, Project $project): Batch
    {
        $batch = Batch::create([
            'user_id' => $user->id,
            'done' => false,
        ]);

        $project->pieces()->update(['batch_id' => $batch->id]);

        return $batch;
    }

    private function orderOnBatch(
        User $user,
        Batch $batch,
        bool $orderSent,
        bool $isDelivered,
        ?string $materialCertNumbers,
    ): Order {
        $supplier = $this->steelMerchant($user->business);

        $quote = Quote::create([
            'user_id' => $user->id,
            'batch_id' => $batch->id,
            'supplier_id' => $supplier->id,
            'supplier_category' => SupplierGroupEnums::STEEL_MERCHANT->value,
            'supplier_quote_reference' => null,
            'quote_sent' => true,
            'quoted_price' => null,
            'quoted_lead_time' => null,
        ]);

        return Order::create([
            'user_id' => $user->id,
            'batch_id' => $batch->id,
            'supplier_id' => $supplier->id,
            'quote_id' => $quote->id,
            'order_sent' => $orderSent,
            'is_delivered' => $isDelivered,
            'material_cert_numbers' => $materialCertNumbers,
        ]);
    }

    /**
     * The bill of materials above is all PFC, so the order belongs to a steel merchant. One of the
     * business's own where there is one - a supplier it has never heard of would be a stranger on
     * its quote screen.
     */
    private function steelMerchant(Business $business): Supplier
    {
        $isSteel = fn (Supplier $supplier) => (bool) (
            unserialize($supplier->supplier_categories ?? 'a:0:{}')[SupplierGroupEnums::STEEL_MERCHANT->value] ?? false
        );

        $supplier = $business->suppliers->first($isSteel)
            ?? Supplier::all()->first($isSteel);

        if ($supplier) {
            return $supplier;
        }

        $supplier = Supplier::create([
            'name' => 'Seeded Steel Co',
            'supplier_categories' => serialize([
                SupplierGroupEnums::FASTENERS->value => false,
                SupplierGroupEnums::STEEL_MERCHANT->value => true,
                SupplierGroupEnums::TIMBER_MERCHANT->value => false,
            ]),
        ]);
        $business->suppliers()->attach($supplier->id);

        return $supplier;
    }

    private function report(User $user, Business $business): void
    {
        $kanbanFormatter = new KanbanFormatter;
        $piecesReadyForBatching = (new NestingFormatter)->piecesReadyForBatching($business);

        $this->command?->info('Cards on the board now:');
        $this->command?->line('  Nesting:    '.($business->projectsReadyForBatching($piecesReadyForBatching)->isEmpty() ? 0 : 1));
        $this->command?->line('  Quoting:    '.count($kanbanFormatter->quotedColumn($business, $user)));
        $this->command?->line('  Ordering:   '.count($kanbanFormatter->orderedColumn($business)));
        $this->command?->line('  Delivering: '.count($kanbanFormatter->deliveredColumn($business)));
    }
}
