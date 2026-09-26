<?php

namespace App\Formatters;

use App\Models\Offcut;

class UniqueLetterIDGenerator
{
    /**
     * Confusable glyphs are deliberately absent: I/1, O/0, Q/O, S/5, Z/2 and U/V.
     *
     * A mark is written on the steel by hand and read back by eye in the yard, so a code like "ZIQ"
     * is three misreads in one. The 20 letters left still give 8,000 three-letter marks per business
     * and product category, and 160,000 at four.
     */
    public const ALPHABET = 'ABCDEFGHJKLMNPRTVWXY';

    /** Shortest mark handed out. */
    public const MIN_LENGTH = 3;

    /**
     * Never stamped on a customer's steel. Checked as a substring, so it holds at four letters too.
     * Most of what would otherwise belong here is already unreachable: the alphabet has no I, O, S
     * or U. Extend the list freely - it costs one str_contains per candidate.
     */
    private const BLOCKED = ['FAG', 'FAP', 'GAY', 'JAP', 'KKK', 'WAP', 'WTF'];

    /**
     * Random probes before falling back to a deterministic sweep of what is left.
     */
    private const MAX_PROBES = 50;

    /**
     * Coprime with 20^n for every n, so stepping by it visits every index in the space exactly once.
     */
    private const SWEEP_STRIDE = 7919;

    /**
     * Marks handed out by THIS instance, keyed by scope. generate() re-reads the table on every call,
     * so without this a mark issued a moment ago is invisible until the caller persists its offcut -
     * and the same mark could be handed out twice in one nest.
     *
     * @var array<string, array<string, true>>
     */
    private array $reserved = [];

    /**
     * A unique mark for one business and product category. e.g. it counts UB's for XYZ business.
     *
     * 3 letters, growing by one when every combination at that length is taken: 8,000 at 3 letters,
     * 160,000 at 4, 3,200,000 at 5.
     *
     * Scoped by business because an offcut is only ever visible to the business that cut it (see
     * Business::availableOffcuts) - there is no point spending XYZ's pool on another yard's steel.
     * $businessId is nullable so that offcuts with no business (their batch or user is gone) keep a
     * pool of their own rather than silently sharing everyone else's.
     */
    public function generate(string $productCategory, ?int $businessId = null): string
    {
        $scope = $businessId.'|'.$productCategory;

        /*
         * Marks already stamped on steel in this scope, as KEYS - the lookup below is an isset() by
         * key, so a list would answer "is there a mark at offset 'GYX'", which is never true.
         *
         * Consumed offcuts (batch_to_id set) are deliberately included. A mark is never recycled, so
         * paperwork naming one always names the same piece of steel.
         */
        $query = Offcut::query()->where('product_category', $productCategory);

        $businessId === null
            ? $query->whereNull('business_id')
            : $query->where('business_id', $businessId);

        $used = $query->pluck('unique_mark')->flip()->all();

        //Marks this instance has already issued but the caller may not have written yet
        $used += $this->reserved[$scope] ?? [];

        //Blocked codes occupy the space like any other taken mark, so growth accounts for them
        foreach (self::BLOCKED as $blocked) {
            $used[$blocked] = true;
        }

        $code = $this->pickUnused($used, $this->lengthFor($used));

        $this->reserved[$scope][$code] = true;

        return $code;
    }

    /**
     * The index'th code of the given length, counting in base-20 over the alphabet.
     */
    public static function codeFromIndex(int $index, int $length): string
    {
        $base = strlen(self::ALPHABET);
        $code = '';

        for ($position = 0; $position < $length; $position++) {
            $code = self::ALPHABET[$index % $base].$code;
            $index = intdiv($index, $base);
        }

        return $code;
    }

    /**
     * @param  array<string, true>  $used
     */
    private function pickUnused(array $used, int $length): string
    {
        $combinations = $this->combinations($length);

        //While the pool is mostly empty this lands on the first or second roll
        for ($probe = 0; $probe < self::MAX_PROBES; $probe++) {
            $code = self::codeFromIndex(random_int(0, $combinations - 1), $length);

            if ($this->isAvailable($code, $used)) {
                return $code;
            }
        }

        /*
         * Nearly full. Rolling more dice from here is a coupon-collector problem - the last few codes
         * cost thousands of attempts each, and the old generator rescanned the whole used set on
         * every miss. Sweep instead, from a random start in a stride coprime with the space, so every
         * index is visited exactly once and the walk still starts somewhere unpredictable.
         */
        $start = random_int(0, $combinations - 1);

        for ($step = 0; $step < $combinations; $step++) {
            $code = self::codeFromIndex(($start + $step * self::SWEEP_STRIDE) % $combinations, $length);

            if ($this->isAvailable($code, $used)) {
                return $code;
            }
        }

        //Everything at this length is taken or blocked. Grow rather than spin.
        return $this->pickUnused($used, $length + 1);
    }

    /**
     * @param  array<string, true>  $used
     */
    private function isAvailable(string $code, array $used): bool
    {
        if (isset($used[$code])) {
            return false;
        }

        foreach (self::BLOCKED as $blocked) {
            if (str_contains($code, $blocked)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string, true>  $used
     */
    private function lengthFor(array $used): int
    {
        $length = self::MIN_LENGTH;

        while ($this->countAtLength($used, $length) >= $this->combinations($length)) {
            $length++;
        }

        return $length;
    }

    /**
     * @param  array<string, true>  $used
     */
    private function countAtLength(array $used, int $length): int
    {
        $count = 0;

        foreach ($used as $code => $ignored) {
            $code = (string) $code;

            /*
             * Only codes this generator could produce fill the space. Marks stamped before the
             * alphabet dropped its confusable letters are still honoured as taken - they just do not
             * count towards "the three-letter pool is full".
             */
            if (strlen($code) === $length && strspn($code, self::ALPHABET) === $length) {
                $count++;
            }
        }

        return $count;
    }

    private function combinations(int $length): int
    {
        return (int) pow(strlen(self::ALPHABET), $length);
    }
}
