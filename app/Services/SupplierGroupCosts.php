<?php

namespace App\Services;

use App\Enums\SupplierGroupEnums;
use App\Models\Business;

/**
 * Which merchant a product is bought from, and what that merchant charges where it differs.
 *
 * The cost model's premise is that everything is money, and it held one price per tonne for the
 * whole yard. That is right for a yard that buys steel. It is wrong the moment a catalogue holds
 * anything else, and this one has held LVL - timber, bought from a timber merchant, nested by the
 * metre and costed at the steel rate - since before the cost model existed.
 *
 * What makes it worth a seam rather than a special case for timber: the same gap is open for
 * PURLINS, which are a different price per tonne from structural sections and come from a different
 * supplier, and would open again for anything else a fabricator buys by the metre.
 *
 * THE OVERRIDABLE COEFFICIENTS ARE THE MERCHANT'S, NEVER THE YARD'S. That line is the whole design:
 *
 *  - What a tonne costs, what freight costs, what a delivery costs and what the bin pays back are
 *    properties of what is being bought and who it is bought from. They vary by merchant.
 *  - The labour rate and every handling duration are properties of the yard. It is one crew with
 *    one wage, and a bundle of LVL is carried by the same people who carry a beam. A per-merchant
 *    labour rate would be a fiction with a form field.
 *
 * `cut_minutes_per_kg_per_m` is the one that looks like it belongs on the merchant's side and does
 * not. It is already scaled by the section's mass, so a lighter piece already gets a shorter cut -
 * what is left over is how hard the material is on a blade, which is a refinement nobody has asked
 * for and would be wrong to approximate with a merchant.
 *
 * `default_kg_per_m` IS overridable, and it is the one that fixes the live problem on its own. A
 * timber merchant's fallback mass should be a timber mass; at the yard-wide 10.0 every LVL row the
 * catalogue cannot weigh is costed as though it were steel bar.
 */
class SupplierGroupCosts
{
    /**
     * What a merchant charges where nobody has said otherwise.
     *
     * The same kind of figure as NestingCostModel::DEFAULTS and carrying the same warning: A
     * STARTING POINT, NOT A MEASUREMENT OF ANY PARTICULAR YARD. Every one of them is editable per
     * business on the admin nesting page.
     *
     * They exist because the alternative is worse than being approximately right. Left empty, every
     * business - including one created tomorrow - prices timber as steel until somebody notices and
     * fills in a form, and "somebody notices" is exactly what did not happen for the whole life of
     * the LVL rows. A platform default is wrong by a margin; no default was wrong by a factor.
     *
     * TIMBER_MERCHANT, and the reasoning behind each:
     *
     *  - material_cost_per_tonne at $4,100. Timber is not sold by the tonne, which is the real
     *    problem: structural LVL runs about $14 a metre in 90x63, and at 3.4 kg/m that is $4,100 a
     *    tonne. Expressed this way so it goes through the same arithmetic as everything else rather
     *    than needing a second costing path for one material.
     *  - scrap_recovery_rate at 0, and this one is not an estimate. A weighbridge buys metal. No
     *    timber merchant buys LVL offcuts back, and a skip of timber costs tip fees to empty - so
     *    the yard's 13% was crediting money that does not exist, on every drop.
     *  - default_kg_per_m at 3.5, between the catalogue's two LVL sections. The fallback for a
     *    timber row nothing can weigh should be a timber mass, not the yard's 10.0, which is a
     *    steel figure and three times over.
     *
     * No entry for PURLINS, which is the next one that will want one - purlin steel is a different
     * price per tonne from structural sections - because nobody has given a figure for it and an
     * invented one would be indistinguishable from a real one once it is sitting here.
     *
     * @var array<string, array<string, float>>
     */
    public const array PLATFORM_DEFAULTS = [
        'TIMBER_MERCHANT' => [
            'material_cost_per_tonne' => 4100.0,
            'scrap_recovery_rate' => 0.0,
            'default_kg_per_m' => 3.5,
        ],
    ];

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
     * merchant: a yard that types only a timber price keeps the platform's "the bin pays nothing",
     * which is the answer it would have given if asked. Overriding a whole merchant at once would
     * mean filling in two boxes you agree with in order to change the third.
     *
     * @return array<string, float>
     */
    public static function effectiveFor(Business $business, string $supplierGroup): array
    {
        return [
            ...self::PLATFORM_DEFAULTS[$supplierGroup] ?? [],
            ...self::normalise($business->getAttribute('cost_overrides'))[$supplierGroup] ?? [],
        ];
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
