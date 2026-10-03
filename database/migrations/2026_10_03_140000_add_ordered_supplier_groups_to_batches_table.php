<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * "This merchant has been ordered from", said one merchant at a time.
     *
     * The batch-wide marks added earlier today answer for the whole job at once, which is the right
     * size of answer for a shop that rings one merchant and buys the lot. It is the wrong size for the
     * common case: the steel goes to the steel merchant through the quotes screen, the timber is
     * bought over the phone, and the order list shows both as "Not ordered" because only one of them
     * has a row behind it.
     *
     * So the order list's blocks get a mark of their own. A list of supplier group names on the batch
     * rather than a table: there is no supplier_group row anywhere in this application to point a
     * foreign key at - a group is what SupplierFormatter::supplierGroups computes off the business's
     * plan and its products - and a name is exactly what the block is keyed by on screen.
     *
     * Nothing is sent by one, no order row is written, and a group with a real sent order never needs
     * it. The order list reads the two together and says "Ordered" for either (see
     * BatchOrderListController).
     *
     * Renaming a supplier group leaves a mark behind pointing at the old name, which reads as that
     * group being unordered again. That is the honest failure for a list of names, and the better
     * trade than inventing a table of groups to key it to: the mark is a note about this batch, and a
     * batch is usually bought and delivered inside a week.
     */
    public function up(): void
    {
        Schema::table('batches', function (Blueprint $table) {
            $table->json('ordered_supplier_groups')->nullable()->after('cut_by_user_id');
        });
    }

    public function down(): void
    {
        Schema::table('batches', function (Blueprint $table) {
            $table->dropColumn('ordered_supplier_groups');
        });
    }
};
