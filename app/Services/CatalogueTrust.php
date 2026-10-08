<?php

namespace App\Services;

use App\Enums\GradeEnums;
use App\Enums\MaterialEnums;
use App\Enums\NestingEnums;
use App\Enums\ProductEnums;
use App\Enums\SurfaceEnums;
use App\Models\Product;

/**
 * Which rows of the master catalogue cannot be relied on, and which of those somebody has looked at
 * and deliberately kept.
 *
 * The catalogue is not a list of things to buy. It is the instrument every figure in the application
 * is read through: kg_per_m decides what a millimetre of a section weighs, and that mass is what the
 * tonne price, the offcut valuation, the scrap write-off and half the handling times are derived
 * from - see Services\NestingCostModel. A row whose mass is blank does not fail; it falls back to
 * the business default, a flat 10.0 kg/m that is about right for light angle and four times out on a
 * 500UB. That is the hazard ISO 9001 7.1.5 is asking about, and it was invisible: a product with no
 * mass looked exactly like one with a mass, on every screen, from the day it was imported.
 *
 * So this is the list, and it is deliberately four different kinds of wrong rather than one count:
 *
 *  - NO_MASS is a figure that will be guessed at. It is the one that moves money.
 *  - CERTIFICATES_UNANSWERED is the tri-state nobody has resolved. Null is the honest state for a
 *    row nobody has answered, but where('certificates', true) and where('certificates', false) both
 *    exclude it, so such a product silently drops out of the mill certificate sense check - it is
 *    not asked for and not reported as missing.
 *  - INVALID_VALUE is a column holding the wrong kind of thing: a grade sitting in a material
 *    column, a backtick typed for a 1 in a nominal length. These are worse than blanks, because the
 *    row reads as complete and is matched by nothing.
 *  - NO_DESCRIPTION is the row nobody can find. It nests and orders perfectly well; it is simply
 *    unsearchable, which is how three of them sat in the spreadsheet unnoticed for years.
 *
 * ACTIVE rows only. A deprecated product is excluded from availableForBusiness(), so no nest
 * resolves a mass from one and no BOM line classifies into one - its blanks cost nothing. Including
 * them would bury the rows that matter under a list of retired ones.
 *
 * Accepted rows stay on the list, moved to their own column rather than hidden. The catalogue's only
 * stainless hex bolt carries its grade in its description and always will, and a product nobody
 * grades has no grade because that is the truth about it. A report that could never reach zero is one nobody
 * reads, and one that quietly drops what was accepted cannot say what the acceptance was for.
 */
class CatalogueTrust
{
    /** No mass per metre, so every figure derived from this row rests on the business default. */
    public const NO_MASS = 'no_mass';

    /** Nobody has said whether this product comes with a mill certificate. */
    public const CERTIFICATES_UNANSWERED = 'certificates_unanswered';

    /** A column holding something the rules would now refuse - see invalidValues(). */
    public const INVALID_VALUE = 'invalid_value';

    /** No description at all, so the row is findable only by scrolling to it. */
    public const NO_DESCRIPTION = 'no_description';

    /**
     * Every reason, with what it means on screen. The screen reads this rather than holding a
     * second copy of the wording, so a reason added here appears there without being written twice.
     *
     * @var array<string, array<string, string>>
     */
    public const REASONS = [
        self::NO_MASS => [
            'label' => 'No mass per metre',
            'hint' => 'Costed at the business default instead, which is a flat figure for every '
                .'section. On a heavy beam it is out by a factor of four.',
        ],
        self::CERTIFICATES_UNANSWERED => [
            'label' => 'Mill certificates unanswered',
            'hint' => 'Neither yes nor no, so this product is asked for no certificate and reported '
                .'as missing none.',
        ],
        self::INVALID_VALUE => [
            'label' => 'A column holding the wrong kind of value',
            'hint' => 'A grade in a material column, or a measurement that is not a number. The row '
                .'looks complete and is matched by nothing.',
        ],
        self::NO_DESCRIPTION => [
            'label' => 'No description',
            'hint' => 'Nests and orders normally, but the catalogue search cannot find it and an '
                .'order list has nothing to print against the line.',
        ],
    ];

