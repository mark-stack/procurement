<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The difference between a certificate file that was disposed of and one that went missing.
     *
     * DownloadMaterialCertificateController already handles a row whose file is not on the disk - it
     * aborts 404 with the comment "the row can outlive its file if the disk was cleared out from
     * under it". That is the right behaviour for an accident and the wrong record of a decision. Once
     * certificate files have a retention period (config/retention.php, seven years), a file reaching
     * the end of it and being deliberately deleted looks from the application exactly like a disk
     * that lost it, and the one thing a disposal has to be is distinguishable from a loss.
     *
     * So the row stays, always - it is the account of what arrived, from which merchant, against
     * which heat, uploaded by whom, and none of that is big enough to be worth reclaiming - and this
     * column says the file behind it went on purpose, on that date. What actually deleted it is in
     * record_dispositions, with the person who authorised it.
     *
     * Nullable, and null on every row that exists today: nothing has been disposed of yet, and a
     * backfill would be inventing a decision nobody made.
     */
    public function up(): void
    {
        Schema::table('material_certificates', function (Blueprint $table) {
            $table->timestamp('file_disposed_at')->nullable()->after('size_bytes');
        });
    }

    public function down(): void
    {
        Schema::table('material_certificates', function (Blueprint $table) {
            $table->dropColumn('file_disposed_at');
        });
    }
};
