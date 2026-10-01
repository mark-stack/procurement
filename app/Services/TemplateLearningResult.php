<?php

namespace App\Services;

use App\Enums\TemplateLearningEnums;
use App\Models\Template;
use App\Models\TemplateLearningAttempt;

/**
 * What came of trying to write a template for an upload that matched nothing.
 *
 * Two outcomes, and the caller does something completely different with each: a template was
 * recorded, so re-run the import and the file will be read - or it was not, so tell the customer the
 * one sentence we tell them and leave the attempt for an admin.
 *
 * A readonly object rather than an array because the two shapes have almost nothing in common, and
 * an array would have the controller asking isset() about keys that only exist down one branch.
 */
class TemplateLearningResult
{
    private function __construct(
        public readonly ?Template $template,
        public readonly ?TemplateLearningAttempt $attempt,
        public readonly ?TemplateLearningEnums $outcome,
        public readonly string $message,
    ) {}

    /**
     * A template was written, tested against the file that triggered it, and saved live.
     */
    public static function recorded(Template $template): self
    {
        return new self($template, null, null, sprintf(
            'This is a spreadsheet format we had not seen before. We have read it, checked that it imports, and saved it as "%s" - uploads of it will import straight away from now on.',
            $template->name,
        ));
    }

    /**
     * No template. The attempt is recorded either way; it is only null if writing it failed, which
     * must not turn a file we could not read into a 500 on a customer's upload.
     */
    public static function refused(TemplateLearningEnums $outcome, ?TemplateLearningAttempt $attempt): self
    {
        return new self(null, $attempt, $outcome, $outcome->customerMessage());
    }

    public function learned(): bool
    {
        return $this->template !== null;
    }
}
