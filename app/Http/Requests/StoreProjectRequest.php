<?php

namespace App\Http\Requests;

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
            'name' => [
                'required',
                'string',
                Rule::notIn($allCurrentProjectNames),
            ],
            'reference' => 'nullable',
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
