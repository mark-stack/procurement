<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

class UpdateProjectRequest extends FormRequest
{
    /**
     * The controller gate used to be the only check, and it runs after validation -
     * so a project belonging to another business was validated (and its name clashes
     * reported back) before anything refused the request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('owned', $this->route('project')) ?? false;
    }

    public function rules(): array
    {
        $project = $this->route('project');
        $business = $project->user->business;

        /*
         * Was every project the business has ever had, archived ones included, so a
         * name freed up by archiving could be given to a new project but never
         * reached by renaming - under a message saying the opposite.
         *
         * Excluding by id rather than by name: matching on the name also cleared
         * every other project that happened to share it.
         */
        $allActiveProjectNames = $business->projects()
            ->where("projects.archive", false)
            ->where("projects.id", "!=", $project->id)
            ->pluck("projects.name")
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
            'name.not_in' => 'Pick a name different to your other projects - archived ones are free to reuse',
        ];
    }
}
