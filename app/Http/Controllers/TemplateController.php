<?php

namespace App\Http\Controllers;

use App\Enums\TemplateEnums;
use App\Enums\TemplateSourceEnums;
use App\Http\Requests\StoreTemplateRequest;
use App\Http\Requests\UpdateTemplateRequest;
use App\Models\Business;
use App\Models\Template;
use App\Services\TemplateChecks;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class TemplateController extends Controller
{
    /*
     * Everything except the screenshot. Each one is up to 750KB of base64, and sending them all
     * inline put every screenshot in the page props on every visit - including the redirect back
     * after each create, update and delete - to render a 128x80 thumbnail. The thumbnails come
     * from AdminTemplateScreenshotController, which the browser can cache per template.
     *
     * The same mistake on the admin users page measured an 11.5MB response.
     */
    private const COLUMNS = [
        'id',
        'business_id',
        'name',
        'source',
        'type',
        'heading_cell',
        'expected_heading_labels',
        'first_description_cell',
        'first_material_cell',
        'first_grade_cell',
        'first_surface_cell',
        'first_length_required_cell',
        'first_width_required_cell',
        'first_sub_qty_cell',
        'skip_or_finish_check_cell',
        'should_skip_row',
        'is_last_data_row',
        'compound_description_prefix',
        'compound_description_suffix',
        'compound_description_cells',
        'assembly_mark_rule',
        'assembly_mark_cell',
        'length_width_units',
        'web_source',
        'active',
        'updated_at',
    ];

    /**
     * Display a listing of the resource.
     */
    public function index(Business $business): Response
    {
        return Inertia::render('AdminTemplatesIndex', [
            'templates' => $this->present($this->listed($business->templates())),
            /*
             * Two fields, not the model. A Business serializes 23 columns including every
             * cost and pricing setting, and this page reads the id and the domain.
             */
            'business' => [
                'id' => $business->id,
                'domain' => $business->domain,
            ],
            'sources' => array_column(TemplateSourceEnums::cases(), 'value'),
            'types' => array_column(TemplateEnums::cases(), 'value'),
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreTemplateRequest $request, Business $business): RedirectResponse
    {
        /*
         * Without the token, which is proof that this template was tested and not a column of the
         * table - the model is $guarded = [], so anything left in here is handed straight to the
         * insert. See TemplateTestCertificate.
         */
        $business->templates()->create(
            collect($request->validated())->except('template_test_token')->all(),
        );

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
     * Every column the screen reads, and the first few characters of the one it must not load.
     *
     * @param  Builder<Template>|HasMany<Template, Business>  $query
     * @return Collection<int, Template>
     */
    private function listed(Builder|HasMany $query): Collection
    {
        return $query
            ->select(self::COLUMNS)
            //substr(), so "is there a screenshot" costs 22 characters instead of 750KB
            ->selectRaw('substr(screenshot, 1, 22) as screenshot_head')
            /*
             * By id, not created_at. Recording a business's templates is one sitting at one
             * screen, so several rows share a timestamp stored to the second - and "newest
             * first" then picks between them arbitrarily.
             */
            ->orderByDesc('id')
            ->get();
    }

    /**
     * @param  Collection<int, Template>  $templates
     * @return array<int, array<string, mixed>>
     */
    private function present(Collection $templates): array
    {
        return $templates->map(fn (Template $template) => [
            ...$template->only(array_diff(self::COLUMNS, ['business_id', 'updated_at'])),
            /*
             * Whether there is a thumbnail to ask for. The screenshot itself is deliberately not
             * in these props, so without this the page points an <img> at an endpoint that 404s
             * for every seeded template - none of them is a photograph of a customer's file.
             *
             * Read from the first few characters rather than the column, because the column is
             * the 750KB this whole select exists to leave behind.
             */
            'has_screenshot' => str_starts_with((string) $template->screenshot_head, 'data:image/'),
            'detection' => $this->detectionSummary($template),
        ])->all();
    }

    /**
     * Whether this row is matched against uploads at all, and everything questionable about it.
     *
     * A row recorded before templates drove detection has cell references and no heading row, so
     * there is nothing to find it by; it says which part is missing rather than sitting there
     * looking like a working template.
     *
     * @return array{detects: bool, blocked_by: list<string>, warnings: array<int, string>}
     */
    private function detectionSummary(Template $template): array
    {
        return [
            'detects' => $template->active && $template->canDetect(),
            'blocked_by' => $template->undetectableReasons(),
            /*
             * Everything odd about the row that is not bad enough to refuse. None of it is refused
             * on save, so this list is the only place it shows.
             */
            'warnings' => array_map(
                fn (array $finding) => $finding['message'],
                TemplateChecks::warnings((new TemplateChecks)->record($template->attributesToArray())),
            ),
        ];
    }
}
