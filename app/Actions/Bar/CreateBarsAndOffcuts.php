<?php

namespace App\Actions\Bar;

use App\Enums\NestingEnums;
use App\Formatters\UniqueLetterIDGenerator;
use App\Models\Bar;
use App\Models\Batch;
use App\Models\Cut;
use App\Models\Offcut;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Lorisleiva\Actions\Concerns\AsAction;

class CreateBarsAndOffcuts
{
    use AsAction;

    /**
     * Tries at writing an offcut before giving up on finding it a free mark. Each retry rolls a new
     * code, so reaching the end means the pool is genuinely contended, not that we were unlucky.
     */
    private const MARK_ATTEMPTS = 5;

    public function handle(array $piecesNested, Batch $batch, array $lettersProjectArray = []): void
    {
        /*
         * Marks are unique per business and product category, and one generator serves the whole nest
         * so that a mark issued for the first bar is not offered again for the second.
         */
        $businessId = $batch->user?->business_id;
        $markGenerator = new UniqueLetterIDGenerator;

        /**
         * Create bars and offcuts
         *
         * Only meterage has bars and offcuts, but the whole nest is serialised so that bundle and area
         * materials survive onto the batch too.
         */
        $meterageNesting = $piecesNested[NestingEnums::METERAGE->value] ?? collect([]);

        if($meterageNesting->count() > 0){
            foreach($meterageNesting as $index => $product){
                /*
                 * Utilised bars
                 */
                $utilisedBars = $product->nested['utilisedBars'];
                foreach($utilisedBars as $indexUtilisedBar => $utilisedBar){
                    $unused = $utilisedBar["result"]["unused"];
                    $threshold = $utilisedBar["result"]["scrap_threshold_mm"];

                    /*
                     * "count" identical bars each produce their own offcut, so collect every id rather
                     * than writing one key that each pass overwrites.
                     */
                    $offcutIds = [];
                    $uniqueMarks = [];

                    for ($i = 1; $i <= $utilisedBar["count"]; $i++) {
                        /*
                         * Create bar
                         * todo "BAR" is not fully implemented yet. It will replace serialization in the future
                         */
                        $bar = Bar::create([
                            //Batch that cut it - without this the bar survives the batch being unwound
                            'batch_id' => $batch->id,

                            //Product attributes
                            'product_category' => $product->product_category,       //PFC
                            'material' => $product->material ?? null,               //PLAIN CARBON STEEL
                            'grade' => $product->grade ?? null,                     //GR250
                            'surface' => $product->surface ?? null,                 //NONE
                            'nominal_length' => $product->nominal_length ?? null,   //9000
                            'precise_length' => $product->precise_length ?? null,   //
                            'nominal_width' => $product->nominal_width ?? null,     //
                            'precise_width' => $product->precise_width ?? null,     //
                            'nominal_height' => $product->nominal_height ?? null,   //200
                            'precise_height' => $product->precise_height ?? null,   //
                            'wall' => $product->wall ?? null,                       //

                            //Other
                            "product_derived_label" => $product->product_derived_label, //200PFC
                            "length" => $utilisedBar["result"]["bar_length"],
                        ]);

                        /*
                         * Record the cuts this particular bar carries.
                         *
                         * "count" identical bars share one cut plan but not one set of parts, so the
                         * ids come from this bar's own entry in pieceIdSets - the i-th bar gets the
                         * i-th set. Pairing them with result.pieces by index is sound because that is
                         * what consolidation grouped on: two bars are only in the same group if their
                         * cut lists are identical, so the lengths are the same list in the same order.
                         *
                         * This is the row that makes a part traceable. Without it the chain ran
                         * piece -> order -> certificates, which is a set; with it the chain runs
                         * piece -> cut -> bar -> heat number, which is an answer.
                         */
                        $this->recordCuts(
                            $batch,
                            $utilisedBar["pieceIdSets"][$i - 1] ?? [],
                            $utilisedBar["result"]["pieces"] ?? [],
                            barId: $bar->id,
                        );

                        //Should make offcut
                        if($unused >= $threshold){
                            //Create offcut, with a mark no other offcut of this business and category holds
                            $offcut = $this->createOffcutWithMark($markGenerator, $product->product_category, $businessId, [
                                //Batch
                                'batch_from_id' => $batch->id,
                                'batch_to_id' => null,

                                //Business that owns it - the mark is only unique within this scope
                                'business_id' => $businessId,

                                //Piece
                                'piece_to_id' => null, //todo this is not assigned anywhere as of yet, so its pointless

                                //Bar
                                "bar_id" => $bar->id,

                                //Product attributes
                                'product_category' => $product->product_category,
                                'material' => $product->material ?? null,
                                'grade' => $product->grade ?? null,
                                'surface' => $product->surface ?? null,
                                'nominal_length' => $product->nominal_length ?? null,
                                'precise_length' => $product->precise_length ?? null,
                                'nominal_width' => $product->nominal_width ?? null,
                                'precise_width' => $product->precise_width ?? null,
                                'nominal_height' => $product->nominal_height ?? null,
                                'precise_height' => $product->precise_height ?? null,
                                'wall' => $product->wall ?? null,
                                'length' => $unused,
                            ]);

                            $offcutIds[] = $offcut->id;
                            $uniqueMarks[] = $offcut->unique_mark;
                        }
                    }

                    //Add IDs to serialised nesting data
                    if(count($offcutIds) > 0){
                        $nested = $meterageNesting[$index]->nested;
                        $nested["utilisedBars"][$indexUtilisedBar]["result"]["offcut_id"] = $offcutIds[0];
                        $nested["utilisedBars"][$indexUtilisedBar]["result"]["offcut_ids"] = $offcutIds;
                        $nested["utilisedBars"][$indexUtilisedBar]["result"]["unique_mark"] = $uniqueMarks[0];
                        $nested["utilisedBars"][$indexUtilisedBar]["result"]["unique_marks"] = $uniqueMarks;
                        $meterageNesting[$index]->nested = $nested;
                    }
                }

                /*
                 * Offcuts
                 */
                $bestResultOffcuts = $product->nested['bestResultOffcuts'];
                if(count($bestResultOffcuts['utilisedOffcutBars']) > 0){
                    //Loop individual offcuts
                    foreach($bestResultOffcuts['utilisedOffcutBars'] as $indexOffcut => $offcutData) {
                        /*
                         * Assign source offcut to batch
                         */
                        $offcut = Offcut::find($offcutData["sourceOffcut"]["offcutId"]);
                        if ($offcut) {
                            /*
                             * Somebody took this piece out of inventory between the nest being
                             * suggested and it being saved - see Offcut::removeFromInventory. The nest
                             * is a cut plan built around it, so unpicking it here would leave the
                             * batch's totals unbalanced; it is consumed as planned and the
                             * contradiction is said out loud instead, because the yard is about to go
                             * looking for steel somebody has already reported gone.
                             */
                            if ($offcut->isRemoved()) {
                                Log::warning('A nest consumed an offcut that had been removed from inventory', [
                                    'offcut_id' => $offcut->id,
                                    'unique_mark' => $offcut->unique_mark,
                                    'batch_id' => $batch->id,
                                    'removed_reason' => $offcut->removed_reason,
                                ]);
                            }

                            $offcut->batch_to_id = $batch->id;
                            $offcut->save();

                            /*
                             * The cuts taken out of this offcut.
                             *
                             * The offcut side of the nest has always carried a piece_id on every cut, so
                             * there is nothing to thread through here - it only needed writing down. A
                             * cut recorded against an offcut inherits that offcut's ancestry for its
                             * certificate trail, which is what Cut::originBar walks back up to find the
                             * bar the steel was originally rolled as.
                             */
                            $cuts = $offcutData["sourceOffcut"]["cuts"] ?? [];

                            $this->recordCuts(
                                $batch,
                                array_map(fn ($cut) => $cut["piece_id"] ?? null, $cuts),
                                $cuts,
                                offcutId: $offcut->id,
                            );
                        }
                        else {
                            Log::error("Offcut with ID wasn't found: ", [$offcutData["sourceOffcut"]["offcutId"]]);
                        }

                        /*
                         * Create offcut-of-offcut
                         */
                        $offcutOfOffcutLength = $offcutData["offcutFromOffcut"]["reusableLength"];
                        if($offcutOfOffcutLength > 0){
                            //Create offcut, with a mark no other offcut of this business and category holds
                            $offcutOfOffcut = $this->createOffcutWithMark($markGenerator, $product->product_category, $businessId, [
                                //Batch
                                'batch_from_id' => $batch->id,
                                'batch_to_id' => null,

                                //Business that owns it - the mark is only unique within this scope
                                'business_id' => $businessId,

                                //Piece
                                'piece_to_id' => null, //todo this is not assigned anywhere as of yet, so its pointless

                                //Bar
                                //Cut from an offcut, not from a bar, so the provenance goes in offcut_from_id.
                                //bar_id resolves against the "bars" table, so an offcut id here reads back
                                //as an unrelated bar (or as nothing, which used to 500 the offcuts page).
                                "bar_id" => null,
                                "offcut_from_id" => $offcutData["sourceOffcut"]["offcutId"],

                                //Product attributes
                                'product_category' => $product->product_category,
                                'material' => $product->material ?? null,
                                'grade' => $product->grade ?? null,
                                'surface' => $product->surface ?? null,
                                'nominal_length' => $product->nominal_length ?? null,
                                'precise_length' => $product->precise_length ?? null,
                                'nominal_width' => $product->nominal_width ?? null,
                                'precise_width' => $product->precise_width ?? null,
                                'nominal_height' => $product->nominal_height ?? null,
                                'precise_height' => $product->precise_height ?? null,
                                'wall' => $product->wall ?? null,
                                'length' => $offcutOfOffcutLength,
                            ]);

                            //Add ID to serialised nesting data
                            $nested = $meterageNesting[$index]->nested;
                            $nested["bestResultOffcuts"]["utilisedOffcutBars"][$indexOffcut]["offcutFromOffcut"]["offcut_of_offcut_id"] = $offcutOfOffcut->id;
                            $nested["bestResultOffcuts"]["utilisedOffcutBars"][$indexOffcut]["offcutFromOffcut"]["unique_mark"] = $offcutOfOffcut->unique_mark;
                            $meterageNesting[$index]->nested = $nested;
                        }
                    }
                }
            }
        }

        /*
         * Save nested_state (the NestedState cast encodes it as JSON).
         * Every algo is kept, not just meterage, so bundle and area materials stay visible on the batch.
         */
        $nestedState = [];
        foreach($piecesNested as $algo => $items){
            $nestedState[$algo] = $items instanceof Collection
                ? $items->toArray()
                : $items;
        }
        $nestedState[NestingEnums::METERAGE->value] = $meterageNesting->toArray();

        $batch->nested_state = $nestedState;

        /*
         * Keep the letter map that was stamped onto these cuts. Recomputing it when the batch is read
         * back orders the projects differently, so the legend disagreed with the drawings.
         */
        $batch->letters_project_array = $lettersProjectArray;

        $batch->save();
    }

