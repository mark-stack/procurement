<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * When somebody last read the master catalogue, what they found, and why a bad row was kept.
     *
     * The catalogue is a measuring instrument. products.kg_per_m decides what a millimetre of a
     * section weighs, and that mass is what every tonne price, every offcut valuation and every
     * scrap write-off is derived through - see Services\NestingCostModel, where a missing mass
     * falls back to default_kg_per_m, a flat 10.0 that is about right for light angle and four
     * times out on a 500UB. ISO 9001 7.1.5 asks whether the resources used for monitoring and
     * measurement are suitable for it, and the honest answer here was that nobody could say: the
     * catalogue has never recorded that anybody looked at it.
     *
     * Two columns' worth of answer, in two different shapes:
     *
     *  - catalogue_reviews is a SERIES. A single reviewed_at on some settings row would answer "when
     *    was it last done" and destroy the previous answer every time it was done again, which is
     *    the one thing a periodic review cannot afford - "every quarter" is a claim about a
     *    sequence. Each row also carries what the catalogue looked like at the time, so a review is
     *    evidence of a finding rather than a timestamp on its own.
     *
     *  - products.accepted_reason is PER ROW, and it is the other half of a review. The trust report
     *    (Services\CatalogueTrust) lists what cannot be relied on, and some of those rows are not
     *    going to be corrected: the catalogue's only stainless hex bolt carries its grade in its
     *    description, seven LVL rows have no grade because nobody grades LVL that way. Accepting one
     *    deliberately, with the reason written down, is a different state to not having looked -
     *    without somewhere to say so, the list never empties and so stops being read.
     */
    public function up(): void
    {
        Schema::create('catalogue_reviews', function (Blueprint $table) {
            $table->id();
            $table->timestamps();

            /*
             * Its own column rather than created_at, the way scraps.scrapped_at and
             * batch_measurements.nested_at are: these are read as a series and the date the review
             * happened is the thing being asserted, not an incidental fact about the row.
             */
            $table->timestamp('reviewed_at')->index();

            /*
             * nullOnDelete, matching templates.reviewed_by_user_id: a departed admin's review still
             * happened, and the date is the half of it that an auditor is asking about.
             */
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            //What they were looking for, or what they changed. Optional - looking is the record
            $table->text('note')->nullable();

            /*
             * The catalogue as it stood, counted at the moment of the review.
             *
             * Recomputed figures would be worse than none: re-deriving "how many rows had no mass"
             * today tells you about today's catalogue, so a review from March would silently report
             * March's date against October's findings - and the whole point of the record is that
             * the two can be compared. Same reason batch_measurements stores a yield rather than
             * re-nesting for it.
             */
            $table->unsignedInteger('products_reviewed');

            /*
             * Reason => how many rows carried it, keyed by the constants on Services\CatalogueTrust.
             * JSON rather than a column each because the list of things that make a row untrustworthy
             * is expected to grow, and a review written before a reason existed should read as
             * silent on it rather than as zero.
             */
            $table->json('untrusted');
        });

        Schema::table('products', function (Blueprint $table) {
            /*
             * Why this row is flagged and being kept anyway. Null is the ordinary state - either
             * nothing is wrong with the row, or nobody has decided yet. See ProductRules::EDITABLE,
             * which is what carries it through the admin form and the JSON export/import, so an
             * acceptance made in one environment is not lost on the next sync.
             */
            $table->text('accepted_reason')->nullable()->after('baseline_supplier');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('accepted_reason');
        });

        Schema::dropIfExists('catalogue_reviews');
    }
};
