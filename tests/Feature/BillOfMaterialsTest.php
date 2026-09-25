<?php

use App\Enums\GradeEnums;
use App\Enums\MaterialEnums;
use App\Enums\ProductEnums;
use App\Enums\SurfaceEnums;
use App\Models\Batch;
use App\Models\Order;
use App\Models\Piece;
use App\Models\Project;
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
    $business1 = createBusiness('biz1', true);
    $user1 = createUser(1, $business1, false, true);

    $business2 = createBusiness('biz2', true);
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
    $business = createBusiness('biz1', true);
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

it('would be a disaster if ordered material could be deleted out from under its order', function () {
    /*
     * The modal hides the checkbox on a row that is quoted or ordered, but that rule lived
     * only in the page - the endpoint deleted the row, its piece and the order behind it.
     */
    $business = createBusiness('biz1', true);
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

it("would be a disaster if another business's rows could be deleted through clarifications", function () {
    $business1 = createBusiness('biz1', true);
    $user1 = createUser(1, $business1, false, true);

    $business2 = createBusiness('biz2', true);
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
    $business1 = createBusiness('biz1', true);
    $user1 = createUser(1, $business1, false, true);

    $business2 = createBusiness('biz2', true);
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
    $business1 = createBusiness('biz1', true);
    $user1 = createUser(1, $business1, false, true);

    $business2 = createBusiness('biz2', true);
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
    $business = createBusiness('biz1', true);
    $user = createUser(1, $business, false, true);
    $project = createProject($user);

    $project->recordUnimportedItems(['20PL 1220mm', 'M16x100'], ['SS316 M16 x 150']);

    expect($project->fresh()->unimportedItems())->toBe([
        'notRecognised' => ['20PL 1220mm', 'M16x100'],
        'otherPlan' => ['SS316 M16 x 150'],
    ]);

    //The user fixes one of them and re-uploads
    $row = createRawMaterialQuote200Pfc($project, MaterialEnums::PLAIN_CARBON_STEEL, GradeEnums::GR300, 9000);
    $row->update(['description' => '20PL 1220mm']);

    $project->fresh()->forgetImportedItems();

    expect($project->fresh()->unimportedItems())->toBe([
        'notRecognised' => ['M16x100'],
        'otherPlan' => ['SS316 M16 x 150'],
    ]);
});

it('would be a disaster if an unreadable items_not_found took down the whole modal', function () {
    /*
     * It was read back with a bare unserialize() and piped straight into implode(),
     * which is a fatal TypeError on anything that does not round-trip.
     */
    $business = createBusiness('biz1', true);
    $user = createUser(1, $business, false, true);
    $project = createProject($user);

    Project::query()->where('id', $project->id)->update(['items_not_found' => 'not-decodable']);

    $this->actingAs($user)
        ->get(route('download.bom', $project))
        ->assertOk()
        ->assertJsonPath('downloadedBomData.data.unimportedItems.notRecognised', [])
        ->assertJsonPath('downloadedBomData.data.unimportedItems.otherPlan', []);
});
