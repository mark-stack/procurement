<?php

namespace App\Services;

use App\Enums\TemplateLearningEnums;
use App\Models\Business;
use App\Models\Project;
use App\Models\Template;
use App\Models\TemplateLearningAttempt;
use App\Models\User;
use App\Notifications\TemplateLearningFailedEmail;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Writes the import template for a spreadsheet nobody has recorded one for, on the customer's own
 * upload.
 *
 * This is the whole of what onboarding used to be. A new business signed up, was shown a page asking
 * it to email us example bills of materials, and waited up to two business days while somebody here
 * read each one, filled in the templates form, tested it and pressed Activate. Everything in that
 * sentence except the reading and the judgement was already automated and sat behind the admin
 * screen - TemplateProposalService reads the sheet, TemplateTestService runs the real importer over
 * the proposal and reports every row, TemplateTestChecklist names each check and decides whether a
 * save is allowed. All that was missing was letting a customer's upload trigger it.
 *
 * So it does the same three steps, in the same order, against the same gate:
 *
 *  1. Describe the sheet. TemplateProposalService, with no template matching - which is why this is
 *     being called at all - so OpenAI's reading is the only source of answers.
 *  2. Try it. TemplateTestService extracts every row the proposal would import, classifies each one
 *     against this business's catalogue and plan, and has a second model read the result back.
 *  3. Save it only if it passed. The gate is TemplateTestChecklist::passed(), which is the same
 *     function StoreTemplateRequest enforces through TemplateTestCertificate - so a template written
 *     here has cleared exactly what a template an admin types has to clear. There is no certificate
 *     because there is no second request to carry one between: the test and the save are one call,
 *     and the values tested are the values saved, which is all a certificate exists to prove.
 *
 * A template that passes goes live immediately, flagged generated_by_ai with no reviewed_at - see
 * the migration. That flag changes nothing about importing. It is there because "live, reading real
 * bills of materials, and never looked at by a person" is a state worth being able to list.
 *
 * What does NOT happen here is the interesting half. A proposal that fails the checklist is not
 * saved, not saved inactive, and not shown to the customer: it is kept as a TemplateLearningAttempt
 * with the file, the proposal and every check that failed, and an admin is emailed. The customer is
 * told one sentence that does not ask them to do anything.
 */
class TemplateLearningService
{
    /**
     * The template columns a proposal is allowed to set.
     *
     * Template is $guarded = [], so whatever is in the array reaches the insert. The proposal is
     * assembled from a model's answer, which makes this the one boundary in the app where something
     * outside it chooses what goes into a row - so the columns are named rather than trusted.
     *
     * active, generated_by_ai and the review columns are deliberately absent: they are this class's
     * decisions, not the proposal's, and are set below where that decision is made.
     */
    private const PROPOSAL_COLUMNS = [
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
        'screenshot',
    ];

    public function __construct(
        private readonly TemplateProposalService $proposals = new TemplateProposalService,
        private readonly TemplateTestService $tests = new TemplateTestService,
        private readonly CsvService $csv = new CsvService,
    ) {}

    /**
     * Try to make this file importable, and say whether it worked.
     *
     * Never throws. It is called from the middle of a customer's upload, and every failure it can
     * have - no API key, a model that read the sheet wrong, a sheet that is not a bill of materials
     * at all - is a failure to improve on the message the upload would have shown anyway.
     */
    public function learn(UploadedFile $file, Project $project): TemplateLearningResult
    {
        $user = $project->user;
        $business = $user->business;

        if (! config('templates.learning.enabled')) {
            return $this->giveUp(TemplateLearningEnums::UNREADABLE, $file, $project, [
                'headline' => 'Automatic template learning is switched off, so this file was not read.',
            ]);
        }

        if ($this->overAllowance($business)) {
            return $this->giveUp(TemplateLearningEnums::THROTTLED, $file, $project, [
                'headline' => sprintf(
                    'This business has already made %d attempts this hour, so this one was not read.',
                    (int) config('templates.learning.hourly_limit'),
                ),
            ]);
        }

        try {
            return $this->attempt($file, $project, $business);
        }
        /*
         * The last line of defence, and it has to be Throwable rather than Exception for the same
         * reason ProductController's is: a TypeError out of a model's answer being a shape nothing
         * expected is exactly the failure this is here to absorb. Reported, so it is a bug report
         * rather than a shrug, and then answered as an unreadable file.
         */
        catch (Throwable $exception) {
            report($exception);

            return $this->giveUp(TemplateLearningEnums::UNREADABLE, $file, $project, [
                'headline' => 'Reading this file failed unexpectedly. The error has been reported.',
            ]);
        }
    }

