<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreTemplateRequest;
use App\Http\Requests\UpdateTemplateRequest;
use App\Models\Business;
use App\Models\Template;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class TemplateController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Business $business): Response
    {
        /*
         * The column list is explicit, and screenshot is not in it. Each one is up to 750KB
         * of base64, and sending them all inline put every screenshot in the page props on
         * every visit - including the redirect back after each create, update and delete -
         * to render a 128x80 thumbnail. The thumbnails now come from
         * AdminTemplateScreenshotController, which the browser can cache per template.
         *
         * The same mistake on the admin users page measured an 11.5MB response.
         */
        $templates = $business->templates()
            ->select([
                'id',
                'name',
                'source',
                'config_label',
                'first_description_cell',
                'first_material_cell',
                'first_length_required_cell',
                'first_width_required_cell',
                'first_sub_qty_cell',
                'length_width_units',
                'active',
                'updated_at',
            ])
            ->latest()
            ->get()
            ->map(fn (Template $template) => [
                ...$template->only([
                    'id',
                    'name',
                    'source',
                    'config_label',
                    'first_description_cell',
                    'first_material_cell',
                    'first_length_required_cell',
                    'first_width_required_cell',
                    'first_sub_qty_cell',
                    'length_width_units',
                    'active',
                ]),
                /*
                 * Whether the entry this row claims to document is still in the config, and
                 * what that entry says its units are. A row recorded before source and
                 * config_label existed names nothing and says so; a row whose entry has
                 * since been renamed or removed is the drift this pair exists to surface.
                 */
                'detection' => $this->detectionSummary($template),
            ]);

        return Inertia::render('AdminTemplatesIndex', [
            'templates' => $templates,
            /*
             * Two fields, not the model. A Business serializes 23 columns including every
             * cost and pricing setting, and this page reads the id and the domain.
             */
            'business' => [
                'id' => $business->id,
                'domain' => $business->domain,
            ],
            //The entries a template can be recorded against, for the form's select
            'detectionOptions' => Template::detectionOptions(),
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreTemplateRequest $request, Business $business): RedirectResponse
    {
        $business->templates()->create($request->validated());

        return back();
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateTemplateRequest $request, Business $business, Template $template): RedirectResponse
    {
        //No screenshot key when the field came back blank, so the stored one is left alone
        $template->update($request->validated());

        return back();
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Business $business, Template $template): RedirectResponse
    {
        $template->delete();

        return back();
    }

    /**
     * @return array{configured: bool, nominal_units: string|null}
     */
    private function detectionSummary(Template $template): array
    {
        $entry = $template->detectionConfig();

        return [
            'configured' => $entry !== null,
            /*
             * The units the importer reads for this entry, so the recorded units can be
             * read against them. See the note on the form: neither number reaches the
             * length conversion today.
             */
            'nominal_units' => $entry['nominalUnits'] ?? null,
        ];
    }
}
