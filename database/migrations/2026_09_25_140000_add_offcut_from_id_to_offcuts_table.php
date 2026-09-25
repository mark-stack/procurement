<?php

use App\Models\Offcut;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('offcuts', function (Blueprint $table) {
            //An offcut cut from another offcut has no bar - it has a source offcut
            $table->integer('offcut_from_id')->nullable()->after('bar_id');
        });

        /*
         * CreateBarsAndOffcuts used to write the SOURCE OFFCUT's id into bar_id, which resolves against
         * the "bars" table - so these rows either pointed at an unrelated bar or at nothing at all.
         * Move any bar_id that is not a real bar across to offcut_from_id.
         */
        Offcut::query()
            ->whereNotNull('bar_id')
            ->whereNotIn('bar_id', fn ($query) => $query->select('id')->from('bars'))
            ->get()
            ->each(function (Offcut $offcut) {
                $offcut->offcut_from_id = $offcut->bar_id;
                $offcut->bar_id = null;
                $offcut->save();
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('offcuts', function (Blueprint $table) {
            $table->dropColumn('offcut_from_id');
        });
    }
};
