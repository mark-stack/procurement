<?php

namespace App\Services;

use Illuminate\Support\Collection;

class MasterMaterialsParseResult
{
    /**
     * @param  array<int, array<string, mixed>>  $rows  Rows good enough to import
     * @param  array<int, array{line: int, reason: string}>  $rejected  Rows that could not be imported
     * @param  array<int, array{line: int, reason: string}>  $warnings  Rows imported, but incomplete
     */
    public function __construct(
        public readonly array $rows,
        public readonly array $rejected = [],
        public readonly array $warnings = [],
    ) {}

    public function collection(): Collection
    {
        return collect($this->rows);
    }

    /**
     * Single purpose: a short, admin-readable account of what the file contained, so a
     * partial import is never mistaken for a clean one.
     *
     * @return array<int, string>
     */
    public function messages(): array
    {
        $messages = [sprintf('%d rows read from the spreadsheet.', count($this->rows))];

        if ($this->rejected !== []) {
            $messages[] = sprintf(
                '%d rows skipped: %s',
                count($this->rejected),
                $this->describe($this->rejected),
            );
        }

        if ($this->warnings !== []) {
            $messages[] = sprintf(
                '%d rows imported with blank fields: %s',
                count($this->warnings),
                $this->describe($this->warnings),
            );
        }

        return $messages;
    }

    /**
     * Single purpose: name the first few offending lines rather than listing hundreds.
     */
    private function describe(array $entries): string
    {
        $shown = array_slice($entries, 0, 5);

        $described = implode(', ', array_map(
            fn (array $entry) => sprintf('line %d (%s)', $entry['line'], $entry['reason']),
            $shown,
        ));

        $remaining = count($entries) - count($shown);

        return $remaining > 0
            ? $described.sprintf(' and %d more', $remaining)
            : $described;
    }
}
