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
             * Nullable only for a project that has no fabrication date to lose.
             *
             * It is required on the way in (StoreProjectRequest), and it was nullable here so that a
             * project created before anybody was asked for one could be given one without the rename
             * form refusing to save - see the migration that adds the column. But nullable both ways
             * meant a date could also be *deleted*, and deleting it is not a correction: it is the
             * one edit that silences everything built on it. The card loses its required-by pill, its
             * critical path and the footer telling somebody to act; the fabrication deadline warning
             * stops selecting the project (FabricationDeadlineQuoting::triggerProject skips a project
             * with no date); and the two deadline reminders stop considering it. A red card can be
             * cleared by emptying the field that made it red.
             *
             * So: a project that has one must keep one, and a project that has none is still free to
             * be given one or left alone.
             */
            'date_fabrication_begins' => [
                /*
                 * "sometimes", because the hole is sending the field empty, not leaving it out. A
                 * request that never mentions the date is not changing it - validated() will not
                 * carry the key and the column is left alone - and plain "required" would refuse
                 * every caller that posts nothing but a new name.
                 */
                'sometimes',
                $project->date_fabrication_begins ? 'required' : 'nullable',
                'date',
                /*
                 * And it stops moving once the steel is in - see
                 * PrerequisiteConditions::moveFabricationDate. Written as a rule on the change rather
                 * than on the field, because the modal posts the whole project back on a rename: held
                 * to the field, nobody could fix a typo in the name of a finished job.
                 */
                Rule::when(
                    $this->fabricationDateChanged() && ! $this->mayMoveFabricationDate(),
                    ['prohibited'],
                ),
            ],
        ];
    }

    /**
     * Is the fabrication date actually being changed by this request?
     *
     * Compared by day, like dateMaterialsRequiredChanged below and for the same reason: the modal is
     * handed "2026-11-02" and the column reads "2026-11-02 00:00:00", so a plain comparison would
     * call every rename a date change.
     */
    private function fabricationDateChanged(): bool
    {
        $submitted = $this->input('date_fabrication_begins');
        $existing = $this->route('project')->date_fabrication_begins;

        if (blank($submitted) || blank($existing)) {
            return blank($submitted) !== blank($existing);
        }

        return ! Carbon::parse($submitted)->isSameDay(Carbon::parse($existing));
    }

    private function mayMoveFabricationDate(): bool
    {
        $user = $this->user();
        $project = $this->route('project');

        return $user !== null
            && (new PrerequisiteConditions())->moveFabricationDate($user, $project);
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
            'date_fabrication_begins.required' => 'This job already has a fabrication start date, and every'
                .' deadline on it is counted back from that day. Move it if it is wrong - it cannot be'
                .' left empty.',
            'date_fabrication_begins.prohibited' => 'The materials for this job have all been delivered, so'
                .' its fabrication date is settled. Everything else here can still be changed.',
        ];
    }
}
