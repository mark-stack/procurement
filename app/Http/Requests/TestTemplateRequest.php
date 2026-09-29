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
 */
class TestTemplateRequest extends StoreTemplateRequest
{
    public function rules(): array
    {
        return [
            ...collect(parent::rules())
                ->except(['name', 'screenshot', 'active', 'web_source'])
                ->all(),

            /*
             * Same ceiling and same types as AdminTemplateProposalController: a sample is often a
             * sheet saved out by hand to show us the shape of a report.
             */
            'sample' => ['required', 'file', 'mimes:xls,xlsx,csv', 'max:1024'],
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
        return collect($this->validated())->except('sample')->all();
    }
}
