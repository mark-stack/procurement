<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTemplateRequest extends FormRequest
{
    /*
     * A spreadsheet cell reference: up to 3 column letters, up to 4 row digits.
     * "min:2|max:5" used to sit here, which accepted "zz" and "hello".
     */
    private const CELL_REFERENCE = 'regex:/^[A-Za-z]{1,3}[0-9]{1,4}$/';

    /*
     * Roughly 750KB of image. The column is longText so anything fits, but every
     * screenshot is sent down with the index payload, so it needs an upper bound.
     */
    private const SCREENSHOT_MAX_CHARACTERS = 1_000_000;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'first_description_cell' => ['required', 'string', self::CELL_REFERENCE],
            'first_material_cell' => ['nullable', 'string', self::CELL_REFERENCE],
            'first_length_required_cell' => ['nullable', 'string', self::CELL_REFERENCE],
            'first_width_required_cell' => ['nullable', 'string', self::CELL_REFERENCE],
            'first_sub_qty_cell' => ['required', 'string', self::CELL_REFERENCE],
            'screenshot' => [
                'required',
                'string',
                'max:'.self::SCREENSHOT_MAX_CHARACTERS,
                //"min:50" was a length check that any 50 characters passed
                'regex:/^data:image\/(png|jpe?g|gif|webp);base64,[A-Za-z0-9+\/]+=*$/',
            ],
            //The column is enum('m','mm'), so anything else is a 500 at write time
            'length_width_units' => ['required', Rule::in(['m', 'mm'])],
            'active' => ['required', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Template name is required.',
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
