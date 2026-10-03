<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Which merchant's steel a batch-attached certificate is for.
     *
     * A certificate on an order needs no such column: the order is one merchant's, so the file is
     * that merchant's by the row it hangs off. A certificate on a batch has no such parent - the
     * batch is the whole job, bought from everybody - and a single pile of PDFs against it would be
     * the pile in the filing cabinet this application exists to replace.
     *
     * So the batch side is uploaded a group at a time, and only for the groups that come with a
     * certificate at all: products.certificates says steel does and timber does not, and asking a
     * timber merchant for a mill certificate is asking for paperwork that was never going to arrive.
     * That is the same flag the BOM's certificate column and the order list's blocks read.
     *
     * Null on every order-attached row, and null is what it stays there - see
     * BatchOrderListController, which reads the two together so one block shows the merchant's
     * certificates however they arrived.
     */
    public function up(): void
    {
        Schema::table('material_certificates', function (Blueprint $table) {
            $table->string('supplier_group', 191)->nullable()->after('batch_id');
        });
    }

    public function down(): void
    {
        Schema::table('material_certificates', function (Blueprint $table) {
            $table->dropColumn('supplier_group');
        });
    }
};