    /**
     * Templates a business can now import with that nobody here recorded.
     *
     * Called after any template is created - by this class, and by an admin at the templates screen -
     * to close the attempts that new template answers. An attempt is "settled" when the file it was
     * recorded for is now found by a template, which is a question only the file can answer, so each
     * unresolved sample is re-read and matched the way an upload is.
     *
     * Cheap because of what bounds it: attempts are per business, unresolved ones are the ones nobody
     * has dealt with, and a business with a dozen of those has a bigger problem than this loop.
     */
    public function resolveSettled(Business $business, Template $template, ?User $by = null): int
    {
        $spec = $template->detectionSpec();

        if ($spec === [] || ($spec['ExpectedHeadingLabels'] ?? []) === []) {
            return 0;
        }

        $resolved = 0;

        foreach ($business->templateLearningAttempts()->unresolved()->get() as $attempt) {
            if (! $attempt->hasSample()) {
                continue;
            }

            $grid = SpreadsheetGrid::fromPath(
                Storage::disk(TemplateLearningAttempt::DISK)->path((string) $attempt->sample_path),
            );

            if ($grid === null || ! $this->finds($grid, $spec)) {
                continue;
            }

            $attempt->resolve($template, $by);
            $resolved++;
        }

        return $resolved;
    }

    /**
     * Describe the sheet, try the description, and save it if it passed.
     *
     * @throws Throwable
     */
    private function attempt(UploadedFile $file, Project $project, Business $business): TemplateLearningResult
    {
        $proposal = $this->proposals->propose($file, $business);

        //Not a spreadsheet at all. Nothing was proposed, so there is nothing to test or keep
        if (! ($proposal['ok'] ?? false)) {
            return $this->giveUp(TemplateLearningEnums::UNREADABLE, $file, $project, [
                'headline' => (string) ($proposal['message'] ?? 'That file could not be read as a spreadsheet.'),
            ]);
        }

        $attributes = $this->attributes($proposal['prefill']);

        /*
         * Nothing to test. propose() always answers with the form's shape, so a sheet it could make
         * nothing of comes back as a column of nulls rather than as a failure - and running the
         * importer over that would report "this template has nothing to find a table by", which is
         * true and useless. The reason worth keeping is the one from the AI call.
         */
        if (! $this->describesATable($attributes)) {
            return $this->giveUp(TemplateLearningEnums::UNREADABLE, $file, $project, [
                'headline' => (string) ($proposal['ai']['error'] ?? 'The sheet could not be described well enough to find a table in it.'),
                'proposal' => $attributes,
                'findings' => $proposal['findings'] ?? null,
            ]);
        }

        $test = $this->tests->run($file, $business, $attributes);

        if (! ($test['passed'] ?? false)) {
            return $this->giveUp(TemplateLearningEnums::REFUSED, $file, $project, [
                'headline' => $this->whyItFailed($test),
                'proposal' => $attributes,
                'checks' => $test['checks'] ?? null,
                'findings' => $test['findings'] ?? null,
            ]);
        }

        /*
         * The screenshot the test drew, in preference to the one the proposal drew. Both are pictures
         * of the same sheet; the test's is the one that knows which row the heading was actually
         * found on, so it bands the table instead of the top-left corner of the file.
         */
        if (filled($test['screenshot'] ?? null)) {
            $attributes['screenshot'] = $test['screenshot'];
        }

        $template = DB::transaction(function () use ($business, $attributes) {
            return $business->templates()->create([
                ...$attributes,
                /*
                 * Live. The point of the whole exercise is that the next upload of this format needs
                 * no OpenAI call and no admin - which only happens if CsvService::eligibleTables()
                 * can see it, and that asks for active.
                 */
                'active' => true,
                //Nobody has read this. reviewed_at stays null until somebody does
                'generated_by_ai' => true,
            ]);
        });

        /*
         * Outside the transaction: it reads files off disk and could close several attempts, and
         * none of that is worth holding the write that created the template. A failure here leaves
         * attempts open, which is the safe direction - somebody looks at a file that already works.
         */
        $this->resolveSettled($business, $template);

        return TemplateLearningResult::recorded($template);
    }

