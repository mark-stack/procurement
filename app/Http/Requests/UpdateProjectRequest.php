<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $project = $this->route('project');
        $business = $project->user->business;
        $allProjects = $business->projects;
        $allActiveProjectNames = $allProjects
            ->where("name","!=",$project->name)
            ->pluck("name")
            ->toArray();

        return [
            'name' => [
                'required',
                'string',
                Rule::notIn($allActiveProjectNames),
            ],
            'reference' => 'nullable',
            'date_materials_required' => 'nullable|date|after:today',
        ];
    }

    public function messages(): array
    {
        return [
            'name.not_in' => 'Pick a name different to currently active projects',
        ];
    }
}
