<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreProjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $user = auth()->user();
        $business = $user->business;
        $allCurrentProjectNames = $business->currentProjects()
            ->pluck("name")
            ->toArray();

        return [
            'name' => [
                'required',
                'string',
                Rule::notIn($allCurrentProjectNames),
            ],
            'reference' => 'nullable',
            'date_materials_required' => 'nullable|date|after:today',
            'tentative' => 'required',
            'excel' => ['required', 'array', 'max:5'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.not_in' => 'Pick a name different to currently active projects',
        ];
    }
}

