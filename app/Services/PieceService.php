<?php

namespace App\Services;

use App\Models\Piece;
use App\Models\RawMaterialQuote;
use Illuminate\Database\UniqueConstraintViolationException;

class PieceService
{
    /**
     * The cut this material row asks for.
     *
     * One piece per row, and this is the only path that may mint one - RawMaterialQuote::piece() is a
     * hasOne, the BOM draws one piece per line, and the unique index on pieces.raw_material_quote_id
     * says the same thing in the schema.
     *
     * Written through writePiece() rather than created outright, because the two clarification
     * endpoints take their whole payload from the request body and are re-postable: a double click, a
     * stale tab or a retried request used to mint a SECOND piece for the same line. The BOM could not
     * show it (hasOne reads the first), but NestingFormatter::piecesReadyForBatching reads the pieces
     * table directly, so the nest bought and cut both - a row asking for 3 lengths had 6 cut.
     * Re-submitting now rewrites the one piece instead, which is also what a user correcting their
     * answer means by it.
     *
     * A piece already nested is left exactly as it is: its batch has been costed, ordered and possibly
     * delivered around that spec, and a stale form must not move steel that is already on a truck.
     *
     * @param  array<string, mixed>  $productSpec
     */
    public function createPieceFromProductSpec(array $productSpec, RawMaterialQuote $rawMaterialQuote, string $algo): Piece
    {
        $attributes = [
            //RawMaterialQuote fields
            'project_id' => $rawMaterialQuote->project_id,
            'actual_length' => $rawMaterialQuote->length_required,
            'actual_width' => $rawMaterialQuote->width_required,
            'actual_qty' => $rawMaterialQuote->sub_qty,

            //Other fields
            'nesting_algo' => $algo,

            //Product spec fields
            'product_category' => $productSpec['product_category'] ?? null,
            'material' => $productSpec['material'] ?? null,
            'grade' => $productSpec['grade'] ?? null,
            'surface' => $productSpec['surface'] ?? null,
            'nominal_units' => $productSpec['nominal_units'] ?? null,
            'nominal_length' => $productSpec['nominal_length'] ?? null,
            'precise_length' => $productSpec['precise_length'] ?? null,
            'nominal_width' => $productSpec['nominal_width'] ?? null,
            'precise_width' => $productSpec['precise_width'] ?? null,
            'nominal_height' => $productSpec['nominal_height'] ?? null,
            'precise_height' => $productSpec['precise_height'] ?? null,
            'wall' => $productSpec['wall'] ?? null,
            'kg_per_m' => $productSpec['kg_per_m'] ?? null,
        ];

        return $this->writePiece($rawMaterialQuote, $attributes);
    }

    /**
     * Write the one piece belonging to this material row, whatever its spec came from.
     *
     * Shared with the custom-product endpoint, which builds its spec by hand from the form rather than
     * from a price book match but owes the row exactly the same guarantee.
     *
     * @param  array<string, mixed>  $attributes  everything but raw_material_quote_id
     */
    public function writePiece(RawMaterialQuote $rawMaterialQuote, array $attributes): Piece
    {
        $existing = Piece::query()
            ->where('raw_material_quote_id', $rawMaterialQuote->id)
            ->first();

        //Already nested: the batch was costed and ordered around this spec, so it stands
        if ($existing && $existing->batch_id !== null) {
            return $existing;
        }

        if ($existing) {
            $existing->update($attributes);

            return $existing;
        }

        try {
            return Piece::create($attributes + ['raw_material_quote_id' => $rawMaterialQuote->id]);
        } catch (UniqueConstraintViolationException) {
            /*
             * Two concurrent posts both missed the read above. The index is what actually holds the
             * rule; the loser reads the winner's row rather than failing the request, which is the
             * same answer it would have got a moment earlier.
             */
            return Piece::query()
                ->where('raw_material_quote_id', $rawMaterialQuote->id)
                ->firstOrFail();
        }
    }
}
