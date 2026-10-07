<?php

namespace App\Services;

use App\Models\Business;
use Illuminate\Support\Str;

/**
 * Proof that a template was run over a real spreadsheet and passed, carried between the two requests
 * that need it.
 *
 * Creating a template is gated on its test passing. The test and the save are separate requests, so
 * the save has to be told that the test happened - and it cannot take the browser's word for it: a
 * disabled button is a courtesy, not a gate, and the one thing a gate on this form must not be is
 * skippable by whoever is in a hurry.
 *
 * So a passing test hands back a token, and the save only accepts one that was issued for the values
 * being saved. It is an HMAC of those values under the application key rather than a row in a table:
 * nothing about a test that was run and not saved is worth keeping, and a token that is only valid
 * for one exact set of cell references cannot be re-used for another.
 *
 * What it covers is only what decides the extraction. The name, the screenshot, whether it is live
 * and where the format is documented are all deliberately outside it: none of them changes a single
 * row of what the importer reads, and an admin who tests a template and then types its name must not
 * be sent back to upload the sample again.
 */
class TemplateTestCertificate
{
    /**
     * Cell references. Upper cased before they are signed for the same reason the request upper cases
     * them: "b7" and "B7" are the same cell, and a token must not turn on which one was typed.
     */
    private const CELL_FIELDS = [
        'heading_cell',
        'first_description_cell',
        'first_material_cell',
        'first_grade_cell',
        'first_surface_cell',
        'first_length_required_cell',
        'first_width_required_cell',
        'first_sub_qty_cell',
        'skip_or_finish_check_cell',
        'assembly_mark_cell',
    ];

    /**
     * Everything else that changes what comes out of a spreadsheet.
     *
     * The skip and stop rules are signed verbatim, case and all: "Total" and "total" are two
     * different rules to the importer, so they have to be two different fingerprints here.
     */
    private const TEXT_FIELDS = [
        'should_skip_row',
        'is_last_data_row',
        'compound_description_prefix',
        'compound_description_suffix',
        'assembly_mark_rule',
        'length_width_units',
    ];

    /**
     * The repeaters. Heading labels are matched literally, so they are signed as written; compound
     * description cells are cell references and are upper cased with the rest.
     */
    private const LIST_FIELDS = [
        'expected_heading_labels' => false,
        'compound_description_cells' => true,
    ];

    /**
     * The token a passing test hands back.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function issue(Business $business, array $attributes): string
    {
        return hash_hmac('sha256', $this->fingerprint($business, $attributes), $this->secret());
    }

    /**
     * Whether this token was issued for exactly these values, for this business.
     *
     * Bound to the business because the verdict on every row is: the descriptions are matched against
     * that business's own catalogue, and the plan it is on decides which products it may buy at all.
     * A template that imports six sections for one customer can import nothing for the next.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function matches(mixed $token, Business $business, array $attributes): bool
    {
        if (! is_string($token) || $token === '') {
            return false;
        }

        return hash_equals($this->issue($business, $attributes), $token);
    }

    /**
     * Whether these two sets of values would be read out of a spreadsheet identically.
     *
     * What this answers is "does saving this change what the importer does", which is the question
     * an edit has to ask: correcting a template's name is not something to make somebody upload a
     * sample for, and moving its heading cell is. Both sets go through the same canonicalisation as
     * a token, so the answer cannot turn on a trailing space or on which case a cell was typed in.
     *
     * @param  array<string, mixed>  $left
     * @param  array<string, mixed>  $right
     */
    public function sameExtraction(Business $business, array $left, array $right): bool
    {
        return hash_equals($this->fingerprint($business, $left), $this->fingerprint($business, $right));
    }

    /**
     * The values being signed, in one canonical string.
     *
     * Canonical matters more than readable here: the test signs the form as it posts it and the save
     * verifies the form as it posts it, so anything that the two requests could spell differently -
     * a trailing space, a missing key, an empty repeater sent as nothing at all - has to be flattened
     * to the same thing first or a passing test would be refused at the save for no reason a human
     * could see.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function fingerprint(Business $business, array $attributes): string
    {
        $canonical = ['business' => $business->id];

        foreach (self::CELL_FIELDS as $field) {
            $canonical[$field] = $this->text($attributes[$field] ?? null, true);
        }

        foreach (self::TEXT_FIELDS as $field) {
            $canonical[$field] = $this->text($attributes[$field] ?? null);
        }

        foreach (self::LIST_FIELDS as $field => $upper) {
            $canonical[$field] = $this->list($attributes[$field] ?? null, $upper);
        }

        return (string) json_encode($canonical);
    }

    /**
     * One value, trimmed, with blank and absent both landing on null - Inertia posts an empty string
     * for a null field when the form carries a file, and a nullable cell left alone is both.
     */
    private function text(mixed $value, bool $upper = false): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        return $upper ? Str::upper($value) : $value;
    }

    /**
     * A repeater's rows, blanks dropped and keys renumbered. An empty repeater does not travel in
     * form data at all, so absent and [] have to fingerprint identically.
     *
     * @return list<string>
     */
    private function list(mixed $values, bool $upper): array
    {
        if (! is_array($values)) {
            return [];
        }

        return array_values(array_filter(
            array_map(fn ($value) => $this->text($value, $upper), $values),
            fn (?string $value) => $value !== null,
        ));
    }

    /**
     * The application key. It is already the secret every signed thing in this app leans on, and a
     * token that outlives a key rotation is not a token anybody wanted.
     */
    private function secret(): string
    {
        return (string) config('app.key');
    }
}
