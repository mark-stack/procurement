<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per physical cut: this part, off that bar.
     *
     * This is the link the traceability chain was missing in the middle. bars now knows which order
     * bought it and which heat it came from; pieces has always known which project asked for it. What
     * nothing recorded was which of them met - so "which heat is in beam B12" had no path from one end
     * to the other, and the certificate trail could only answer with every certificate the batch bought.
     *
     * Why a table and not a bars_id column on pieces.
     *
     * A piece row is a line of DEMAND, not a part. pieces.actual_qty of 3 means three cuts, and
     * NestingFormatter expands it into three independent entries before nesting starts
     * (nestingMeterageAlgo builds one cut per unit of actual_qty). Each one is then packed on its own
     * merits, so they routinely land on different bars - and the offcut path can take some of them off
     * the rack while the rest come off new steel. A single bar_id on the piece would be correct only
     * for a quantity of one, and silently wrong - which is the worst way for a traceability record to
     * be wrong - for every other row. Three parts, three heats, three rows.
     *
     * Exactly one of bar_id and offcut_id is set on any row: steel comes off a new bar or off the rack,
     * never both and never neither. App\Models\Cut enforces that, rather than a check constraint, so
     * that the rule reads the same on mysql and on the sqlite the tests run against.
     *
     * These rows are derived from the saved nest and are deleted with it. Unwinding a batch means the
     * cuts were never made - the same reasoning BatchController already applies to the bars and offcuts
     * it deletes - and the cascades below are what make that automatic rather than a fourth thing that
     * has to be remembered in that method.
     */
    public function up(): void
    {
        Schema::create('cuts', function (Blueprint $table) {
            $table->id();
            $table->timestamps();

            /*
             * The part. Cascades: a piece that is deleted was never cut - RawMaterialListBulkDeleteController
             * only reaches pieces that are neither quoted nor ordered, so nothing with a cut behind it
             * gets there anyway.
             */
            $table->foreignId('piece_id')->constrained()->cascadeOnDelete();

            /*
             * The nest that planned it. Cascades for the reason given above: un-nesting deletes the
             * plan, and this row is part of the plan.
             */
            $table->foreignId('batch_id')->constrained()->cascadeOnDelete();

            /*
             * Where the steel came from - exactly one of these two.
             *
             * bar_id for new stock, offcut_id for something off the rack. The offcut case is how a cut
             * inherits the ancestry trail that Offcut::loadAncestry walks, which is what carries
             * certificates back through an offcut of an offcut of an offcut.
             */
            $table->foreignId('bar_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('offcut_id')->nullable()->constrained()->cascadeOnDelete();

            /*
             * The cut itself, in millimetres, excluding the saw kerf - the kerf is swarf and belongs to
             * neither the part nor the drop. Float to match bars.length and offcuts.length, which is
             * what this is measured against.
             */
            $table->float('length');

            //"every cut of this piece" and "everything cut from this bar" are the two reads
            $table->index('piece_id');
            $table->index('bar_id');
            $table->index('offcut_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cuts');
    }
};