    /**
     * Write one cuts row per part taken out of a bar or an offcut.
     *
     * Two callers with two cut shapes, which is why the length is read from either key: the new stock
     * side calls its measurement "cutLength" (addNewStockBar) and the offcut side calls it "length"
     * (nestRequiredCutsIntoOffcuts). Normalising here rather than renaming one of them keeps the saved
     * nested_state readable by every screen that already parses it.
     *
     * Rows with no piece id are skipped rather than written with a null. A cut that cannot name its part
     * traces nothing, and a traceability table whose rows may or may not mean anything is one nobody can
     * rely on - better to be short and honest, which is what Batch::offcutOrdersWithCertificates already
     * does when it reports the certificates material "could have come from". In practice this skips
     * nothing: both nesting paths carry an id for every cut. It is the guard that keeps that true.
     *
     * @param  array<int, int|null>  $pieceIds  the parts on this bar, in cut order
     * @param  array<int, array<string, mixed>>  $cuts  the same cuts, carrying their lengths
     */
    private function recordCuts(
        Batch $batch,
        array $pieceIds,
        array $cuts,
        ?int $barId = null,
        ?int $offcutId = null,
    ): void {
        $cuts = array_values($cuts);

        foreach (array_values($pieceIds) as $index => $pieceId) {
            if ($pieceId === null) {
                continue;
            }

            $cut = $cuts[$index] ?? [];

            Cut::create([
                'piece_id' => (int) $pieceId,
                'batch_id' => $batch->id,
                'bar_id' => $barId,
                'offcut_id' => $offcutId,
                'length' => (float) ($cut['cutLength'] ?? $cut['length'] ?? 0),
            ]);
        }
    }

    /**
     * Write an offcut, stamping it with a mark no other offcut of this business and product category
     * holds.
     *
     * The mark is chosen by reading the marks already taken, and the whole nest runs inside a
     * transaction (QuoteController), so offcuts another business is writing at this moment are
     * invisible to that read. The unique index on (business_id, product_category, unique_mark) is
     * what actually stops two bars in the yard wearing the same mark; this catches the loser of that
     * race and rolls again. The generator has already reserved the code that lost, so the retry
     * cannot come back with it.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function createOffcutWithMark(
        UniqueLetterIDGenerator $markGenerator,
        string $productCategory,
        ?int $businessId,
        array $attributes,
    ): Offcut {
        $attempt = 0;

        while (true) {
            $attempt++;

            try {
                return Offcut::create($attributes + [
                    'unique_mark' => $markGenerator->generate($productCategory, $businessId),
                ]);
            } catch (UniqueConstraintViolationException $exception) {
                if ($attempt >= self::MARK_ATTEMPTS) {
                    throw $exception;
                }

                Log::warning('Offcut mark was taken between generating it and writing it, retrying', [
                    'business_id' => $businessId,
                    'product_category' => $productCategory,
                    'attempt' => $attempt,
                ]);
            }
        }
    }
}
