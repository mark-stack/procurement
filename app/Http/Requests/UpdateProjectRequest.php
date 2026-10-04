<?php

namespace App\Http\Requests;

use App\Models\Project;
use App\PrerequisiteConditions\PrerequisiteConditions;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

class UpdateProjectRequest extends FormRequest
{
    /**
     * The controller gate used to be the only check, and it runs after validation -
     * so a project belonging to another business was validated (and its name clashes
     * reported back) before anything refused the request.
     *
     * The gate answers "is this my business", which is every colleague's project in the
     * shared Nesting column. Editing is the owner's call, the same as marking a project done: see
     * PrerequisiteConditions::editProject for why the two belong together.
     */
    public function authorize(): bool
    {
        $user = $this->user();
        $project = $this->route('project');

        if (! $user || ! $project) {
            return false;
        }

        return $user->can('owned', $project)
            && (new PrerequisiteConditions())->editProject($user, $project);
    }

    /**
     * Same reason as StoreProjectRequest::prepareForValidation - renaming is the other way to give a
     * project a name that is blank, or padded, everywhere the business reads it.
     */
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('name'))) {
            $this->merge(['name' => trim($this->input('name'))]);
        }

        if (is_string($this->input('reference'))) {
            $this->merge(['reference' => trim($this->input('reference')) ?: null]);
        }
    }

    public function rules(): array
    {
        $project = $this->route('project');
        $business = $project->user->business;

        /*
         * Was every project the business has ever had, ones marked done included, so a
         * name freed up that way could be given to a new project but never
         * reached by renaming - under a message saying the opposite.
         *
         * Excluding by id rather than by name: matching on the name also cleared
         * every other project that happened to share it.
         */
        $allActiveProjectNames = $business->projects()
            ->where("projects.done", false)
            ->where("projects.id", "!=", $project->id)
            ->pluck("projects.name")
            ->toArray();

        return [
            //Bounded here as well as on create - see the constants on the Project model
            'name' => [
                'required',
                'string',
                'max:'.Project::MAX_NAME_CHARACTERS,
                Rule::notIn($allActiveProjectNames),
            ],
            'reference' => ['nullable', 'string', 'max:'.Project::MAX_REFERENCE_CHARACTERS],
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
            /*
             * Required when the project is created, nullable here - so a mistyped fabrication date can
             * be corrected, and a project created before anybody was asked can be given one, without
             * the rename form refusing to save until a date is invented for it. See the migration that
             * adds the column for why those older projects carry none.
             */
            'date_fabrication_begins' => ['nullable', 'date'],
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
            'name.not_in' => 'Pick a name different to your other projects - ones marked done are free to reuse',
        ];
    }
}
