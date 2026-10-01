<?php

namespace App\Models;

use App\Enums\TemplateLearningEnums;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * One customer upload that matched no template and could not be made to match one.
 *
 * Written by TemplateLearningService when it gives up, read by the admin templates screen, and
 * resolved when a template that reads the file exists. Nothing here is shown to the customer: they
 * are told the one sentence on TemplateLearningEnums and nothing else.
 *
 * @property int $id
 * @property int $business_id
 * @property int|null $user_id
 * @property int|null $project_id
 * @property string $file_name
 * @property string|null $sample_path
 * @property TemplateLearningEnums $outcome
 * @property string|null $headline
 * @property array<string, mixed>|null $proposal
 * @property list<array{key: string, label: string, status: string, detail: string}>|null $checks
 * @property list<array{level: string, field: string|null, message: string}>|null $findings
 * @property Carbon|null $resolved_at
 * @property int|null $resolved_by_user_id
 * @property int|null $template_id
 * @property Carbon $created_at
 */
class TemplateLearningAttempt extends Model
{
    /*
     * No factory. Every row in this table is written by TemplateLearningService when it gives up on a
     * real upload, and the tests make them that way - a factory would let one be conjured with a
     * sample_path naming no file and checks that were never asked, which is the one thing this model's
     * methods all assume is not true of it.
     */

    /**
     * Where the copy of the customer's spreadsheet is kept.
     *
     * The local disk, which is not public: these are customers' bills of materials, and the one
     * thing that must never be true of them is that a URL serves one to whoever guesses it. They
     * reach an admin through AdminTemplateAttemptSampleController, which checks who is asking.
     */
    public const DISK = 'local';

    public const DIRECTORY = 'template-learning-attempts';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'outcome' => TemplateLearningEnums::class,
            'proposal' => 'array',
            'checks' => 'array',
            'findings' => 'array',
            'resolved_at' => 'datetime',
        ];
    }

    /*
     * Relationships
     *
     * Annotated, unlike most of this application's, because the admin screen maps over these rows and
     * reads a field off the uploader - and without the generic that read is a property access on a
     * bare Model, which static analysis cannot type and reports as an unresolvable one.
     */
    /** @return BelongsTo<Business, $this> */
    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return BelongsTo<Template, $this> */
    public function template(): BelongsTo
    {
        return $this->belongsTo(Template::class);
    }

    //Scopes
    /**
     * Still somebody's job. The admin screen leads with these and the users list counts them.
     *
     * @param  Builder<TemplateLearningAttempt>  $query
     */
    public function scopeUnresolved(Builder $query): void
    {
        $query->whereNull('resolved_at');
    }

    //Booleans
    /**
     * Whether the stored sample is still there to be downloaded and tested against.
     *
     * Asked rather than assumed, because the file outlives nothing: a cleanup, a restored database
     * pointed at an empty disk, or a row written when the write to disk itself failed all leave a
     * path naming nothing. The screen offers the download only when this is true.
     */
    public function hasSample(): bool
    {
        return filled($this->sample_path) && Storage::disk(self::DISK)->exists($this->sample_path);
    }

    //Methods
    /**
     * Mark this attempt dealt with, and say what dealt with it.
     *
     * The sample goes at the same time. It is a customer's bill of materials held only so that
     * somebody could reproduce the failure, and once a template reads it there is nothing left to
     * reproduce - keeping it would be keeping a copy of a customer's project data for no remaining
     * reason. The proposal and the checks stay: they are small, they say what went wrong, and they
     * are the only account of it.
     */
    public function resolve(?Template $template = null, ?User $by = null): void
    {
        $this->discardSample();

        $this->forceFill([
            'resolved_at' => now(),
            'resolved_by_user_id' => $by?->id,
            'template_id' => $template?->id,
        ])->save();
    }

    /**
     * Delete the stored spreadsheet and forget where it was.
     *
     * Tolerant of a path naming nothing on purpose - see hasSample(). A cleanup that throws
     * because the file it wanted to delete is already gone has failed at nothing.
     */
    public function discardSample(): void
    {
        if (filled($this->sample_path)) {
            Storage::disk(self::DISK)->delete($this->sample_path);
        }

        $this->sample_path = null;
    }
}