    /**
     * Keep the attempt, tell an admin, and hand back the sentence the customer sees.
     *
     * Everything in here is best effort. A customer's upload must not 500 because the record of why
     * it could not be read failed to save, so the row, the file and the email are each allowed to
     * fail without taking the upload with them.
     *
     * @param  array{headline: string, proposal?: array<string, mixed>|null, checks?: mixed, findings?: mixed}  $detail
     */
    private function giveUp(
        TemplateLearningEnums $outcome,
        UploadedFile $file,
        Project $project,
        array $detail,
    ): TemplateLearningResult {
        $attempt = null;

        try {
            $attempt = $this->record($outcome, $file, $project, $detail);
        } catch (Throwable $exception) {
            report($exception);
        }

        if ($attempt !== null) {
            $this->tellTheAdmins($attempt);
        }

        return TemplateLearningResult::refused($outcome, $attempt);
    }

    /**
     * @param  array{headline: string, proposal?: array<string, mixed>|null, checks?: mixed, findings?: mixed}  $detail
     *
     * @throws Throwable
     */
    private function record(
        TemplateLearningEnums $outcome,
        UploadedFile $file,
        Project $project,
        array $detail,
    ): TemplateLearningAttempt {
        $attempt = $project->user->business->templateLearningAttempts()->create([
            'user_id' => $project->user_id,
            'project_id' => $project->id,
            'file_name' => $file->getClientOriginalName(),
            'outcome' => $outcome,
            //255 characters: a column headline, not the report. The checks carry the report
            'headline' => mb_substr($detail['headline'], 0, 255),
            'proposal' => $detail['proposal'] ?? null,
            'checks' => $detail['checks'] ?? null,
            'findings' => $detail['findings'] ?? null,
        ]);

        /*
         * The file itself, so the failure can be reproduced rather than reasoned about. Stored after
         * the row and under the row's id: a file on disk that no row points at is litter nothing will
         * ever clean up, and this way the only orphan possible is the harmless direction.
         *
         * Not kept for a THROTTLED attempt. Nothing was read, so there is nothing to reproduce, and
         * the shape that produces those is a customer retrying the same file - which would store the
         * same spreadsheet five more times for no reason.
         */
        if ($outcome !== TemplateLearningEnums::THROTTLED) {
            $attempt->update(['sample_path' => $this->storeSample($file, $attempt)]);
        }

        return $attempt;
    }

