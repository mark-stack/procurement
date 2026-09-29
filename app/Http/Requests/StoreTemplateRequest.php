<?php

namespace App\Http\Requests;

use App\Enums\TemplateEnums;
use App\Enums\TemplateSourceEnums;
use App\Models\Template;
use App\Services\TemplateChecks;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;

class StoreTemplateRequest extends FormRequest
{
    /*
     * A spreadsheet cell reference: up to 3 column letters, then a row number.
     * "min:2|max:5" used to sit here, which accepted "zz" and "hello".
     *
     * The row part is [1-9][0-9]{0,3}, not [0-9]{1,4}: spreadsheet rows start at 1, so
     * "B0" is not a cell, and it used to be stored as one.
     */
    private const CELL_REFERENCE = 'regex:/^[A-Z]{1,3}[1-9][0-9]{0,3}$/';

    /*
     * Roughly 750KB of image. The column is longText so anything fits, but a screenshot
     * this size is worth an upper bound wherever it travels.
     */
    private const SCREENSHOT_MAX_CHARACTERS = 1_000_000;

    /**
     * Every field holding a single cell reference. The seven column cells are named by
     * Template::CELL_FIELDS, which is also where each one's offset into the spec is written down.
     *
     * A method rather than a constant: a constant expression cannot spread an array_keys() call.
     *
     * @return list<string>
     */
    private function singleCellFields(): array
    {
        return [
            'heading_cell',
            'skip_or_finish_check_cell',
            'assembly_mark_cell',
            ...array_keys(Template::CELL_FIELDS),
        ];
    }

    public function authorize(): bool
    {
        return true;
    }

    /**
     * "b7" and "B7" are the same cell, and the record is read by people. Stored verbatim,
     * the table mixed the two - the seeded row used "b28" while the form's own placeholder
     * says "B7" - so the cell rule now only accepts upper case and the input is upper
     * cased before it is checked rather than being rejected for its case.
     *
     * Heading labels are trimmed and emptied out here for the same reason: the form is a
     * repeater, and an untouched row in it is a blank that must not become a label the
     * importer then looks for.
     */
    protected function prepareForValidation(): void
    {
        $normalised = [];

        foreach ($this->singleCellFields() as $field) {
            //Absent stays absent: this must not turn a missing field into a present null
            if ($this->has($field)) {
                $value = $this->input($field);

                $normalised[$field] = is_string($value) ? Str::upper(trim($value)) : $value;
            }
        }

        if ($this->has('compound_description_cells')) {
            $normalised['compound_description_cells'] = $this->cleanedList(
                $this->input('compound_description_cells'),
                fn (string $value) => Str::upper($value),
            );
        }

        if ($this->has('expected_heading_labels')) {
            $normalised['expected_heading_labels'] = $this->cleanedList(
                $this->input('expected_heading_labels'),
                fn (string $value) => $value,
            );
        }

        $this->merge($normalised);
    }

    public function rules(): array
    {
        return [
            'name' => [
                'required',
                'string',
                'max:255',
                /*
                 * Two byte-identical rows, both marked active, used to store fine. The
                 * name is how an admin tells one recorded template from another, so it
                 * has to be unique within the business - and only within it, because two
                 * customers can both import an "Assembly List".
                 */
                $this->uniqueNameRule(),
            ],
            //Which CAD package or person produced the spreadsheet. Recorded, and shown in the list.
            'source' => ['required', Rule::enum(TemplateSourceEnums::class)],
            'type' => ['required', Rule::enum(TemplateEnums::class)],

            /*
             * Detection. These two are the whole of it: the labels find the table anywhere in a
             * sheet, and the heading cell is the origin every column below is measured from.
             */
            'expected_heading_labels' => ['required', 'array', 'min:1', 'max:30'],
            'expected_heading_labels.*' => ['required', 'string', 'max:255'],
            'heading_cell' => ['required', 'string', self::CELL_REFERENCE],

            /*
             * The columns, as cells of the first row of data. All nullable: which ones a real
             * spreadsheet has is not ours to insist on, and a record with no way at all to describe
             * a row is caught in after() instead, where the compound description is also in view.
             */
            'first_description_cell' => ['nullable', 'string', self::CELL_REFERENCE],
            'first_material_cell' => ['nullable', 'string', self::CELL_REFERENCE],
            'first_grade_cell' => ['nullable', 'string', self::CELL_REFERENCE],
            'first_surface_cell' => ['nullable', 'string', self::CELL_REFERENCE],
            'first_length_required_cell' => ['nullable', 'string', self::CELL_REFERENCE],
            'first_width_required_cell' => ['nullable', 'string', self::CELL_REFERENCE],
            'first_sub_qty_cell' => ['nullable', 'string', self::CELL_REFERENCE],

            //Where the table stops, and which rows inside it to pass over
            'skip_or_finish_check_cell' => ['nullable', 'string', self::CELL_REFERENCE],
            'should_skip_row' => ['nullable', 'string', 'max:255'],
            'is_last_data_row' => ['nullable', 'string', 'max:255'],

            //A description built out of several cells, for tables that have no description column
            'compound_description_prefix' => ['nullable', 'string', 'max:50'],
            'compound_description_suffix' => ['nullable', 'string', 'max:50'],
            'compound_description_cells' => ['nullable', 'array', 'max:10'],
            'compound_description_cells.*' => ['required', 'string', self::CELL_REFERENCE],

            'assembly_mark_rule' => ['required', Rule::in(['NONE', 'COLUMN', 'FIXED'])],
            //One cell says both things - which column for COLUMN, which fixed cell for FIXED
            'assembly_mark_cell' => ['nullable', 'string', self::CELL_REFERENCE],

            'screenshot' => $this->screenshotRules(),
            //The column is enum('m','mm'), so anything else is a 500 at write time
            'length_width_units' => ['required', Rule::in(['m', 'mm'])],
            'web_source' => ['nullable', 'url', 'max:2048'],
            'active' => ['required', 'boolean'],
        ];
    }

