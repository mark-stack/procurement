<?php

namespace App\Services;

use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use RuntimeException;

/**
 * Bulk changes to the platform catalogue as reviewed JSON.
 *
 * Built for two jobs:
 *
 *  1. Adding products generated elsewhere - an AI asked for the full RHS range, say - without
 *     hand-typing 200 rows into a form.
 *  2. Moving the catalogue between environments. Export from production, import into local (or the
 *     other way), and the diff says exactly what would change before anything does.
 *
 * Nothing is written until a plan has been produced and the plan has been approved, because the
 * thing this replaces was a button that rewrote all 1,150 rows on one click.
 *
 * ## A row is a whole product, not a patch
 *
 * A column left out of a row is imported as blank, not left alone. That is what makes an export
 * round-trip faithful, and the plan lists every column it would blank so it is never a surprise.
 *
 * ## Why it cannot orphan anything
 *
 * Rows are matched to existing products BY THEIR SPEC - the columns pieces, bars and offcuts find
 * their product through. So a matched row is by definition not changing any of them, and an
 * unmatched row is a new product. There is no import that detaches existing work, which is why this
 * needs no equivalent of the admin form's spec lock.
 */
class MaterialsJsonImport
{
    /**
     * merge: create and update only. Nothing is deprecated for being absent, which is what an
     * additive batch of new sections wants.
     *
     * replace: the payload is the whole catalogue. Anything absent from it is deprecated - never
     * deleted, because deleting a product that pieces or bars are matched on leaves that work
     * matching nothing. This is the mode that syncs one environment to another.
     */
    public const MODES = ['merge', 'replace'];

    public function __construct(private ProductSpec $spec = new ProductSpec) {}

    /**
     * Single purpose: read the uploaded text as a payload, failing with something an author can act
     * on rather than "Syntax error".
     *
     * @return array{mode: string, products: array<int, mixed>}
     */
    public function decode(string $json): array
    {
        $decoded = json_decode($json, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException('That is not valid JSON: '.json_last_error_msg().'.');
        }

        /*
         * A bare array of products is accepted as well as the full envelope. It is the shape anyone
         * asked to "generate some steel products as JSON" will hand back, and refusing it over a
         * missing wrapper would be pedantry.
         */
        if (array_is_list($decoded ?? [])) {
            $decoded = ['products' => $decoded];
        }

        if (! is_array($decoded)) {
            throw new RuntimeException('Expected a JSON object with a "products" array, or an array of products.');
        }

        $mode = $decoded['mode'] ?? 'merge';

        if (! in_array($mode, self::MODES, true)) {
            throw new RuntimeException(sprintf(
                '"mode" must be one of %s, not "%s".',
                implode(' or ', array_map(fn ($m) => '"'.$m.'"', self::MODES)),
                is_scalar($mode) ? $mode : gettype($mode),
            ));
        }

        if (! isset($decoded['products']) || ! is_array($decoded['products'])) {
            throw new RuntimeException('Expected a "products" array. Nothing was imported.');
        }

        if ($decoded['products'] === []) {
            throw new RuntimeException('"products" is empty. Nothing to import.');
        }

        return ['mode' => $mode, 'products' => array_values($decoded['products'])];
    }

