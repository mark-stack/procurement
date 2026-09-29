<?php

namespace App\Http\Controllers;

use App\Http\Requests\TestTemplateRequest;
use App\Models\Business;
use App\Services\TemplateTestService;
use Illuminate\Http\RedirectResponse;

/**
 * Tries the template form against a sample spreadsheet and reports what it would import.
 *
 * Writes nothing: no template, no project, no material row. The result comes back as a flash prop
 * next to the form that produced it, and the ordinary store request is still what records anything -
 * so an admin can find out that a record extracts no steel before a customer does.
 */
class AdminTemplateTestController extends Controller
{
    public function __invoke(TestTemplateRequest $request, Business $business, TemplateTestService $tests): RedirectResponse
    {
        return back()->with(
            'templateTest',
            $tests->run($request->file('sample'), $business, $request->templateAttributes()),
        );
    }
}
