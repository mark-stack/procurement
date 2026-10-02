<?php

use App\Enums\GradeEnums;
use App\Enums\MaterialEnums;
use App\Enums\ProductEnums;
use App\Enums\SurfaceEnums;
use App\Models\Batch;
use App\Models\Order;
use App\Models\Piece;
use App\Models\Project;
use App\Models\Quote;
use App\Models\RawMaterialQuote;
use App\Models\Supplier;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * A 200PFC row with a piece derived from it, which is what the bulk delete has to unwind.
 */
function bomRowWithPiece(Project $project): RawMaterialQuote
{
    $rawMaterialQuote = createRawMaterialQuote200Pfc(
        $project,
        MaterialEnums::PLAIN_CARBON_STEEL,
        GradeEnums::GR300,
        9000,
    );

    Piece::create([
        'project_id' => $project->id,
        'raw_material_quote_id' => $rawMaterialQuote->id,
        'product_category' => ProductEnums::PFC->value,
        'material' => MaterialEnums::PLAIN_CARBON_STEEL->value,
        'grade' => GradeEnums::GR300->value,
        'surface' => SurfaceEnums::NONE->value,
        'actual_length' => 9000,
    ]);

    return $rawMaterialQuote;
}

it("would be a disaster if another business's material list could be bulk deleted", function () {
    /*
     * The ids arrive in the request body, so there is no bound model for a gate to check -
     * the endpoint had no ownership check at all, and deleted whatever ids it was handed.
     */
    $business1 = createBusiness('biz1');
    $user1 = createUser(1, $business1, false, true);

    $business2 = createBusiness('biz2');
    $user2 = createUser(1, $business2, false, true);
    $theirProject = createProject($user2);
    $theirRow = bomRowWithPiece($theirProject);

    $this->actingAs($user1)
        ->post(route('raw.material.quote.bulk.destroy'), [
            'selectedRawMaterialQuoteIds' => [$theirRow->id],
        ])
        ->assertRedirect();

    expect(RawMaterialQuote::find($theirRow->id))->not->toBeNull();
    expect(Piece::where('raw_material_quote_id', $theirRow->id)->exists())->toBeTrue();
});

it('would be a disaster if your own material list could not be bulk deleted', function () {
    $business = createBusiness('biz1');
    $user = createUser(1, $business, false, true);
    $project = createProject($user);
    $row = bomRowWithPiece($project);

    $this->actingAs($user)
        ->post(route('raw.material.quote.bulk.destroy'), [
            'selectedRawMaterialQuoteIds' => [$row->id],
        ])
        ->assertRedirect();

    expect(RawMaterialQuote::find($row->id))->toBeNull();
    expect(Piece::where('raw_material_quote_id', $row->id)->exists())->toBeFalse();
});

it('lets whoever uploaded a colleague’s material list edit the rows on it', function () {
    /*
     * A draftsman who uploads a BOM for a project manager (see projects.created_by_user_id) can finish
     * the import: confirm a partial price book match, save a custom product, delete a row that came
     * off the sheet wrong. All three endpoints scope through RawMaterialQuote::scopeOwnedByUser, which
     * has to give the same answer as PrerequisiteConditions::uploadMaterials - that gate is what draws
     * the controls, so a narrower scope here would draw a checkbox that can only answer 403.
     */
    $business = createBusiness('biz1');
    $draftsman = createUser(1, $business, false, true);
    $projectManager = createUser(2, $business, false, true);

    $project = createProject($projectManager);
    $project->update(['created_by_user_id' => $draftsman->id]);
    $row = bomRowWithPiece($project);

    $this->actingAs($draftsman)
        ->post(route('raw.material.quote.bulk.destroy'), [
            'selectedRawMaterialQuoteIds' => [$row->id],
        ])
        ->assertRedirect();

    expect(RawMaterialQuote::find($row->id))->toBeNull();
});

it('would be a disaster if a colleague with no hand in the list could delete its rows', function () {
    //The line moved for the uploader alone. Everybody else on the shared board still only reads it.
    $business = createBusiness('biz1');
    $projectManager = createUser(1, $business, false, true);
    $bystander = createUser(2, $business, false, true);

    $project = createProject($projectManager);
    $row = bomRowWithPiece($project);

    $this->actingAs($bystander)
        ->post(route('raw.material.quote.bulk.destroy'), [
            'selectedRawMaterialQuoteIds' => [$row->id],
        ])
        ->assertRedirect();

    expect(RawMaterialQuote::find($row->id))->not->toBeNull();
});

