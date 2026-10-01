<?php

namespace App\Http\Requests;

use App\Models\Project;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreProjectRequest extends FormRequest
{
    /**
     * Keep in step with the limits the modal advertises
     * (resources/js/Components/Modals/NewProjectModal.vue).
     */
    public const MAX_FILES = 5;

    public const MAX_FILE_KILOBYTES = 1024;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * Trim before validating, not after.
     *
     * The modal trims the name on its way out because a project saved as "   " is blank everywhere
     * it is displayed. Anything posting straight at the route skipped that, and "required" is happy
     * with a string of spaces - so the guard lived only in the browser. Doing it here also means the
     * duplicate-name check compares what will actually be stored.
     */
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('name'))) {
            $this->merge(['name' => trim($this->input('name'))]);
        }

        //A reference of nothing but spaces is no reference, and the card only draws it when it is set
        if (is_string($this->input('reference'))) {
            $this->merge(['reference' => trim($this->input('reference')) ?: null]);
        }
    }

    public function rules(): array
    {
        $user = auth()->user();
        $business = $user->business;
        /*
         * Every live project of the business, read directly. This used to go through
         * Business::currentProjects(), which only reached projects that already had
         * pieces in an active batch - a project being created has none, so the guard
         * never fired and duplicate names went straight through. That method and the
         * inverted scope behind it have since been deleted.
         */
        $allCurrentProjectNames = $business->projects()
            ->where("projects.archive", false)
            ->pluck("projects.name")
            ->toArray();

        return [
            /*
             * "max" and a typed reference, both of which were missing. The modal puts no maxlength on
             * either input and never renders the reference field at all, so the only limit on what
             * reached the database was whatever the poster felt like sending - see the constants on
             * the Project model for what each one costs.
             */
            'name' => [
                'required',
                'string',
                'max:'.Project::MAX_NAME_CHARACTERS,
                Rule::notIn($allCurrentProjectNames),
            ],
            'reference' => ['nullable', 'string', 'max:'.Project::MAX_REFERENCE_CHARACTERS],
            /*
             * Which colleague this job is for, when somebody is uploading on their behalf - the
             * draftsman case this whole column exists for (see the migration adding
             * created_by_user_id). Absent, or your own id, means the plain case.
             *
             * Scoped to the business, not just to "a user that exists": this id decides who owns a
             * project, who is reminded about its deadline and who may archive it, so an arbitrary id
             * would hand a stranger a project - and hide it from the person who uploaded it, since
             * their own board is drawn from their business's users.
             */
            'project_manager_id' => [
                'nullable',
                'integer',
                Rule::exists('users', 'id')->where('business_id', $business->id),
            ],
            'date_materials_required' => 'nullable|date|after:today',
            'tentative' => 'required|boolean',
            'excel' => ['required', 'array', 'min:1', 'max:'.self::MAX_FILES],
            /*
             * Every one of these was previously enforced in the browser only - the
             * modal's accept attribute, its file counter and its size check. Anything
             * posting straight at the route could hand Excel::toArray an executable
             * of any size.
             */
            'excel.*' => [
                'required',
                'file',
                'mimes:xls,xlsx',
                'max:'.self::MAX_FILE_KILOBYTES,
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'name.not_in' => 'Pick a name different to your other projects - archived ones are free to reuse',
            'project_manager_id.exists' => 'Pick a project manager from your own company.',
            'excel.max' => 'Maximum '.self::MAX_FILES.' BOM files can be uploaded.',
            'excel.*.mimes' => 'Each material list must be an Excel file (.xls or .xlsx).',
            'excel.*.max' => 'Each material list must be under 1Mb.',
        ];
    }

    public function attributes(): array
    {
        return [
            'excel' => 'material lists',
            'excel.*' => 'material list',
        ];
    }
}
