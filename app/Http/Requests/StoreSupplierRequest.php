<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreSupplierRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string',
            'supplier_categories' => [
                'required',
                'array',
                function ($attribute, $value, $fail) {
                    if (! in_array(true, $value, true)) {
                        $fail('Select at least ONE category');
                    }
                },
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'The supplier name is required.',
            'supplier_categories.required' => 'At least one category must be selected.',
        ];
    }
}