it('would be a disaster if ordered material could be deleted out from under its order', function () {
    /*
     * The modal hides the checkbox on a row that is quoted or ordered, but that rule lived
     * only in the page - the endpoint deleted the row, its piece and the order behind it.
     */
    $business = createBusiness('biz1');
    $user = createUser(1, $business, false, true);
    $project = createProject($user);
    $row = bomRowWithPiece($project);

    $batch = Batch::factory()->forUser($user->id)->create(['done' => false]);
    $supplier = Supplier::factory()->create();

    $order = Order::create([
        'user_id' => $user->id,
        'batch_id' => $batch->id,
        'supplier_id' => $supplier->id,
        'quote_id' => null,
        'order_sent' => true,
        'is_delivered' => false,
    ]);

    $row->piece->update(['order_id' => $order->id]);

    expect($row->fresh()->status())->toBe('ORDERED');

    $this->actingAs($user)
        ->post(route('raw.material.quote.bulk.destroy'), [
            'selectedRawMaterialQuoteIds' => [$row->id],
        ])
        ->assertRedirect();

    expect(RawMaterialQuote::find($row->id))->not->toBeNull();
});

it('would be a disaster if deleting a row reached a batch that has already been bought', function () {
    /*
     * Deleting material rows tidies up after itself: the pieces, the quotes left holding none, and
     * then any batch of this business left with no materials on it at all
     * (Actions/Batch/DeleteBatchesWithoutPieces). That last sweep is over EVERY empty batch the
     * business has, not only the ones this request emptied - so a batch that was somehow left empty
     * at some point in the past is swept by whatever row somebody deletes next, months later.
     *
     * This is the shape found in a live database: a batch with no pieces, a sent quote, and an order
     * that has been sent and booked in as delivered. The steel was bought and it arrived. Deleting a
     * row on an unrelated project tried to delete that batch, and what stopped it was the foreign key
     * on orders.batch_id - the request died on "Cannot delete or update a parent row", which is a 500
     * on a modal that was only deleting a BOM line, and it would have died on every subsequent delete
     * too.
     *
     * The record is the thing to protect here. Had the orders been swept out of the way first, the
     * constraint would not have fired and a delivered purchase would have been deleted silently.
     */
    $business = createBusiness('biz1');
    $user = createUser(1, $business, false, true);
    $project = createProject($user);

    //The row being deleted: nothing quoted, nothing ordered, on no batch - deletable
    $row = bomRowWithPiece($project);

    $bought = Batch::factory()->forUser($user->id)->create(['done' => false]);
    $supplier = Supplier::factory()->create();

    $quote = Quote::create([
        'user_id' => $user->id,
        'batch_id' => $bought->id,
        'supplier_id' => $supplier->id,
        'supplier_category' => 'STEEL_MERCHANT',
        'supplier_quote_reference' => null,
        'quote_sent' => true,
        'quoted_price' => null,
        'quoted_lead_time' => null,
    ]);

    $order = Order::create([
        'user_id' => $user->id,
        'batch_id' => $bought->id,
        'supplier_id' => $supplier->id,
        'quote_id' => $quote->id,
        'order_sent' => true,
        'is_delivered' => true,
    ]);

    //No pieces on it, which is what brings the sweep looking
    expect($bought->pieces()->count())->toBe(0);

    $this->actingAs($user)
        ->post(route('raw.material.quote.bulk.destroy'), [
            'selectedRawMaterialQuoteIds' => [$row->id],
        ])
        ->assertRedirect();

    //The row went, which is what was asked for
    expect(RawMaterialQuote::find($row->id))->toBeNull();

    //And the purchase is still there, every row of it
    expect(Batch::find($bought->id))->not->toBeNull();
    expect(Quote::find($quote->id))->not->toBeNull();
    expect(Order::find($order->id))->not->toBeNull();
});