    /**
     * The columns whose value is checked against an enum, and the enum it has to be one of.
     *
     * @var array<string, class-string<\BackedEnum>>
     */
    private const ENUM_COLUMNS = [
        'product_category' => ProductEnums::class,
        'material' => MaterialEnums::class,
        'grade' => GradeEnums::class,
        'surface' => SurfaceEnums::class,
    ];

    /**
     * Product id => the reasons it carries, for every active platform row that carries any.
     *
     * Memoised for the life of the instance, because the admin screen asks for the counts, then for
     * the ids of one reason to filter by, then for the reasons of each row it renders - three
     * questions about the same 1,100 rows.
     *
     * @var array<int, array<int, string>>|null
     */
    private ?array $flagged = null;

    /** @var array<int, string|null> product id => the reason it was accepted for, or null */
    private array $accepted = [];

    private int $considered = 0;

    /**
     * Why this one row cannot be relied on. Empty when it can.
     *
     * Takes a Product rather than an id so the admin screen can ask it of a row it has already
     * loaded - see Http\Resources\ProductResource, which puts the answer on every row of the page.
     *
     * @return array<int, string>
     */
    public function reasonsFor(Product $product): array
    {
        $reasons = [];

        /*
         * BUNDLE is excluded, and it is most of the catalogue's blanks - 461 of the 492 rows with no
         * mass are bolts, nuts and studs. A fastener is nested by counting packs and is never handed
         * to the cost model at all (see NestingFormatter, where only the meterage path builds one),
         * so a bolt with no kg/m is not an unmeasured resource, it is a resource measured in a
         * different unit. Flagging all 461 would have buried the thirty that matter.
         */
        if ($product->nesting_algo !== NestingEnums::BUNDLE->value && ! ((float) $product->kg_per_m > 0)) {
            $reasons[] = self::NO_MASS;
        }

        //Null only. False is an answer - this product does not come with one
        if ($product->certificates === null) {
            $reasons[] = self::CERTIFICATES_UNANSWERED;
        }

        if ($this->invalidValues($product) !== []) {
            $reasons[] = self::INVALID_VALUE;
        }

        if (trim((string) $product->description) === '') {
            $reasons[] = self::NO_DESCRIPTION;
        }

        return $reasons;
    }

    /**
     * Every column of this product holding something the rules would now refuse.
     *
     * Kept in step with ProductRules on purpose: whatever a JSON import would reject, the catalogue
     * screen already says out loud, so a bad row is never only discoverable by trying to import one.
     *
     * @return array<int, string>
     */
    public function invalidValues(Product $product): array
    {
        $invalid = [];

        foreach (self::ENUM_COLUMNS as $column => $enum) {
            $value = (string) $product->{$column};

            //A blank is inherited, not invalid - the spreadsheet stored one for every unanswered cell
            if ($value === '') {
                continue;
            }

            if ($enum::tryFrom($value) === null) {
                $invalid[] = $column;
            }
        }

        foreach (ProductRules::MEASUREMENTS as $column) {
            $value = $product->{$column};

            if ($value === null || $value === '') {
                continue;
            }

            if (! is_numeric($value)) {
                $invalid[] = $column;
            }
        }

        return $invalid;
    }

