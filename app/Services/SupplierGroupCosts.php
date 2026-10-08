<?php

namespace App\Services;

use App\Enums\SupplierGroupEnums;
use App\Models\Business;

/**
 * Which merchant a product is bought from, and what that merchant charges where it differs.
 *
 * The cost model's premise is that everything is money, and it held one price per tonne for the
 * whole yard. That is right for a yard that buys steel, and wrong the moment a catalogue holds
 * anything else.
 *
 * It was built for timber - the catalogue carried LVL, bought from a timber merchant, nested by the
 * metre and costed at the steel rate. The timber went on 2026-10-08, as too rare to carry for an
 * audience of steel fabricators, and the SEAM stayed, because the same gap is open for PURLINS,
 * which are a different price per tonne from structural sections and come from a different
 * supplier, and would open again for anything else a fabricator buys by the metre.
 *
 * THE OVERRIDABLE COEFFICIENTS ARE THE MERCHANT'S, NEVER THE YARD'S. That line is the whole design:
 *
 *  - What a tonne costs, what freight costs, what a delivery costs and what the bin pays back are
 *    properties of what is being bought and who it is bought from. They vary by merchant.
 *  - The labour rate and every handling duration are properties of the yard. It is one crew with
 *    one wage, and a bundle of purlins is carried by the same people who carry a beam. A
 *    per-merchant labour rate would be a fiction with a form field.
 *
 * `cut_minutes_per_kg_per_m` is the one that looks like it belongs on the merchant's side and does
 * not. It is already scaled by the section's mass, so a lighter piece already gets a shorter cut -
 * what is left over is how hard the material is on a blade, which is a refinement nobody has asked
 * for and would be wrong to approximate with a merchant.
 *
 * `default_kg_per_m` IS overridable, and it was the one that fixed the original problem on its own:
 * a merchant's fallback mass should be a mass of what that merchant sells, and at the yard-wide
 * 10.0 every row the catalogue could not weigh was costed as though it were steel bar.
 */
class SupplierGroupCosts
{
    /**
     * What a merchant charges where nobody has said otherwise.
     *
     * EMPTY, AND CORRECTLY SO. It held one entry, TIMBER_MERCHANT, and it existed because the
     * catalogue carried LVL that was being priced as steel: $4,100 a tonne rather than the yard's
     * steel rate, no scrap recovery because no timber merchant buys offcuts back, and a 3.5 kg/m
     * fallback instead of the yard's 10.0. With the timber gone there is nothing left in the
     * catalogue that is not steel bought from a steel merchant, so every figure here would be an
     * invention.
     *
     * The rule that kept it to one entry is the rule that empties it now: PURLINS is the next group
     * that will want one - purlin steel is a different price per tonne from structural sections -
     * and it has never had an entry because nobody has given a figure for it, and an invented one
     * would be indistinguishable from a real one once it is sitting here.
     *
     * So this staying empty is the seam working, not the seam being unused. A business can still
     * override any merchant's coefficients on the admin nesting page; what is gone is the platform
     * presuming to know one. Anything added here is A STARTING POINT, NOT A MEASUREMENT OF ANY
     * PARTICULAR YARD - the same warning NestingCostModel::DEFAULTS carries.
     *
     * @var array<string, array<string, float>>
     */
    public const array PLATFORM_DEFAULTS = [];

    /**
     * Product category => supplier group, built once per process.
     *
     * Resolving it means constructing every ProductImplementation and reading its config, which is
     * a directory scan - and the cleanout asks this question once per offcut on the rack.
     *
     * @var array<string, string>|null
     */
    private static ?array $groupByCategory = null;

    /**
     * The merchant a product of this category is bought from.
     *
     * Null for a category with no implementation. ProductEnums holds categories that have none
     * (SHS), and a nest of one is already costed on the yard's own figures - returning a group it
     * could not justify would be worse than saying nothing.
     */
    public static function forCategory(?string $category): ?string
    {
        if ($category === null || $category === '') {
            return null;
        }

        return self::map()[strtoupper($category)] ?? null;
    }