    /**
     * Keep a copy of the customer's spreadsheet, or null if it could not be kept.
     *
     * Named after the attempt rather than after the upload: two customers' "BOM.xlsx" are two files,
     * and an original filename is whatever the customer typed, including characters that have no
     * business being a path. The name they gave it is in the row.
     */
    private function storeSample(UploadedFile $file, TemplateLearningAttempt $attempt): ?string
    {
        try {
            $extension = pathinfo($file->getClientOriginalName(), PATHINFO_EXTENSION);
            $extension = preg_match('/^[A-Za-z0-9]{1,8}$/', $extension) === 1 ? strtolower($extension) : 'xlsx';

            return $file->storeAs(
                TemplateLearningAttempt::DIRECTORY,
                $attempt->id.'.'.$extension,
                TemplateLearningAttempt::DISK,
            ) ?: null;
        }
        //A full disk must not cost the customer their upload. The row still says what went wrong
        catch (Throwable $exception) {
            report($exception);

            return null;
        }
    }

    /**
     * Every admin, by email.
     *
     * Mail only, for the reason on NewUserEmail::via(): the bell draws what
     * NotificationService::implementations() claims, so a database row for a type no implementation
     * claims is an unread notification with no wording and no way to clear it.
     */
    private function tellTheAdmins(TemplateLearningAttempt $attempt): void
    {
        try {
            $admins = User::query()->where('is_admin', true)->get();

            if ($admins->isNotEmpty()) {
                Notification::send($admins, new TemplateLearningFailedEmail($attempt));
            }
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    /**
     * Whether this business has used up its hour. Successes count as well as failures - see
     * config/templates.php for why.
     */
    private function overAllowance(Business $business): bool
    {
        $limit = (int) config('templates.learning.hourly_limit');

        if ($limit <= 0) {
            return true;
        }

        $since = now()->subHour();

        $spent = $business->templateLearningAttempts()->where('created_at', '>=', $since)->count()
            + $business->templates()->where('generated_by_ai', true)->where('created_at', '>=', $since)->count();

        return $spent >= $limit;
    }

    /**
     * Only the template's own columns, with the keys the insert wants and nothing else.
     *
     * @param  array<string, mixed>  $prefill
     * @return array<string, mixed>
     */
    private function attributes(array $prefill): array
    {
        $attributes = [];

        foreach (self::PROPOSAL_COLUMNS as $column) {
            $attributes[$column] = $prefill[$column] ?? null;
        }

        //NOT NULL with a default of NONE, so a proposal that left it out must not write null over it
        $attributes['assembly_mark_rule'] = filled($attributes['assembly_mark_rule'])
            ? $attributes['assembly_mark_rule']
            : 'NONE';

        return $attributes;
    }

    /**
     * Whether there is anything here to test.
     *
     * The same three things Template::canDetect() and TemplateTestService::run() ask for - an anchor,
     * labels to find it by, and a column to read - asked before the importer is run rather than after,
     * so that "the model could make nothing of this sheet" is reported as that instead of as a
     * template with nothing in it.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function describesATable(array $attributes): bool
    {
        return (new Template($attributes))->canDetect();
    }

    /**
     * Whether this spec finds a table anywhere in this sheet. CsvService's own matching, asked one
     * row at a time, which is how TemplateProposalService and TemplateTestService both ask it.
     *
     * @param  array<string, mixed>  $spec
     */
    private function finds(SpreadsheetGrid $grid, array $spec): bool
    {
        for ($rowNumber = 1; $rowNumber <= $grid->rowCount(); $rowNumber++) {
            if ($this->csv->headerStartIndex($grid->row($rowNumber), $spec) !== null) {
                return true;
            }
        }

        return false;
    }

    /**
     * The first check that failed, in its own words.
     *
     * The checklist is ordered the way the failures happen - the file, then the table, then the rows,
     * then whether those rows are steel - so the first failure is the one that explains the rest. An
     * admin opening the row sees all of them; this is the line in the list.
     *
     * @param  array<string, mixed>  $test
     */
    private function whyItFailed(array $test): string
    {
        foreach ($test['checks'] ?? [] as $check) {
            if (($check['status'] ?? null) === TemplateTestChecklist::FAIL) {
                return $check['label'].': '.$check['detail'];
            }
        }

        return (string) ($test['message'] ?? 'The proposed template did not pass its test.');
    }
}
