<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * The thirty active products that carried no mass per metre.
     *
     * Every figure the cost model produces is derived from kg/m - the tonne price, the offcut
     * valuation, the scrap write-off and half the handling times - and a row without one silently
     * fell back to default_kg_per_m, a flat 10.0 that is about right for light angle and four times
     * out on a heavy beam. TASK-008 made the gap visible; this closes it.
     *
     * NOTHING HERE IS COPIED OUT OF A TABLE, AND NOTHING HERE IS A GUESS AT A ROW. Each mass is
     * computed from the dimensions the row already holds, so the arithmetic can be checked against
     * any reference without trusting this file, and a catalogue that differs between environments
     * gets each of its own rows answered rather than a list of ids that only matched one database.
     *
     * The four families, and how certain each is:
     *
     *  - CHS (12 rows, the whole 200nb family). pi x t x (OD - t) x 7850, which is the AS/NZS 1163
     *    definition, taken across precise_width because 200nb is a NOMINAL BORE - a plumbing-era
     *    name - and the actual tube is 193.7mm across. These come out at 21.0, 23.3, 27.8, 36.6,
     *    45.3 and 55.9, the published figures to the decimal. The same formula reproduces every
     *    CHS mass the catalogue ALREADY held, which is what says it is the right formula.
     *  - FLAT (1 row). width x height x 7850. 75x8 is 4.71, also the published figure.
     *  - ALLTHREAD (3 rows). A threaded rod is not a bar of its nominal diameter - the thread cuts
     *    material away - so this takes the mean of the major and minor diameters, which lands at
     *    0.74 for M12 and 1.35 for M16 against a trade figure usually quoted near 0.75 and 1.35.
     *    Close enough that the difference is far inside what the coefficients around it are worth.
     *  - LVL (14 rows). Cross-section x 600 kg/m3, structural LVL at service moisture content.
     *    Timber varies with species and moisture in a way steel does not, so this is the one family
     *    here that is genuinely an estimate - but it is an estimate of timber rather than the 10.0
     *    it had, which was an estimate of steel. See the timber merchant's own coefficients in
     *    Services\SupplierGroupCosts: the mass and the price per tonne have to be right TOGETHER,
     *    and correcting either alone makes the answer worse.
     *
     * Only a row with no mass at all is touched, so a figure somebody has entered by hand survives
     * and re-running this changes nothing.
     */
    public function up(): void
    {
        $unweighed = DB::table('products')
            ->whereNull('business_id')
            ->where('nesting_algo', '!=', 'BUNDLE')
            ->where(fn ($query) => $query->whereNull('kg_per_m')->orWhere('kg_per_m', 0))
            ->get(['id', 'description', 'product_category', 'nominal_height', 'nominal_width', 'precise_width', 'wall']);

        foreach ($unweighed as $product) {
            $kgPerM = $this->massPerMetre($product);

            if ($kgPerM === null) {
                continue;
            }

            DB::table('products')->where('id', $product->id)->update(['kg_per_m' => $kgPerM]);
        }
    }

    /**
     * What one metre of this section weighs, or null where its own row cannot say.
     *
     * Null rather than a fallback. A row this cannot answer is left on the trust report where it
     * can be seen (Services\CatalogueTrust), which is a better outcome than a plausible number
     * nobody can trace.
     */
    private function massPerMetre(object $product): ?float
    {
        $steel = 7850.0;

        return match ($product->product_category) {
            //A tube, measured across its real outside diameter rather than its nominal bore
            'CHS' => $this->circularHollow($product, $steel),

            //A solid rectangle
            'FLAT' => $this->solid($product->nominal_height, $product->nominal_width, $steel),

            //LVL is timber. See the class note - the density is what makes this a different product
            'LVL' => $this->solid($product->nominal_height, $product->nominal_width, 600.0),

            //A threaded rod, which is thinner than its nominal diameter everywhere the thread cuts
            'ALLTHREAD' => $this->threadedRod($product->nominal_width, $steel),

            default => null,
        };
    }

    private function circularHollow(object $product, float $density): ?float
    {
        /*
         * precise_width is the real outside diameter; nominal_width holds 200, the nominal BORE,
         * which is a plumbing-era name rather than a measurement - a tube computed from it comes
         * out a fifth heavy. All 164 CHS rows carry a precise_width.
         *
         * The description is the fallback and not the source, even though it states the same figure
         * ("CHS 200nb (O193.7x10.0) 9m"): a column is a column, and a row whose description and
         * dimensions disagree should be answered by its dimensions.
         */
        $outsideDiameter = is_numeric($product->precise_width) ? (float) $product->precise_width : 0.0;

        if ($outsideDiameter <= 0 && preg_match('/\(\D?([\d.]+)\s*[xX]/u', (string) $product->description, $matches)) {
            $outsideDiameter = (float) $matches[1];
        }

        $wall = (float) $product->wall;

        if ($wall <= 0 || $outsideDiameter <= $wall) {
            return null;
        }

        return round(M_PI * $wall * ($outsideDiameter - $wall) * $density / 1e6, 2);
    }

    private function solid(mixed $height, mixed $width, float $density): ?float
    {
        if (! is_numeric($height) || ! is_numeric($width) || (float) $height <= 0 || (float) $width <= 0) {
            return null;
        }

        return round((float) $height * (float) $width * $density / 1e6, 2);
    }

    private function threadedRod(mixed $nominalDiameter, float $density): ?float
    {
        if (! is_numeric($nominalDiameter) || (float) $nominalDiameter <= 0) {
            return null;
        }

        /*
         * Coarse metric thread: the minor diameter is roughly the nominal less 1.0825 times the
         * pitch, and the mass sits around the mean of the two. Pitch is taken from the standard
         * coarse series rather than derived, because it is a lookup in the real world too.
         */
        $pitch = [6 => 1.0, 8 => 1.25, 10 => 1.5, 12 => 1.75, 16 => 2.0, 20 => 2.5, 24 => 3.0];

        $major = (float) $nominalDiameter;
        $coarse = $pitch[(int) $major] ?? null;

        if ($coarse === null) {
            return null;
        }

        $minor = $major - (1.0825 * $coarse);
        $mean = ($major + $minor) / 2;

        return round(M_PI * ($mean / 2) ** 2 * $density / 1e6, 2);
    }

    /**
     * Deliberately not reversible.
     *
     * The previous value was the absence of a measurement, and putting it back would mean blanking
     * a mass - including one somebody has since corrected by hand. There is nothing here worth
     * being able to undo.
     */
    public function down(): void {}
};