    /**
     * Every supplier group, in the order the enum declares them, with what to call one.
     *
     * @return array<int, array<string, string>>
     */
    public static function all(): array
    {
        return array_map(fn (SupplierGroupEnums $group): array => [
            'value' => $group->value,
            'label' => ucwords(strtolower(str_replace('_', ' ', $group->value))),
        ], SupplierGroupEnums::cases());
    }

    /**
     * Whether this names a supplier group the application knows.
     */
    public static function exists(string $group): bool
    {
        return SupplierGroupEnums::tryFrom($group) !== null;
    }

    /**
     * One business's overrides, cleaned up, as group => coefficient => value.
     *
     * Everything unrecognised is dropped rather than refused. This is read on the costing path, and
     * the alternative to ignoring a group that no longer exists - or a coefficient that has since
     * been renamed - is a nest that throws. A retained snapshot makes that a real possibility: it
     * is written once and read back months later, by which time the override set it carries may
     * mention something that has been removed.
     *
     * @param  mixed  $raw  whatever the column or the snapshot holds
     * @return array<string, array<string, float>>
     */
    public static function normalise(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $clean = [];

        foreach ($raw as $group => $coefficients) {
            if (! is_string($group) || ! self::exists($group) || ! is_array($coefficients)) {
                continue;
            }

            foreach ($coefficients as $key => $value) {
                //A null is how the form says "use the yard figure", which is the absence of an override
                if (! is_string($key) || ! in_array($key, NestingCostModel::MERCHANT_COEFFICIENTS, true)) {
                    continue;
                }

                if ($value === null || $value === '' || ! is_numeric($value)) {
                    continue;
                }

                $clean[$group][$key] = (float) $value;
            }
        }

        return $clean;
    }

    /**
     * What one merchant charges this business, platform defaults included.
     *
     * The business's own figures sit ON TOP of the platform's, per coefficient rather than per
     * merchant: a yard that types only a price per tonne keeps whatever the platform says about
     * scrap recovery, which is the answer it would have given if asked. Overriding a whole merchant
     * at once would mean filling in two boxes you agree with in order to change the third.
     *
     * @return array<string, float>
     */
    public static function effectiveFor(Business $business, string $supplierGroup): array
    {
        return [
            ...self::platformDefaultsFor($supplierGroup),
            ...self::normalise($business->getAttribute('cost_overrides'))[$supplierGroup] ?? [],
        ];
    }

    /**
     * What the platform says one merchant charges, which today is nothing for every merchant.
     *
     * Read through here rather than off the constant because PLATFORM_DEFAULTS is empty, and an
     * empty constant is a literal type: static analysis correctly reports that indexing it can
     * never find anything, and would do so at every call site. Widening it once, here, keeps the
     * callers honest about the shape this holds when somebody adds a merchant to it.
     *
     * @return array<string, float>
     */
    public static function platformDefaultsFor(string $supplierGroup): array
    {
        /** @var array<string, array<string, float>> $defaults */
        $defaults = self::PLATFORM_DEFAULTS;

        return $defaults[$supplierGroup] ?? [];
    }

    /**
     * Every merchant this business has an answer for, its own or the platform's.
     *
     * What NestingSettings retains onto a batch - the EFFECTIVE set, not the business's overrides,
     * for the reason it resolves every flat coefficient before storing it: a snapshot that recorded
     * only what the business had typed would silently re-read tomorrow's platform defaults, and a
     * batch nested in March would cost differently in May because the platform changed its mind.
     *
     * @return array<string, array<string, float>>
     */
    public static function effective(Business $business): array
    {
        $groups = [
            ...array_keys(self::PLATFORM_DEFAULTS),
            ...array_keys(self::normalise($business->getAttribute('cost_overrides'))),
        ];

        $effective = [];

        foreach (array_unique($groups) as $group) {
            $set = self::effectiveFor($business, $group);

            if ($set !== []) {
                $effective[$group] = $set;
            }
        }

        return $effective;
    }

    /**
     * @return array<string, string>
     */
    private static function map(): array
    {
        if (self::$groupByCategory !== null) {
            return self::$groupByCategory;
        }

        $map = [];

        foreach ((new ProductService)->getImplementations() as $className) {
            $config = (new $className)->config();

            $map[strtoupper((string) $config['productCategory'])] = $config['supplierGroup']->value;
        }

        return self::$groupByCategory = $map;
    }
}
