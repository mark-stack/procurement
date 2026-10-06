<?php

namespace App\Http\Requests;

use App\Services\NestingCostModel;
use App\Services\NestingSettings;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * The figures a yard nests on, on their way in.
 *
 * Every coefficient in the cost model had a column, a documented default and an explainer page, and
 * no way to change it. Nothing in the application wrote one: the admin page was a GET, there was no
 * form anywhere else, and so every installation ran on the same $2,000 a tonne, the same $50 an
 * hour, 0mm of kerf and - the one that actually bites - no freight at all. The model's own
 * documentation says a business "opts in by setting its real freight" and says freight is the main
 * lever on how quickly a remnant gets written off. There was no opt-in to make.
 *
 * EDITING THESE IS SAFE TO DO AT ANY TIME, which is why the write can exist now and could not have
 * before. A nest keeps the figures it was run on (Services\NestingSettings), the scrap ledger prices
 * an old batch against that snapshot, and a measurement records which it used - so changing a
 * coefficient changes what the NEXT nest decides and restates nothing that has already happened.
 *
 * The field list is taken from the cost model and the nesting keys rather than written out again.
 * Two lists would drift the moment a coefficient was added, and the half that drifted would be this
 * one - a new dial that silently cannot be edited looks exactly like a dial nobody has touched.
 */
class UpdateNestingSettingsRequest extends FormRequest
{
    /**
     * Upper bounds, where a figure has one worth stating.
     *
     * Not an opinion about what a yard should charge. These are the points past which a value is
     * certainly a typo - a tonne of steel at six figures, a saw cut that takes a working day - and
     * the cost of letting one through is a nest that quietly stops making sense rather than an
     * error anybody sees.
     */
    private const CEILINGS = [
        'scrap_threshold_mm' => 12000,
        'kerf_mm' => 50,
        'labour_rate_per_hour' => 1000,
        'material_cost_per_tonne' => 100000,
        'delivery_cost_per_tonne' => 100000,
        'delivery_cost_per_order' => 100000,
        'scrap_recovery_rate' => 1,
        'default_kg_per_m' => 2000,
        'offcut_retention_cap' => 1,
        'purchase_cost_weight' => 10,
    ];

    /**
     * Minutes are all bounded the same way: a whole shift on one operation is not a coefficient.
     */
    private const MINUTE_CEILING = 480;

    /**
     * Every setting that may be written, which is every setting a nest is retained with.
     *
     * @return array<int, string>
     */
    public static function writableKeys(): array
    {
        return [...NestingSettings::NESTING_KEYS, ...array_keys(NestingCostModel::DEFAULTS)];
    }

    public function authorize(): bool
    {
        //The route is behind AdminMiddleware; nothing further is asked of the request itself
        return true;
    }

    /**
     * @return array<string, array<int, mixed>|string>
     */
    public function rules(): array
    {
        $rules = [];

        foreach (self::writableKeys() as $key) {
            $rules[$key] = [
                'required',
                ...$this->rulesFor($key),
            ];
        }

        return $rules;
    }

    /**
     * @return array<int, string>
     */
    private function rulesFor(string $key): array
    {
        $ceiling = self::CEILINGS[$key] ?? (str_contains($key, 'minutes') ? self::MINUTE_CEILING : 1000);

        /*
         * The two lengths are whole millimetres. Everything else is a rate or a duration and is
         * allowed its decimals - 0.06 minutes per kg/m is a real coefficient.
         */
        if (in_array($key, NestingSettings::NESTING_KEYS, true)) {
            return ['integer', 'min:'.$this->floorFor($key), 'max:'.$ceiling];
        }

        return ['numeric', 'min:'.$this->floorFor($key), 'max:'.$ceiling];
    }

    /**
     * What a setting may not go below.
     *
     * Zero for almost everything, because a yard that genuinely pays nothing for freight or recovers
     * nothing from the bin should be able to say so. The exceptions are the three where zero is not
     * a cheaper yard but a model that has stopped working:
     *
     *  - scrap_threshold_mm at 0 says every offcut is worth banking, a 50mm one included, which is
     *    how a rack fills with material nobody will ever pick up. It is also the one setting here
     *    that changes what physically happens in the yard rather than which plan is chosen.
     *  - default_kg_per_m at 0 makes every section weigh nothing, so steel is free and every
     *    section takes the same time to handle - the cost model guards against this already, and
     *    being refused at the form is better than being silently overridden.
     *  - purchase_cost_weight at 0 makes bought steel free, and a nest that can buy for nothing
     *    will never cut into inventory again.
     */
    private function floorFor(string $key): string
    {
        return match ($key) {
            'scrap_threshold_mm' => '1',
            'default_kg_per_m' => '0.01',
            'purchase_cost_weight' => '0.01',
            default => '0',
        };
    }

    public function after(): array
    {
        return [
            fn (Validator $validator) => $this->guardRetentionAgainstPurchase($validator),
        ];
    }

    /**
     * The one constraint between two of these that has to hold.
     *
     * Retained value is V(L) = cap x L x sqrt((L-t)/(ref-t)), so its slope rises to 1.5 x cap at a
     * full stock length. Once that marginal value reaches the purchase weight, buying one more
     * millimetre of bar and racking it pays for itself - and the nest starts buying steel in order
     * to bank it. At a cap of 0.98 it bought a 12,000mm bar to put a 2,500mm cut in and racked the
     * other 8,000mm, over two 9,000mm bars that covered the same cuts for 3,000mm less.
     *
     * Refused here rather than reported afterwards, which is what the explainer page does with the
     * same arithmetic. The two settings are individually reasonable and only wrong together, so the
     * message has to name both or an admin will put the one they did not touch back where it was.
     *
     * See NestingCostModel::retention, which is where this is derived.
     */
    private function guardRetentionAgainstPurchase(Validator $validator): void
    {
        $cap = (float) $this->input('offcut_retention_cap');
        $purchase = (float) $this->input('purchase_cost_weight');

        if (1.5 * $cap < $purchase) {
            return;
        }

        $validator->errors()->add('offcut_retention_cap', sprintf(
            'A retention cap of %s puts a racked millimetre\'s marginal value at %s, which is not under the purchase weight of %s - so the nest would buy steel in order to bank the remainder. Lower the cap below %s, or raise the purchase weight above %s.',
            rtrim(rtrim(number_format($cap, 3), '0'), '.'),
            rtrim(rtrim(number_format(1.5 * $cap, 3), '0'), '.'),
            rtrim(rtrim(number_format($purchase, 3), '0'), '.'),
            rtrim(rtrim(number_format($purchase / 1.5, 3), '0'), '.'),
            rtrim(rtrim(number_format(1.5 * $cap, 3), '0'), '.'),
        ));
    }

    /**
     * The validated settings, as the columns want them.
     *
     * @return array<string, float|int>
     */
    public function settings(): array
    {
        $settings = [];

        foreach (self::writableKeys() as $key) {
            $value = $this->validated()[$key];

            $settings[$key] = in_array($key, NestingSettings::NESTING_KEYS, true)
                ? (int) $value
                : (float) $value;
        }

        return $settings;
    }
}