    /**
     * Single purpose: say exactly what importing this payload would do, without doing any of it.
     *
     * @param  array{mode: string, products: array<int, mixed>}  $payload
     * @return array<string, mixed>
     */
    public function plan(array $payload): array
    {
        $existing = Product::query()->platformCreated()->get();

        $create = [];
        $update = [];
        $unchanged = 0;
        $errors = [];
        $matchedIds = [];
        //Identity => the row number that claimed it, so two rows for one product are caught
        $claimed = [];

        foreach ($payload['products'] as $index => $row) {
            //1-based, and "row 1" is what an author counting products in a file will look for
            $number = $index + 1;

            if (! is_array($row) || array_is_list($row)) {
                $errors[] = ['row' => $number, 'messages' => ['Expected an object describing one product.']];

                continue;
            }

            $rowErrors = $this->unacceptableKeys($row);

            if ($rowErrors !== []) {
                $errors[] = ['row' => $number, 'messages' => $rowErrors];

                continue;
            }

            /*
             * Every editable column filled in, so a row that omits the measurements its category
             * does not use still satisfies rules that require them to be present. This is also what
             * makes a row a whole product rather than a patch.
             */
            $normalised = ProductRules::blanksToNull([
                ...array_fill_keys(ProductRules::EDITABLE, null),
                'deprecated' => false,
                ...$row,
            ]);

            /*
             * Two passes, for the same reason the admin form has two modes. The catalogue inherited
             * seven LVL rows with no grade at all, and refusing them would mean a full-catalogue
             * sync could never round-trip: exporting production and importing it here would report
             * the seven as errors and import nothing.
             *
             * So a row is first read under the rules for something that already exists, purely to
             * find out whether it does. Only a row that turns out to be NEW then has to satisfy the
             * strict rules - nothing new needs to start out unmatchable.
             */
            $validator = Validator::make(
                $normalised,
                ProductRules::rules(tolerateBlankSpec: true),
            );

            if ($validator->fails()) {
                $errors[] = [
                    'row' => $number,
                    'label' => $this->describeRow($row),
                    'messages' => $validator->errors()->all(),
                ];

                continue;
            }

            $attributes = ProductRules::attributes($validator->validated());
            $category = $attributes['product_category'];

            //Two rows describing the same product would apply in file order, the last one silently winning
            $identity = $category.'|'.$this->spec->fingerprint($attributes, $this->spec->duplicateKeyFor($category));

            if (isset($claimed[$identity])) {
                $errors[] = [
                    'row' => $number,
                    'label' => $this->describeRow($row),
                    'messages' => [sprintf(
                        'Row %d already describes this product. Two rows for one product would apply '
                        .'in file order and only the last would survive.',
                        $claimed[$identity],
                    )],
                ];

                continue;
            }

            $claimed[$identity] = $number;

            $match = $this->match($existing, $attributes, $category);

            if (! $match) {
                //Second pass: this row is a new product, so it has to be a complete one
                $strictErrors = $this->strictErrors($normalised, $attributes, $category);

                if ($strictErrors !== []) {
                    $errors[] = [
                        'row' => $number,
                        'label' => $this->describeRow($row),
                        'messages' => $strictErrors,
                    ];

                    continue;
                }

                $create[] = [
                    'row' => $number,
                    'label' => $this->label($attributes),
                    'attributes' => $attributes,
                ];

                continue;
            }

            $matchedIds[$match->id] = true;

            $changes = $this->changes($match, $attributes);

            if ($changes === []) {
                $unchanged++;

                continue;
            }

            $update[] = [
                'row' => $number,
                'id' => $match->id,
                'label' => $this->label($attributes),
                'changes' => $changes,
                'attributes' => $attributes,
            ];
        }

        /*
         * Deprecated, never deleted. A product absent from a full-catalogue import may still have
         * pieces, bars, offcuts, quotes and orders matched on it, and none of those tables holds a
         * foreign key that would stop the delete - it would simply leave them matching nothing.
         *
         * Nothing is deprecated while any row is invalid. An invalid row never matched a product, so
         * in replace mode the product it was about looks absent from the file - and the catalogue's
         * two worst rows would be deprecated precisely because their replacements could not be read.
         * apply() refuses a plan with errors anyway, so listing those deprecations would only be a
         * claim about something that cannot happen.
         */
        $deprecate = [];

        if ($payload['mode'] === 'replace' && $errors === []) {
            foreach ($existing as $product) {
                if (! isset($matchedIds[$product->id]) && ! $product->deprecated) {
                    $deprecate[] = [
                        'id' => $product->id,
                        'label' => $this->label($product->toArray()),
                        'description' => $product->description,
                    ];
                }
            }
        }

        return [
            'mode' => $payload['mode'],
            'create' => $create,
            'update' => $update,
            'deprecate' => $deprecate,
            'unchanged' => $unchanged,
            'errors' => $errors,
            'counts' => [
                'create' => count($create),
                'update' => count($update),
                'deprecate' => count($deprecate),
                'unchanged' => $unchanged,
                'errors' => count($errors),
            ],
        ];
    }

    /**
     * Single purpose: apply a plan, all of it or none of it.
     *
     * One transaction, because the alternative is a catalogue half way between two versions of
     * itself with no record of where it stopped.
     *
     * @param  array<string, mixed>  $plan
     * @return array<int, string>  What it did, for the admin
     */
    public function apply(array $plan): array
    {
        if ($plan['errors'] !== []) {
            throw new RuntimeException(sprintf(
                '%d rows are invalid. Nothing was imported - fix them and try again.',
                count($plan['errors']),
            ));
        }

        return DB::transaction(function () use ($plan) {
            foreach ($plan['create'] as $entry) {
                Product::create([
                    ...$entry['attributes'],
                    //Platform catalogue, available to every business
                    'business_id' => null,
                ]);
            }

            foreach ($plan['update'] as $entry) {
                Product::query()
                    ->platformCreated()
                    ->whereKey($entry['id'])
                    ->update($entry['attributes']);
            }

            $deprecatedIds = array_column($plan['deprecate'], 'id');

            if ($deprecatedIds !== []) {
                Product::query()
                    ->whereIn('id', $deprecatedIds)
                    ->update(['deprecated' => true]);
            }

            $summary = [
                sprintf('%d products created.', $plan['counts']['create']),
                sprintf('%d products updated.', $plan['counts']['update']),
                sprintf('%d products unchanged.', $plan['counts']['unchanged']),
            ];

            if ($plan['mode'] === 'replace') {
                $summary[] = sprintf(
                    '%d products deprecated for being absent from the file.',
                    count($deprecatedIds),
                );
            }

            return $summary;
        });
    }

