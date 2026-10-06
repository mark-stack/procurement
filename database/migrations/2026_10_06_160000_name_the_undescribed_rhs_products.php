<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * The three RHS rows the spreadsheet left with no description.
     *
     * 75x50x2.0, 75x50x2.5 and 75x50x5.0, all of them the 12,000mm stock length. Each has an 8,000mm
     * twin sitting directly above it that IS described, and the twin's description - "75x50x2.0 RHS"
     * - names no length, so the two were always meant to read the same. Somebody filled the column
     * down the 8m block and stopped.
     *
     * Not cosmetic. description is the only column the catalogue screen searches on
     * (AdminMaterialIndexController), it is what the order list prints against a line of steel, and
     * it is the handle a person uses to say which product they mean. A row without one is findable
     * only by scrolling to it. Nesting is unaffected either way: pieces and bars match on the spec
     * columns, and the label the app shows is derived from those - which is why these three have
     * been orderable and nestable all along while being unsearchable.
     *
     * Built from each row's own dimensions rather than written out three times, in the format its
     * siblings already use: height x width x wall, one decimal on the wall, then the category. Only
     * a blank description is touched, so a row somebody has since named keeps its name, and
     * re-running this changes nothing.
     */
    public function up(): void
    {
        $undescribed = DB::table('products')
            ->whereNull('business_id')
            ->where('product_category', 'RHS')
            ->where(fn ($query) => $query->whereNull('description')->orWhereRaw("TRIM(description) = ''"))
            ->get(['id', 'nominal_height', 'nominal_width', 'wall']);

        foreach ($undescribed as $product) {
            /*
             * A row missing one of the three dimensions would produce "75xx2.0 RHS", which is worse
             * than the blank it replaces. None of the three are, but this runs against whatever a
             * given environment's catalogue actually holds.
             */
            if (! is_numeric($product->nominal_height) || ! is_numeric($product->nominal_width) || ! is_numeric($product->wall)) {
                continue;
            }

            DB::table('products')->where('id', $product->id)->update([
                'description' => sprintf(
                    '%sx%sx%s RHS',
                    (int) $product->nominal_height,
                    (int) $product->nominal_width,
                    number_format((float) $product->wall, 1),
                ),
            ]);
        }
    }

    /**
     * Deliberately not reversible.
     *
     * The previous value was a blank, and restoring it would mean this migration's only effect in
     * reverse is to delete three descriptions - including from a row somebody has since corrected
     * by hand. There is nothing here worth being able to undo.
     */
    public function down(): void {}
};
