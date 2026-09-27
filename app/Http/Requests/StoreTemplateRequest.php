<?php

namespace App\Http\Requests;

use App\Models\Template;
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

    /*
     * The five fields that hold a cell reference. Listed once because they are normalised
     * together and share a rule.
     */
    private const CELL_FIELDS = [
        'first_description_cell',
        'first_material_cell',
        'first_length_required_cell',
        'first_width_required_cell',
        'first_sub_qty_cell',
    ];

    public function authorize(): bool
    {
        return true;
    }

    /**
     * "b7" and "B7" are the same cell, and the record is read by people. Stored verbatim,
     * the table mixed the two - the seeded row used "b28" while the form's own placeholder
     * says "B7" - so the cell rule now only accepts upper case and the input is upper
     * cased before it is checked rather than being rejected for its case.
     */
    protected function prepareForValidation(): void
    {
        $normalised = [];

        foreach (self::CELL_FIELDS as $field) {
            //Absent stays absent: this must not turn a missing field into a present null
            if ($this->has($field)) {
                $value = $this->input($field);

                $normalised[$field] = is_string($value) ? Str::upper($value) : $value;
            }
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
            /*
             * Which entry in config/TableTemplates.php this row documents. Required, so a
             * new row cannot be unattributable the way every existing one is.
             */
            'source' => ['required', 'string', Rule::in($this->configuredSources())],
            'config_label' => [
                'required',
                'string',
                Rule::in($this->configuredLabels()),
                //The label has to belong to the source, not merely exist somewhere
                function (string $attribute, mixed $value, callable $fail) {
                    if (! $this->pairIsConfigured((string) $this->input('source'), (string) $value)) {
                        $fail('That template is not one of the entries in config/TableTemplates.php.');
                    }
                },
            ],
            'first_description_cell' => ['required', 'string', self::CELL_REFERENCE],
            'first_material_cell' => ['nullable', 'string', self::CELL_REFERENCE],
            'first_length_required_cell' => ['nullable', 'string', self::CELL_REFERENCE],
            'first_width_required_cell' => ['nullable', 'string', self::CELL_REFERENCE],
            'first_sub_qty_cell' => ['required', 'string', self::CELL_REFERENCE],
            'screenshot' => $this->screenshotRules(),
            //The column is enum('m','mm'), so anything else is a 500 at write time
            'length_width_units' => ['required', Rule::in(['m', 'mm'])],
            'active' => ['required', 'boolean'],
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
     * @return array<int, string>
     */
    private function configuredSources(): array
    {
        return array_values(array_unique(array_column(Template::detectionOptions(), 'source')));
    }

    /**
     * @return array<int, string>
     */
    private function configuredLabels(): array
    {
        return array_values(array_unique(array_column(Template::detectionOptions(), 'config_label')));
    }

    private function pairIsConfigured(string $source, string $label): bool
    {
        foreach (Template::detectionOptions() as $option) {
            if ($option['source'] === $source && $option['config_label'] === $label) {
                return true;
            }
        }

        return false;
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Template name is required.',
            'name.unique' => 'This business already has a template with that name.',
            'source.required' => 'Say which detection entry this template is.',
            'source.in' => 'That source is not one in config/TableTemplates.php.',
            'config_label.required' => 'Say which detection entry this template is.',
            'config_label.in' => 'That template is not one of the entries in config/TableTemplates.php.',
            'first_description_cell.required' => 'First description cell is required.',
            'first_sub_qty_cell.required' => 'First sub quantity cell is required.',
            'first_description_cell.regex' => 'Use a cell reference like B7.',
            'first_material_cell.regex' => 'Use a cell reference like B7.',
            'first_length_required_cell.regex' => 'Use a cell reference like B7.',
            'first_width_required_cell.regex' => 'Use a cell reference like B7.',
            'first_sub_qty_cell.regex' => 'Use a cell reference like B7.',
            'screenshot.required' => 'Screenshot is required.',
            'screenshot.regex' => 'Screenshot must be a base64 data URL, e.g. data:image/png;base64,...',
            'screenshot.max' => 'Screenshot is too large. Resize it to around 800x500px first.',
            'length_width_units.required' => 'Units for length and width are required.',
            'length_width_units.in' => 'Units must be either m or mm.',
            'active.required' => 'Active status is required.',
        ];
    }
}
