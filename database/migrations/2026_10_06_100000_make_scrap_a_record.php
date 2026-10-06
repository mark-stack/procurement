<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Scrap as a row, with the weight and the money on it.
     *
     * Until now the only record of destroyed steel was a per-bar millimetre figure buried in a
     * batch's nested_state JSON (NestingFormatter::summariseBars) and the SCRAPPED case on
     * OffcutRemovalEnums. Neither is a quantity anybody can trend: the JSON has to be parsed a batch
     * at a time to be read at all, it carries no mass and no money, and the removal flag on an offcut
     * says a piece left the yard without saying how much steel that was or what it was worth. A yield
     * objective measured against either is an objective nothing can report on.
     *
     * The table this replaces was created in February 2025 and never written to - App\Models\Scrap
     * was an empty class and ScrapController seven empty stubs - so there is nothing to migrate and
     * no down path worth preserving beyond the original shape. The spec columns are kept exactly as
     * they were, because they are the same columns bars and offcuts carry and the same ones
     * Services\ProductSpec matches on.
     *
     * What is new is everything that makes a row answerable:
     *
     *  - WHERE IT CAME FROM. The batch, and the bar or the offcut the saw left it on. A nest drop
     *    names its bar; a drop taken off a piece already on the rack names that offcut; a cleanout
     *    names the offcut it weighed in. The batch is on every row, which is also what scopes this
     *    table to a business and to a sandbox - see App\Models\Batch and the BelongsToSandbox trait.
     *
     *  - WHAT IT WEIGHED AND WHAT IT COST. Resolved once, when the scrap happens, and stored. NOT
     *    recomputed on read: kg_per_m comes off the product catalogue, and a catalogue row whose spec
     *    is edited afterwards no longer matches the steel that was destroyed (see Services\ProductSpec),
     *    so a report that re-derived the mass would quietly restate last quarter's figures. The mass
     *    used is written down beside the answer, with a flag saying whether it was the catalogue's or
     *    the business default, so a figure that rests on a guess reads as one.
     *
     *  - WHEN. scrapped_at rather than created_at, because the two are not the same for a row
     *    reconstructed from a batch nested before this table existed - see Services\ScrapLedger. A
     *    month's scrap has to mean the month the steel was destroyed, not the month somebody ran the
     *    backfill.
     */
    public function up(): void
    {
        Schema::dropIfExists('scraps');

        Schema::create('scraps', function (Blueprint $table) {
            $table->id();
            $table->timestamps();

            /*
             * The batch whose steel this is.
             *
             * For a nest drop, the batch that cut it. For a cleanout, the batch that originally
             * produced the offcut being weighed in - the only batch the material was ever part of.
             *
             * Not nullable, and it does two jobs beyond provenance: every offcut carries a
             * batch_from_id, so there is always one to name, and reporting reaches this table through
             * Business::batches() - which is where both the business scoping and the sandbox filter
             * live. A scrap row with no batch would be invisible to one and leak past the other.
             *
             * Cascades, for the reason the cuts table gives: unwinding a batch says those cuts were
             * never made, so the drop they left never existed either.
             */
            $table->foreignId('batch_id')->constrained()->cascadeOnDelete();

            /*
             * The bar it came off, where it came off new stock, and the offcut where it came off the
             * rack. Exactly one of the two on a nest drop; a cleanout always names the offcut.
             *
             * App\Models\Scrap enforces that rule rather than a check constraint, the way
             * App\Models\Cut does, so it reads the same under mysql and under the sqlite the tests
             * run on.
             */
            $table->foreignId('bar_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('offcut_id')->nullable()->constrained()->cascadeOnDelete();

            /*
             * Which of the two things that destroy steel this was - see App\Enums\ScrapSourceEnums.
             * They are not the same event and they do not belong to the same person: a nest drop is
             * the arithmetic of a cut plan nobody chose, and a cleanout is a decision somebody made
             * about steel that was sitting in the rack. Reports separate them.
             */
            $table->string('source');

            /*
             * Who weighed it in, and what they said about it. Both null on a nest drop, which nobody
             * decides. Nullable past the user being gone for the reason offcuts.removed_by_user_id is:
             * the scrap is a fact about the steel and outlives the account that recorded it.
             */
            $table->foreignId('scrapped_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('note')->nullable();

            //Product attributes - the same columns bars and offcuts carry
            $table->text('product_category');               //PFC
            $table->text('material')->nullable();           //PLAIN CARBON STEEL
            $table->text('grade')->nullable();              //GR250
            $table->text('surface')->nullable();            //NONE
            $table->string('nominal_length')->nullable();   //9000
            $table->string('precise_length')->nullable();   //
            $table->string('nominal_width')->nullable();    //
            $table->string('precise_width')->nullable();    //
            $table->string('nominal_height')->nullable();   //200
            $table->string('precise_height')->nullable();   //
            $table->float('wall')->nullable();              //

            //Spelled out once, so a report does not have to rebuild "200PFC" from eleven columns
            $table->string('product_derived_label');

            //The drop itself, in millimetres. Float to match bars.length and offcuts.length
            $table->float('length');

            /*
             * What that length weighed, what owning it had cost by the time it was destroyed, and what
             * the merchant pays back for the metal. The write-off is the difference, and it is left as
             * a difference rather than stored: two of the three are facts about this steel and the
             * third would be a third place for them to disagree.
             *
             * "value" is the LANDED figure and "recovered_value" is priced off bare steel, which is the
             * distinction Services\NestingCostModel::scrapIncome exists to keep - a weighbridge pays
             * for metal, not for the freight that brought it here.
             */
            $table->float('weight_kg');
            $table->float('value');
            $table->float('recovered_value');

            /*
             * The mass per metre the three figures above were worked out from, and whether it was the
             * catalogue's or the business default. See the class docblock: without these a row's money
             * cannot be checked, and a valuation that fell back to default_kg_per_m - which for a
             * heavy section is out by a wide margin - would read exactly like one that did not.
             */
            $table->float('kg_per_m');
            $table->boolean('kg_per_m_estimated')->default(false);

            /*
             * When the steel was destroyed, which for a nest drop is when the batch was nested.
             * Separate from created_at so a backfilled row lands in the month it happened.
             */
            $table->timestamp('scrapped_at');

            /*
             * The three reads: everything off one batch, everything off one piece of steel, and a
             * period. The period index carries the source because every report splits on it.
             */
            $table->index('batch_id');
            $table->index('bar_id');
            $table->index('offcut_id');
            $table->index(['scrapped_at', 'source']);
        });
    }

    /**
     * Back to the shape of the February 2025 table, which nothing ever wrote to.
     */
    public function down(): void
    {
        Schema::dropIfExists('scraps');

        Schema::create('scraps', function (Blueprint $table) {
            $table->id();
            $table->timestamps();

            $table->integer('batch_id');

            $table->text('product_category');
            $table->text('material');
            $table->text('grade');
            $table->text('surface');
            $table->string('nominal_length')->nullable();
            $table->string('precise_length')->nullable();
            $table->string('nominal_width')->nullable();
            $table->string('precise_width')->nullable();
            $table->string('nominal_height')->nullable();
            $table->string('precise_height')->nullable();
            $table->float('wall')->nullable();

            $table->float('length');
        });
    }
};
