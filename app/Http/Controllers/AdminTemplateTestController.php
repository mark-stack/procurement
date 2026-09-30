<?php

namespace App\Http\Controllers;

use App\Http\Requests\TestTemplateRequest;
use App\Models\Business;
use App\Services\TemplateTestCertificate;
use App\Services\TemplateTestService;
use Illuminate\Http\RedirectResponse;

/**
 * Tries the template form against a sample spreadsheet and reports what it would import.
 *
 * Writes nothing: no template, no project, no material row. The result comes back as a flash prop
 * next to the form that produced it, and the ordinary store request is still what records anything -
 * so an admin can find out that a record extracts no steel before a customer does.
 *
 * It is also the gate. Creating a template is refused unless its test passed, and this is where the
 * proof of that is issued: a pass hands back a token signed over the values that were tried, which
 * the store request checks against the values being saved. A failing test hands back no token, which
 * is the whole of why a failing template cannot be created.
 */
class AdminTemplateTestController extends Controller
{
    public function __invoke(
        TestTemplateRequest $request,
        Business $business,
        TemplateTestService $tests,
        TemplateTestCertificate $certificate,
    ): RedirectResponse {
        $attributes = $request->templateAttributes();
        $result = $tests->run(
            $request->file('sample'),
            $business,
            $attributes,
            //Not its own duplicate: editing a template and testing it against the file it reads
            $request->integer('editing_template_id') ?: null,
        );

        return back()->with('templateTest', [
            ...$result,
            /*
             * Signed over $attributes rather than over the result, so it is only good for the values
             * that were actually tried. Editing any cell after reading this makes the token stop
             * matching, which is the same thing the screen says when it calls the result stale.
             */
            'token' => $result['passed'] ? $certificate->issue($business, $attributes) : null,
        ]);
    }
}
