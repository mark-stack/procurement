<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string'],
            'first_description_cell' => ['required', 'string', 'min:2', 'max:5'],
            'first_material_cell' => ['nullable', 'string', 'min:2', 'max:5'],
            'first_length_required_cell' => ['nullable', 'string', 'min:2', 'max:5'],
            'first_width_required_cell' => ['nullable', 'string', 'min:2', 'max:5'],
            'first_sub_qty_cell' => ['required', 'string', 'min:2', 'max:5'],
            'screenshot' => ['required', 'string', 'min:50'],
            'length_width_units' => ['required', 'string'],
            'active' => 'required',
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Template name is required.',
            'first_description_cell.required' => 'First description cell is required.',
            'first_sub_qty_cell.required' => 'First sub quantity cell is required.',
            'screenshot.required' => 'Screenshot is required.',
            'screenshot.min' => 'Screenshot must be a valid base64-encoded string (minimum 50 characters).',
            'length_width_units.required' => 'Units for length and width are required.',
            'active.required' => 'Active status is required.',
        ];
    }
}
