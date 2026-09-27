<?php

namespace App\Http\Requests;

/**
 * Creating and updating a template validate identically but for the screenshot. This used
 * to be a byte-for-byte copy of StoreTemplateRequest, so the two drifted apart silently;
 * it now inherits everything and states the one difference.
 */
class UpdateTemplateRequest extends StoreTemplateRequest
{
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
        return array_merge(['sometimes'], array_filter(
            parent::screenshotRules(),
            fn ($rule) => $rule !== 'required',
        ));
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
