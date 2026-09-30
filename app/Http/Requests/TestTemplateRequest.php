<?php

namespace App\Http\Requests;

/**
 * The template form, submitted to be tried against a sample spreadsheet rather than saved.
 *
 * Everything that describes the table is validated exactly as it is on save - the cell references
 * are normalised and checked by the same rules, and the same blocking checks run in after(), because
 * a record that cannot detect or cannot read a description cannot be tested either and the reason
 * belongs under the field it is about.
 *
 * The four fields that are not about reading a spreadsheet are dropped: the name (which has to be
 * unique within the business, and a test is not a record), the screenshot, whether it is live, and
 * where the format is documented. Requiring a screenshot before an admin may press Test would be
 * asking for a photograph to check some arithmetic.
 *
 * So is the proof of a passing test, for the obvious reason: this is the request that issues one.
 */
class TestTemplateRequest extends StoreTemplateRequest
{
    /**
     * Everything the record has to satisfy, and not the test gate - see the class note above.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [$this->recordChecks()];
    }

    public function rules(): array
    {
        return [
            ...collect(parent::rules())
                ->except(['name', 'screenshot', 'active', 'web_source', 'template_test_token'])
                ->all(),

            /*
             * Same ceiling and same types as AdminTemplateProposalController: a sample is often a
             * sheet saved out by hand to show us the shape of a report.
             */
            'sample' => ['required', 'file', 'mimes:xls,xlsx,csv', 'max:1024'],

            /*
             * Which recorded template the form is open on, when it is open on one. Only used to keep
             * a template from being reported as a duplicate of itself: editing one and testing it
             * against the file it already reads is the ordinary thing to do, not a clash.
             *
             * Unvalidated against the business on purpose - it can only ever remove a row from a
             * check, so the worst a wrong one does is quieten a warning for whoever sent it.
             */
            'editing_template_id' => ['nullable', 'integer'],
        ];
    }

    public function messages(): array
    {
        return [
            ...parent::messages(),
            'sample.required' => 'Choose a spreadsheet to test this template against.',
            'sample.mimes' => 'The sample must be a spreadsheet (.xls, .xlsx or .csv).',
            'sample.max' => 'The sample must be under 1Mb.',
        ];
    }

    /**
     * The template's own fields - everything the importer reads, and nothing about the upload it is
     * being tried against.
     *
     * @return array<string, mixed>
     */
    public function templateAttributes(): array
    {
        return collect($this->validated())->except(['sample', 'editing_template_id'])->all();
    }
}
