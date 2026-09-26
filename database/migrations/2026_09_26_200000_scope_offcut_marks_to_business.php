<?php

use App\Formatters\UniqueLetterIDGenerator;
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
         * An offcut is only ever visible to the business that cut it (Business::availableOffcuts), so
         * the unique mark stamped on it only has to be unique there. business_id is denormalised onto
         * the row because the provenance runs offcuts -> batches -> users -> business, and a unique
         * index cannot be built across a join.
         */
        Schema::table('offcuts', function (Blueprint $table) {
            $table->unsignedBigInteger('business_id')->nullable()->after('batch_to_id');
        });

        $this->backfillBusinessIds();

        /*
         * TEXT cannot be indexed on MySQL without a prefix length, and the mark itself has never had
         * a length of its own - three or four letters have been sitting in a varchar(255).
         */
        Schema::table('offcuts', function (Blueprint $table) {
            $table->string('product_category', 64)->change();
            $table->string('unique_mark', 32)->change();
        });

        $this->resolveDuplicateMarks();

        /*
         * The real guarantee. Generation reads the table and then inserts, and nesting runs inside a
         * transaction (QuoteController), so two businesses nesting at the same moment each read a
         * mark list that does not yet contain the other's offcuts. Only the database can stop the
         * two duplicate rows that follow.
         */
        Schema::table('offcuts', function (Blueprint $table) {
            $table->unique(
                ['business_id', 'product_category', 'unique_mark'],
                'offcuts_business_category_mark_unique',
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('offcuts', function (Blueprint $table) {
            $table->dropUnique('offcuts_business_category_mark_unique');
            $table->dropColumn('business_id');
        });

        Schema::table('offcuts', function (Blueprint $table) {
            $table->text('product_category')->change();
            $table->string('unique_mark')->change();
        });
    }

    /**
     * Every existing offcut belongs to the business whose user owns the batch it was cut from.
     */
    private function backfillBusinessIds(): void
    {
        $businessByBatch = DB::table('batches')
            ->join('users', 'users.id', '=', 'batches.user_id')
            ->whereNotNull('users.business_id')
            ->pluck('users.business_id', 'batches.id');

        //One update per business rather than one per offcut. The batch ids are the KEYS, so the
        //grouping has to preserve them.
        foreach ($businessByBatch->groupBy(fn ($businessId) => $businessId, true) as $businessId => $batches) {
            DB::table('offcuts')
                ->whereIn('batch_from_id', array_keys($batches->all()))
                ->update(['business_id' => $businessId]);
        }
    }

    /**
     * Marks handed out before this migration could repeat: the dedupe was broken for a time, and the
     * read-then-insert has never been safe against two nests running at once. The index below will
     * not build over those rows, so re-mark all but the oldest of each duplicate set first.
     */
    private function resolveDuplicateMarks(): void
    {
        $duplicates = DB::table('offcuts')
            ->select('business_id', 'product_category', 'unique_mark')
            ->groupBy('business_id', 'product_category', 'unique_mark')
            ->havingRaw('count(*) > 1')
            ->get();

        $generator = new UniqueLetterIDGenerator;

        foreach ($duplicates as $duplicate) {
            $query = DB::table('offcuts')
                ->where('product_category', $duplicate->product_category)
                ->where('unique_mark', $duplicate->unique_mark);

            $duplicate->business_id === null
                ? $query->whereNull('business_id')
                : $query->where('business_id', $duplicate->business_id);

            //The oldest row keeps the mark - it is the one most likely already stamped on steel
            $ids = $query->orderBy('id')->pluck('id')->all();

            foreach (array_slice($ids, 1) as $id) {
                DB::table('offcuts')->where('id', $id)->update([
                    'unique_mark' => $generator->generate(
                        $duplicate->product_category,
                        $duplicate->business_id === null ? null : (int) $duplicate->business_id,
                    ),
                ]);
            }
        }
    }
};
