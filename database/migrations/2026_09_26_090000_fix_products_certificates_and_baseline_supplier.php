<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        /*
         * certificates held the spreadsheet's literal "TRUE"/"FALSE" text while every consumer
         * compared it to a boolean, so `where('certificates', true)` matched nothing and
         * `(bool) $product->certificates` was true for every product. Normalise the existing
         * text before narrowing the column.
         */
        DB::table('products')
            ->whereRaw('UPPER(certificates) IN (?, ?, ?, ?)', ['TRUE', 'T', 'YES', 'Y'])
            ->update(['certificates' => '1']);

        DB::table('products')
            ->whereRaw('UPPER(certificates) IN (?, ?, ?, ?)', ['FALSE', 'F', 'NO', 'N'])
            ->update(['certificates' => '0']);

        DB::table('products')
            ->whereNotIn('certificates', ['1', '0'])
            ->update(['certificates' => null]);

        Schema::table('products', function (Blueprint $table) {
            $table->boolean('certificates')->nullable()->change();

            // The sheet's BASELINE_SUPPLIER column was parsed on every import and then discarded
            $table->text('baseline_supplier')->nullable()->after('kg_per_m');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('baseline_supplier');
            $table->string('certificates')->nullable()->change();
        });

        DB::table('products')->where('certificates', '1')->update(['certificates' => 'TRUE']);
        DB::table('products')->where('certificates', '0')->update(['certificates' => 'FALSE']);
    }
};