it('still clears away a batch left holding nothing at all', function () {
    /*
     * The other side of the test above: a batch with no pieces, no quotes and no orders is a shell
     * nobody can reach - it draws a card on the board and on /nesting with nothing on it - and
     * sweeping those is what DeleteBatchesWithoutPieces is for. The guard that saves a bought batch
     * must not save these too.
     */
    $business = createBusiness('biz1');
    $user = createUser(1, $business, false, true);
    $project = createProject($user);
    $row = bomRowWithPiece($project);

    $shell = Batch::factory()->forUser($user->id)->create(['done' => false]);

    $this->actingAs($user)
        ->post(route('raw.material.quote.bulk.destroy'), [
            'selectedRawMaterialQuoteIds' => [$row->id],
        ])
        ->assertRedirect();

    expect(Batch::find($shell->id))->toBeNull();
});

it("would be a disaster if another business's rows could be deleted through clarifications", function () {
    $business1 = createBusiness('biz1');
    $user1 = createUser(1, $business1, false, true);

    $business2 = createBusiness('biz2');
    $user2 = createUser(1, $business2, false, true);
    $theirProject = createProject($user2);
    $theirRow = bomRowWithPiece($theirProject);

    $this->actingAs($user1)
        ->post(route('raw.material.quote.clarifications'), [
            'deletedIds' => [$theirRow->id],
        ])
        ->assertRedirect();

    expect(RawMaterialQuote::find($theirRow->id))->not->toBeNull();
});

it("would be a disaster if another business's rows could be deleted through customisations", function () {
    $business1 = createBusiness('biz1');
    $user1 = createUser(1, $business1, false, true);

    $business2 = createBusiness('biz2');
    $user2 = createUser(1, $business2, false, true);
    $theirProject = createProject($user2);
    $theirRow = bomRowWithPiece($theirProject);

    $this->actingAs($user1)
        ->post(route('raw.material.quote.customisations'), [
            'deletedIds' => [$theirRow->id],
        ])
        ->assertRedirect();

    expect(RawMaterialQuote::find($theirRow->id))->not->toBeNull();
});

it("would be a disaster if another business's bill of materials could be downloaded", function () {
    $business1 = createBusiness('biz1');
    $user1 = createUser(1, $business1, false, true);

    $business2 = createBusiness('biz2');
    $user2 = createUser(1, $business2, false, true);
    $theirProject = createProject($user2);

    $this->actingAs($user1)
        ->get(route('download.bom', $theirProject))
        ->assertForbidden();
});

it('would be a disaster if unimported items were lost, or kept after they were imported', function () {
    /*
     * The warning was append-only, so a line the user fixed and re-uploaded stayed listed
     * forever - and the two causes were merged into one list labelled with only the first.
     */
    $business = createBusiness('biz1');
    $user = createUser(1, $business, false, true);
    $project = createProject($user);

    $project->recordUnimportedItems(['20PL 1220mm', 'M16x100'], ['SS316 M16 x 150'], ['100x100x10EA']);

    expect($project->fresh()->unimportedItems())->toBe([
        'notRecognised' => ['20PL 1220mm', 'M16x100'],
        'otherPlan' => ['SS316 M16 x 150'],
        'couldNotBeRead' => ['100x100x10EA'],
    ]);

    //The user fixes one of them and re-uploads
    $row = createRawMaterialQuote200Pfc($project, MaterialEnums::PLAIN_CARBON_STEEL, GradeEnums::GR300, 9000);
    $row->update(['description' => '20PL 1220mm']);

    $project->fresh()->forgetImportedItems();

    expect($project->fresh()->unimportedItems())->toBe([
        'notRecognised' => ['M16x100'],
        'otherPlan' => ['SS316 M16 x 150'],
        'couldNotBeRead' => ['100x100x10EA'],
    ]);
});

it('would be a disaster if an unreadable items_not_found took down the whole modal', function () {
    /*
     * It was read back with a bare unserialize() and piped straight into implode(),
     * which is a fatal TypeError on anything that does not round-trip.
     */
    $business = createBusiness('biz1');
    $user = createUser(1, $business, false, true);
    $project = createProject($user);

    Project::query()->where('id', $project->id)->update(['items_not_found' => 'not-decodable']);

    $this->actingAs($user)
        ->get(route('download.bom', $project))
        ->assertOk()
        ->assertJsonPath('downloadedBomData.data.unimportedItems.notRecognised', [])
        ->assertJsonPath('downloadedBomData.data.unimportedItems.otherPlan', []);
});
