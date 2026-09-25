<?php

namespace App\Formatters;
use App\Models\Offcut;

class UniqueLetterIDGenerator
{
    private $letters = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
    private $currentLength = 3;
    private $usedCodes = [];

    public string $productCategory;

    public function generate(string $productCategory): string
    {
        /**
         * This is unique mark reference for a given business and material group.
         * e.g It counts UB's for XYZ business.
         * 3 letters, then adds 1 character when all combinations reached.
         * So "GYX", then later "YSKD".
         * This gets 17,576 at 3 letters, 456,976 at 4 letters, 11,881,376 at 5 characters
         */

        /*
         * Get all marks for this product category.
         *
         * Flipped so the marks are the KEYS. pluck() hands back a list, and the lookup below is an
         * isset() by key - so against a list it was asking "is there a mark at offset 'GYX'", which is
         * never true. Every mark was therefore handed out without being checked against the ones
         * already stamped on steel, and needsToGrow() was measuring strlen() of the list's integer
         * offsets instead of the codes.
         */
        $this->usedCodes = Offcut::query()
            ->where("product_category",$productCategory)
            ->pluck("unique_mark")
            ->flip()
            ->all();
        $this->adjustLength();

        while (true) {
            $code = $this->randomLetters($this->currentLength);

            if (!isset($this->usedCodes[$code])) {
                $this->usedCodes[$code] = true;
                return $code;
            }

            // Optional: adjust if needed during generation
            if ($this->needsToGrow()) {
                $this->currentLength++;
            }
        }
    }

    private function randomLetters(int $length): string
    {
        $result = '';
        $maxIndex = strlen($this->letters) - 1;

        for ($i = 0; $i < $length; $i++) {
            $result .= $this->letters[random_int(0, $maxIndex)];
        }

        return $result;
    }

    private function needsToGrow(): bool
    {
        $maxCombinations = pow(strlen($this->letters), $this->currentLength);
        $usedCount = 0;
        foreach ($this->usedCodes as $code => $value) {
            if (strlen($code) === $this->currentLength) {
                $usedCount++;
            }
        }
        return $usedCount >= $maxCombinations;
    }

    private function adjustLength(): void
    {
        // Check during construction: if all codes at current length are used, bump up
        while ($this->needsToGrow()) {
            $this->currentLength++;
        }
    }
}
