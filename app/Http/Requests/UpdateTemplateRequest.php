<?php

namespace App\Http\Requests;

use App\Models\Business;
use App\Models\Template;
use App\Services\TemplateTestCertificate;

/**
 * Creating and updating a template validate identically but for the screenshot. This used
 * to be a byte-for-byte copy of StoreTemplateRequest, so the two drifted apart silently;
 * it now inherits everything and states the one difference.
 */
class UpdateTemplateRequest extends StoreTemplateRequest
{
    /**
     * An edit is gated on a passing test when, and only when, it changes what gets read.
     *
     * The gate exists so that no template reads a customer's spreadsheet without somebody having
     * watched it import steel. Editing used to be outside it altogether, on the grounds that
     * insisting on a sample to fix a spelling mistake in a name would mean names stay wrong - which
     * is right about names and was wrong about everything else: the same form posts the heading
     * cell, and moving that re-anchors every column offset on a template that is already live. A
     * record that had been proved to import steel could be edited into one that reads the wrong
     * columns entirely, with no sample, no test and no check beyond the record's own coherence.
     *
     * So the question is asked of the change rather than of the request. Anything the certificate
     * covers - the cells, the rules, the labels, the units - needs a passing test over the values
     * being saved. The name, the screenshot, the documentation link and whether it is live do not,
     * because none of them changes a single row of what the importer reads.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = collect(parent::rules());

        return $this->changesWhatIsRead()
            ? $rules->all()
            : $rules->except('template_test_token')->all();
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return $this->changesWhatIsRead()
            ? [$this->recordChecks(), $this->passingTest()]
            : [$this->recordChecks()];
    }

    /**
     * Whether this edit would change what the importer takes out of a spreadsheet.
     *
     * False where there is no business or no template in the url to compare against: those are the
     * routes' own problem, and a request that cannot find what it is editing must not answer this
     * question by demanding a test for a template nobody can identify.
     */
    private function changesWhatIsRead(): bool
    {
        $business = $this->route('business');
        $template = $this->route('template');

        if (! $business instanceof Business || ! $template instanceof Template) {
            return false;
        }

        return ! (new TemplateTestCertificate)->sameExtraction(
            $business,
            $template->attributesToArray(),
            //prepareForValidation() has already run, so the cells are cased the way a token signs them
            $this->all(),
        );
    }

    /**
     * An unchanged screenshot is not sent back, so renaming a template no longer re-uploads
     * up to 750KB of base64 to change one word. "sometimes" leaves the stored screenshot
     * alone when the field is absent, and validated() then has no screenshot key for
     * update() to write.
     *
     * Absent means keep; present still has to be a real data URL.
     *
     * @return array<int, mixed>
     */
    protected function screenshotRules(): array
    {
        return ['sometimes', ...$this->screenshotShape()];
    }

    /**
     * A blank screenshot field means "keep the one already stored", so it is removed from
     * the input entirely - "sometimes" skips an absent key, not a present null, and a
     * present null would validate and then blank the column.
     */
    protected function prepareForValidation(): void
    {
        parent::prepareForValidation();

        if ($this->has('screenshot') && blank($this->input('screenshot'))) {
            //replace(), not merge(): merge cannot take a key away again
            $this->replace(collect($this->all())->except('screenshot')->all());
        }
    }
}
