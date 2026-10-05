<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * "This merchant's price is in", said one merchant at a time.
     *
     * The ordered mark's other half (see the 2026_10_03_140000 migration), and it exists for the same
     * reason: the order list is a block per merchant, and the step a block is at is a fact about that
     * merchant rather than about the job. A batch whose steel has been priced and whose timber has not
     * is neither quoted nor unquoted, and until now the only mark between the two was "All quoted" on
     * the card menu, which says both at once.
     *
     * It is also what the order list offers while the batch is still quoting. The block used to offer
     * "Mark as ordered" from the moment the batch was nested, which is the press after next on a batch
     * nobody has a price for yet - see OrderListModal.vue.
     *
     * A list of supplier group names, like the ordered one, and for the same reasons: a group is what
     * SupplierFormatter::supplierGroups computes off the business's plan and its products, there is no
     * row anywhere to point a foreign key at, and a name is what the block is keyed by on screen. A
     * renamed group leaves its mark behind pointing at the old name, which reads as that merchant
     * being unquoted again - the honest failure for a list of names, on a record that is usually
     * bought and delivered inside a week.
     *
     * Nothing is sent by one and no quote row is written. The order list reads these alongside the
     * sent quotes and says "Quoted" for either (see BatchOrderListController), exactly as it already
     * does for the two ways of being ordered.
     */
    public function up(): void
    {
        Schema::table('batches', function (Blueprint $table) {
            $table->json('quoted_supplier_groups')->nullable()->after('ordered_supplier_groups');
        });
    }

    public function down(): void
    {
        Schema::table('batches', function (Blueprint $table) {
            $table->dropColumn('quoted_supplier_groups');
        });
    }
};