    /**
     * The whole report: how many rows were looked at, and what was found in them.
     *
     * Two counts per reason rather than one. "Outstanding" is what is left to do; "accepted" is what
     * somebody has decided to live with and written down why. A single total would mix the two and
     * never move.
     *
     * @return array{
     *     products: int,
     *     outstanding: int,
     *     accepted: int,
     *     reasons: array<int, array<string, mixed>>,
     * }
     */
    public function report(): array
    {
        $flagged = $this->flagged();

        $reasons = [];

        foreach (self::REASONS as $reason => $wording) {
            $outstanding = 0;
            $accepted = 0;

            foreach ($flagged as $id => $carried) {
                if (! in_array($reason, $carried, true)) {
                    continue;
                }

                $this->isAccepted($id) ? $accepted++ : $outstanding++;
            }

            $reasons[] = ['reason' => $reason] + $wording + [
                'outstanding' => $outstanding,
                'accepted' => $accepted,
            ];
        }

        /*
         * Rows, not findings. One product with no mass AND no description is one row to go and look
         * at, and adding the per-reason counts up would say two - which is also why the per-reason
         * figures above deliberately do not sum to these.
         */
        $ids = array_keys($flagged);

        return [
            'products' => $this->considered,
            'outstanding' => count(array_filter($ids, fn (int $id): bool => ! $this->isAccepted($id))),
            'accepted' => count(array_filter($ids, fn (int $id): bool => $this->isAccepted($id))),
            'reasons' => $reasons,
        ];
    }

    /**
     * Just the per-reason tallies, as the catalogue_reviews table stores them.
     *
     * Outstanding only. A review is a record of what was still unresolved when somebody looked, and
     * a row already accepted - with a reason on it, recorded by RecordsChanges when it was written -
     * is resolved. Including it would make the series read as though nothing was ever cleared.
     *
     * @return array<string, int>
     */
    public function outstandingByReason(): array
    {
        $counts = [];

        foreach ($this->report()['reasons'] as $reason) {
            $counts[$reason['reason']] = $reason['outstanding'];
        }

        return $counts;
    }

    /**
     * How many active platform rows the report covers.
     */
    public function productsConsidered(): int
    {
        $this->flagged();

        return $this->considered;
    }

    /**
     * The ids to narrow the catalogue screen to, for one reason or for all of them.
     *
     * Ids rather than a query the caller can chain onto. Three of the four reasons are expressible
     * in SQL and the fourth is not portably - "this measurement is not a number" is a different
     * expression in mysql and in the sqlite the tests run on, and a filter only exercised against
     * one of the two is a filter that breaks in the other. The catalogue is 1,100 rows and the
     * flagged set is a few dozen, so the list is small either way.
     *
     * @return array<int, int>
     */
    public function idsFlaggedAs(?string $reason): array
    {
        $flagged = $this->flagged();

        if ($reason === null || ! isset(self::REASONS[$reason])) {
            return array_keys($flagged);
        }

        return array_keys(array_filter(
            $flagged,
            fn (array $reasons): bool => in_array($reason, $reasons, true),
        ));
    }

    /**
     * Whether this row's flags have been deliberately accepted.
     */
    private function isAccepted(int $id): bool
    {
        return trim((string) ($this->accepted[$id] ?? '')) !== '';
    }

    /**
     * One pass over the active catalogue.
     *
     * Every column the checks read, and nothing else. The whole table at once rather than a query
     * per reason, because the four questions are asked of the same rows and three of them are
     * cheaper to answer in PHP than to express twice.
     *
     * @return array<int, array<int, string>>
     */
    private function flagged(): array
    {
        if ($this->flagged !== null) {
            return $this->flagged;
        }

        $flagged = [];
        $this->considered = 0;

        Product::query()
            ->platformCreated()
            ->active()
            //MEASUREMENTS already names kg_per_m, and a column selected twice is a column
            //that reads as a duplicate the moment somebody adds another to either list
            ->select(array_values(array_unique([
                'id', 'description', 'product_category', 'material', 'grade', 'surface',
                'certificates', 'nesting_algo', 'accepted_reason',
                ...ProductRules::MEASUREMENTS,
            ])))
            ->orderBy('id')
            ->chunk(500, function ($products) use (&$flagged) {
                foreach ($products as $product) {
                    $this->considered++;

                    $reasons = $this->reasonsFor($product);

                    if ($reasons === []) {
                        continue;
                    }

                    $flagged[$product->id] = $reasons;
                    $this->accepted[$product->id] = $product->accepted_reason;
                }
            });

        return $this->flagged = $flagged;
    }
}
