<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Which order bought this bar, and which heat of steel it came out of.
     *
     * These two columns are what turn the certificate trail from a set into an answer. Batch.php says
     * what it had to settle for without them, in a comment on the offcut ancestry:
     *
     *     "the bar a cut was taken from is not recorded against an order, so the honest answer is the
     *      certificates the material could have come from"
     *
     * That is a true statement and a failing traceability system. AS/NZS 5131 and EN 1090 both want a
     * part tied to the heat it was rolled from, one to one - not to a list of three certificates one of
     * which applies. ISO 9001 8.5.2 is the general form of the same requirement: where traceability is
     * a requirement, the organisation shall control the unique identification of outputs and retain the
     * documented information necessary to enable traceability.
     *
     * bars was created with product spec, a length and later a batch_id, and nothing that identified
     * the steel itself. With order_id, a bar knows which purchase it arrived on and therefore which
     * certificates hang off it; with heat_number, it knows which line of that certificate is its own.
     *
     * Both nullable, and both stay nullable:
     *
     *   order_id, because a bar exists from the moment a batch is nested and no supplier is chosen
     *   until somebody presses "Sent order". A nested-but-not-ordered bar legitimately belongs to no
     *   order. See App\Actions\Bar\AttachBarsToOrder.
     *
     *   heat_number, because it is read off a docket at the gate and plenty of deliveries arrive
     *   without one. Null means "not recorded", and the offcut trail keeps reporting the
     *   could-have-come-from answer for those - which is why that code is not being deleted. What
     *   changes is that a yard filling these in gets the exact answer instead.
     */
    public function up(): void
    {
        Schema::table('bars', function (Blueprint $table) {
            /*
             * nullOnDelete rather than cascade. Deleting an order must not delete the steel: the bars
             * are physically in the yard, their offcuts are on the rack, and the cuts made from them
             * are in somebody's building. Losing the link is bad; losing the bar is unrecoverable.
             */
            $table->foreignId('order_id')->nullable()->after('batch_id')->constrained()->nullOnDelete();

            //As printed on the mill certificate. A string: heat and cast numbers are not numbers
            $table->string('heat_number', 191)->nullable()->after('order_id');

            //"which bars has this order bought" is read on every goods receipt
            $table->index('order_id');
        });
    }

    public function down(): void
    {
        Schema::table('bars', function (Blueprint $table) {
            $table->dropIndex(['order_id']);
            $table->dropConstrainedForeignId('order_id');
            $table->dropColumn('heat_number');
        });
    }
};