    /**
     * The checks that need the whole record rather than one field at a time.
     *
     * Only the ones TemplateChecks calls errors are refused here, and an error now means the record
     * could not import anything whatever spreadsheet it met: no heading labels to find a table by,
     * no heading cell to measure from, cells naming three different rows, nothing to read a
     * description out of. Everything else it finds is a warning shown on the screen and never a
     * refusal, because a customer's spreadsheet is allowed to be odd and recording what it actually
     * looks like is the point of the record.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                //A payload that already failed its field rules has nothing coherent to check
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                foreach (TemplateChecks::blocking((new TemplateChecks)->record($this->all())) as $field => $message) {
                    $validator->errors()->add($field, $message);
                }
            },
        ];
    }

    /**
     * Overridden when updating, where an unchanged screenshot is not sent back at all.
     *
     * @return array<int, mixed>
     */
    protected function screenshotRules(): array
    {
        return [
            'required',
            'string',
            'max:'.self::SCREENSHOT_MAX_CHARACTERS,
            //"min:50" was a length check that any 50 characters passed
            'regex:/^data:image\/(png|jpe?g|gif|webp);base64,[A-Za-z0-9+\/]+=*$/',
        ];
    }

    /**
     * Unique within the business in the url, ignoring the row being edited.
     *
     * ignore() is only applied when there is a row to ignore: on store there is none, and
     * ignore(null) builds a condition against a null key.
     */
    private function uniqueNameRule(): Unique
    {
        $business = $this->route('business');

        $rule = Rule::unique('templates', 'name')
            ->where('business_id', $business?->id);

        $template = $this->route('template');

        return $template ? $rule->ignore($template) : $rule;
    }

    /**
     * A repeater's rows, trimmed, with the blanks dropped and the keys renumbered so the ".*" rules
     * and the stored JSON both see a list rather than a sparse array.
     *
     * @param  callable(string): string  $transform
     * @return list<string>
     */
    private function cleanedList(mixed $values, callable $transform): array
    {
        if (! is_array($values)) {
            return [];
        }

        return array_values(array_filter(
            array_map(fn ($value) => is_string($value) ? $transform(trim($value)) : $value, $values),
            fn ($value) => is_string($value) ? $value !== '' : $value !== null,
        ));
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Template name is required.',
            'name.unique' => 'This business already has a template with that name.',
            'source.required' => 'Say which program produced this spreadsheet.',
            'type.required' => 'Say what kind of document this is.',
            'expected_heading_labels.required' => 'Give the heading labels - they are what finds this table in an upload.',
            'expected_heading_labels.min' => 'Give at least one heading label.',
            'heading_cell.required' => 'Give the cell the first heading label sits in, e.g. A6. Every column is measured from it.',
            'heading_cell.regex' => 'Use a cell reference like A6.',
            'first_description_cell.regex' => 'Use a cell reference like B7.',
            'first_material_cell.regex' => 'Use a cell reference like B7.',
            'first_grade_cell.regex' => 'Use a cell reference like B7.',
            'first_surface_cell.regex' => 'Use a cell reference like B7.',
            'first_length_required_cell.regex' => 'Use a cell reference like B7.',
            'first_width_required_cell.regex' => 'Use a cell reference like B7.',
            'first_sub_qty_cell.regex' => 'Use a cell reference like B7.',
            'skip_or_finish_check_cell.regex' => 'Use a cell reference like B7.',
            'assembly_mark_cell.regex' => 'Use a cell reference like B7.',
            'compound_description_cells.*.regex' => 'Use a cell reference like B7.',
            'screenshot.required' => 'Screenshot is required.',
            'screenshot.regex' => 'Screenshot must be a base64 data URL, e.g. data:image/png;base64,...',
            'screenshot.max' => 'Screenshot is too large. Resize it to around 800x500px first.',
            'length_width_units.required' => 'Units for length and width are required.',
            'length_width_units.in' => 'Units must be either m or mm.',
            'active.required' => 'Active status is required.',
        ];
    }
}
