<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
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
            /*
             * The modal posts the whole project back, so a project whose materials
             * date has already passed would resubmit that past date and fail
             * "after:today" - leaving the user unable to rename an older project.
             * Only hold a date to the future when it is actually being changed.
             */
            'date_materials_required' => [
                'nullable',
                'date',
                Rule::when(
                    $this->dateMaterialsRequiredChanged(),
                    ['after:today'],
                ),
            ],
        ];
    }

    private function dateMaterialsRequiredChanged(): bool
    {
        $submitted = $this->input('date_materials_required');
        $existing = $this->route('project')->date_materials_required;

        if (blank($submitted) || blank($existing)) {
            return $submitted !== $existing;
        }

        return ! Carbon::parse($submitted)->isSameDay(Carbon::parse($existing));
    }

    public function messages(): array
    {
        return [
            'name.not_in' => 'Pick a name different to currently active projects',
        ];
    }
}