    /**
     * Single purpose: a fingerprint of the plan, so applying it cannot apply a different one.
     *
     * The file is re-sent to be applied rather than parked in the session, and the catalogue could
     * have moved under it in between. Both halves are covered: what the file said, and how many
     * products it would touch.
     *
     * @param  array<string, mixed>  $plan
     */
    public function fingerprint(array $plan): string
    {
        return hash('sha256', json_encode([
            $plan['mode'],
            $plan['counts'],
            array_column($plan['create'], 'label'),
            array_column($plan['update'], 'id'),
            array_column($plan['deprecate'], 'id'),
        ]));
    }

    /**
     * Single purpose: what stops a NEW product being created incomplete.
     *
     * A PFC with no nominal height is matched by no piece spec ever built. It offers no stock length
     * to any nest and contributes no mass per metre, while sitting in the catalogue looking complete.
     * Which columns those are depends on the category, so it cannot be a static rule.
     *
     * @param  array<string, mixed>  $normalised
     * @param  array<string, mixed>  $attributes
     * @return array<int, string>
     */
    private function strictErrors(array $normalised, array $attributes, string $category): array
    {
        $validator = Validator::make($normalised, ProductRules::rules(tolerateBlankSpec: false));

        $messages = $validator->fails() ? $validator->errors()->all() : [];

        foreach ($this->spec->keyFor($category) as $column) {
            if (($attributes[$column] ?? null) === null || $attributes[$column] === '') {
                $messages[] = sprintf(
                    'A %s identifies a %s, so a new one without it would be matched by no cut list '
                    .'line at all.',
                    str_replace('_', ' ', $column),
                    $category,
                );
            }
        }

        return array_values(array_unique($messages));
    }

    /**
     * Single purpose: find the product this row is about.
     *
     * By spec, not by description - a corrected description is still the same product, and the old
     * spreadsheet importer's habit of keying on description is what stranded products as deprecated
     * duplicates whenever one was reworded.
     *
     * @param  \Illuminate\Support\Collection<int, Product>  $existing
     * @param  array<string, mixed>  $attributes
     */
    private function match($existing, array $attributes, string $category): ?Product
    {
        $columns = $this->spec->duplicateKeyFor($category);
        $fingerprint = $this->spec->fingerprint($attributes, $columns);

        return $existing->first(fn (Product $product) => $product->product_category === $category
            && $this->spec->fingerprint($product, $columns) === $fingerprint);
    }

    /**
     * Single purpose: the columns this row would change on the product it matched, old value and
     * new, so a blanked column is as visible as a corrected one.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, array{from: mixed, to: mixed}>
     */
    private function changes(Product $product, array $attributes): array
    {
        $changes = [];

        foreach ($attributes as $column => $value) {
            $before = $product->{$column};

            //Compared the way the app compares them, so "2.0" and 2.0 are not reported as a change
            if ($this->spec->canonical($column, $before) === $this->spec->canonical($column, $value)) {
                continue;
            }

            $changes[$column] = ['from' => $before, 'to' => $value];
        }

        return $changes;
    }

    /**
     * Single purpose: refuse keys that would be silently ignored, or that the row is not allowed to
     * decide.
     *
     * A misspelled "kg_per_metre" that imported as a blank weight would be far worse than one that
     * refused the file: the products would look complete and cost as though they weighed nothing.
     *
     * @param  array<string, mixed>  $row
     * @return array<int, string>
     */
    private function unacceptableKeys(array $row): array
    {
        $messages = [];

        $derived = array_intersect(array_keys($row), ProductRules::DERIVED);

        if ($derived !== []) {
            $messages[] = sprintf(
                '%s %s set by the product category, not by the row - remove %s.',
                implode(' and ', $derived),
                count($derived) === 1 ? 'is' : 'are',
                count($derived) === 1 ? 'it' : 'them',
            );
        }

        $unknown = array_diff(
            array_keys($row),
            ProductRules::EDITABLE,
            ProductRules::DERIVED,
        );

        if ($unknown !== []) {
            $messages[] = sprintf(
                'Unrecognised %s: %s. Nothing would be written from %s.',
                count($unknown) === 1 ? 'key' : 'keys',
                implode(', ', $unknown),
                count($unknown) === 1 ? 'it' : 'them',
            );
        }

        return $messages;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function label(array $attributes): string
    {
        return (new ProductService)->getDerivedProductLabel($attributes);
    }

    /**
     * Something to point at in an error message for a row too broken to have a derived label.
     *
     * @param  array<string, mixed>  $row
     */
    private function describeRow(array $row): string
    {
        foreach (['description', 'product_category'] as $column) {
            if (is_scalar($row[$column] ?? null) && (string) $row[$column] !== '') {
                return (string) $row[$column];
            }
        }

        return '(unidentifiable)';
    }
}
