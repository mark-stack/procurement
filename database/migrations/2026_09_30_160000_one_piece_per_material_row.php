<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One cut per material row, said in the schema.
     *
     * RawMaterialQuote::piece() has always been a hasOne and the Bill of Materials draws one piece per
     * line, but nothing stopped a second one being written - and the two clarification endpoints take
     * their whole payload from the request body, so a double click, a stale tab or a retried request
     * minted one. The BOM could not show the duplicate (hasOne reads the first) while
     * NestingFormatter::piecesReadyForBatching reads the pieces table directly, so the nest bought and
     * cut both: a row asking for three lengths had six cut and paid for.
     *
     * PieceService is the only writer now and writes through this index. The index is what makes that
     * race-safe rather than merely careful - without it there is no unique violation to catch.
     */
    public function up(): void
    {
        $this->mergeDuplicatePieces();

        Schema::table('pieces', function (Blueprint $table) {
            $table->unique('raw_material_quote_id', 'pieces_raw_material_quote_id_unique');
        });
    }

    public function down(): void
    {
        Schema::table('pieces', function (Blueprint $table) {
            $table->dropUnique('pieces_raw_material_quote_id_unique');
        });
    }

    /**
     * Drop the duplicates this used to leave behind, keeping the one that is furthest into the
     * business's own process.
     *
     * A piece that is on an order, or on a batch, is one the yard has costed and possibly bought
     * against; its twin is the accident. Where neither is committed the older row wins, because that
     * is the one every other screen has been reading. The losers are deleted rather than detached -
     * they are not steel anybody asked for, and leaving them un-nested would simply put them in the
     * next batch.
     */
    private function mergeDuplicatePieces(): void
    {
        $duplicatedRowIds = DB::table('pieces')
            ->select('raw_material_quote_id')
            ->groupBy('raw_material_quote_id')
            ->havingRaw('count(*) > 1')
            ->pluck('raw_material_quote_id');

        foreach ($duplicatedRowIds as $rawMaterialQuoteId) {
            $pieces = DB::table('pieces')
                ->where('raw_material_quote_id', $rawMaterialQuoteId)
                ->orderBy('id')
                ->get(['id', 'batch_id', 'order_id']);

            $keep = $pieces->first(fn ($piece) => $piece->order_id !== null)
                ?? $pieces->first(fn ($piece) => $piece->batch_id !== null)
                ?? $pieces->first();

            $discard = $pieces
                ->reject(fn ($piece) => $piece->id === $keep->id)
                ->pluck('id')
                ->all();

            //piece_quote has no cascade, and a pivot row pointing at a deleted piece is a broken join
            DB::table('piece_quote')->whereIn('piece_id', $discard)->delete();
            DB::table('pieces')->whereIn('id', $discard)->delete();
        }
    }
};
