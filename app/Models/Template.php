<?php

namespace App\Models;

use App\Models\Concerns\RecordsChanges;
use App\Services\CellReference;
use Database\Factories\TemplateFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Annotated because the two json columns are the ones everything here reads, and without it
 * static analysis takes a casted attribute for a string - so array_values() over the heading
 * labels reads as a call that cannot do anything.
 *
 * @property int $id
 * @property int $business_id
 * @property string $name
 * @property string|null $source
 * @property string|null $type
 * @property string|null $heading_cell
 * @property list<string>|null $expected_heading_labels
 * @property string|null $first_description_cell
 * @property string|null $first_material_cell
 * @property string|null $first_grade_cell
 * @property string|null $first_surface_cell
 * @property string|null $first_length_required_cell
 * @property string|null $first_width_required_cell
 * @property string|null $first_sub_qty_cell
 * @property string|null $skip_or_finish_check_cell
 * @property string|null $should_skip_row
 * @property string|null $is_last_data_row
 * @property string|null $compound_description_prefix
 * @property string|null $compound_description_suffix
 * @property list<string>|null $compound_description_cells
 * @property string $assembly_mark_rule
 * @property string|null $assembly_mark_cell
 * @property string $length_width_units
 * @property string|null $web_source
 * @property string|null $screenshot
 * @property bool $active
 * @property bool $generated_by_ai
 * @property \Illuminate\Support\Carbon|null $reviewed_at
 * @property int|null $reviewed_by_user_id
 */
class Template extends Model
{
    /** @use HasFactory<TemplateFactory> */
    use HasFactory, RecordsChanges;

    protected $guarded = [];

    /**
     * The cell references that describe one row of data, and the config key each one answers in the
     * spec the importer reads. The order is the order they are shown in on the form.
     */
    public const CELL_FIELDS = [
        'first_description_cell' => 'DescriptionRelativeOffset',
        'first_material_cell' => 'MaterialRelativeOffset',
        'first_grade_cell' => 'GradeRelativeOffset',
        'first_surface_cell' => 'SurfaceRelativeOffset',
        'first_length_required_cell' => 'LengthRelativeOffset',
        'first_width_required_cell' => 'WidthRelativeOffset',
        'first_sub_qty_cell' => 'SubQtyRelativeOffset',
    ];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'generated_by_ai' => 'boolean',
            'reviewed_at' => 'datetime',
            'expected_heading_labels' => 'array',
            'compound_description_cells' => 'array',
        ];
    }

    /**
     * The screenshot stays out of the change log.
     *
     * It is a data URI of a whole spreadsheet in a longText column, so recording it would put a
     * megabyte of base64 in every row of a table whose job is to be readable - twice over on an
     * update, since each entry holds the before and the after. It is also the one column on this
     * model that changes nothing about what the importer reads, which is what the log is here for.
     *
     * @return list<string>
     */
    protected function changeLogExcept(): array
    {
        return ['screenshot'];
    }

    //Relationships
    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_user_id');
    }

    //Scopes
    /**
     * Templates a customer's upload wrote for itself that nobody has looked at since.
     *
     * Both halves matter. An unreviewed template an admin typed is not a thing - typing it was the
     * review - and a machine-written one that has been read is no different from any other row. It
     * is the pair that is worth a list.
     *
     * @param  Builder<Template>  $query
     */
    public function scopeAwaitingReview(Builder $query): void
    {
        $query->where('generated_by_ai', true)->whereNull('reviewed_at');
    }
    /**
     * The templates an upload by this user is matched against: their own business's, and active.
     *
     * The config this replaced compared the user's EMAIL domain to each entry's "ownerDomain",
     * which meant a customer who signed up with a personal address was matched against whatever
     * that address's domain owned. Ownership is a column now, so it cannot be that loose.
     *
     * The config also let an admin match against every entry, and that is deliberately gone:
     * "every entry" used to mean five and would now mean every template every business has
     * recorded, so an admin's own upload could be read at a stranger's offsets. An admin who
     * needs a customer's view impersonates them.
     *
     * @param  Builder<Template>  $query
     */
    public function scopeEligibleFor(Builder $query, ?User $user): void
    {
        //Nobody, rather than everybody: a user with no business has no templates of their own
        if (! $user?->business_id) {
            $query->whereRaw('1 = 0');

            return;
        }

        $query->where('active', true)->where('business_id', $user->business_id);
    }

    /**
     * Templates that can actually be matched against a spreadsheet. A row recorded before templates
     * drove detection holds cell references and no heading row, so there is nothing to find it by.
     *
     * @param  Builder<Template>  $query
     */
    public function scopeDetectable(Builder $query): void
    {
        $query->whereNotNull('heading_cell')->whereNotNull('expected_heading_labels');
    }

    /**
     * This template as the array the importer reads, keyed the way config/TableTemplates.php was.
     *
     * The record stores cell references because that is what an admin can see in a spreadsheet and
     * check. The importer wants offsets, because it has to find the table wherever it sits in a
     * sheet rather than at the coordinates one sample happened to use. The anchor - the cell the
     * heading run starts in, in that sample - is what converts between the two, and this is the
     * only place that conversion happens.
     *
     * @return array<string, mixed>
     */
    public function detectionSpec(): array
    {
        $anchor = $this->headingCell();
        $firstDataRow = $this->firstDataRow();

        //A row with no anchor describes no table; scopeDetectable() keeps these away from detection
        if (! $anchor || $firstDataRow === null) {
            return [];
        }

        $spec = [
            'label' => $this->name,
            'source' => $this->source,
            'type' => $this->type,
            'ExpectedHeadingLabels' => $this->expected_heading_labels ?? [],
            'OffsetFromHeaderToFirstDataRow' => $firstDataRow - $anchor->rowNumber,
            /*
             * Which column the skip and end-of-table rules watch. Left blank it follows the
             * description column, because "stop when the description runs out" is what a table
             * ending looks like - and 0, the anchor's own column, is only right by coincidence.
             */
            'skipOrFinishCheckRelativeOffset' => $this->columnOffset($this->skip_or_finish_check_cell)
                ?? $this->columnOffset($this->first_description_cell)
                ?? 0,
            'ShouldSkipRow' => $this->should_skip_row,
            'isLastDataRow' => $this->is_last_data_row,
            'compoundDescription' => $this->compoundDescriptionSpec(),
            'assemblyMarkRule' => $this->assemblyMarkSpec(),
            'nominalUnits' => $this->length_width_units,
        ];

        foreach (self::CELL_FIELDS as $field => $offsetKey) {
            $spec[$offsetKey] = $this->columnOffset($this->{$field});
        }

        return $spec;
    }

    /**
     * Whether this row could match a spreadsheet at all. Not whether it matches the right one -
     * only a sample can say that - but whether there is anything here to look for.
     */
    public function canDetect(): bool
    {
        return $this->detectionSpec() !== []
            && count($this->expected_heading_labels ?? []) > 0;
    }

    /**
     * Every reason this row cannot be matched against a spreadsheet, in words. Empty when it can.
     *
     * @return list<string>
     */
    public function undetectableReasons(): array
    {
        $reasons = [];

        if (! $this->headingCell()) {
            $reasons[] = 'No heading cell, so there is nothing to measure the columns from.';
        }

        if (count($this->expected_heading_labels ?? []) === 0) {
            $reasons[] = 'No heading labels, so the table cannot be found in a spreadsheet.';
        }

        if ($this->firstDataRow() === null) {
            $reasons[] = 'No cell references, so there are no columns to read.';
        }

        return $reasons;
    }

    public function headingCell(): ?CellReference
    {
        return CellReference::tryFrom($this->heading_cell);
    }

    /**
     * The row every recorded cell reference sits on - the first row of data in the sample.
     *
     * The cells are meant to agree, and a record where they do not is refused on save. Older rows
     * were not, so the row most of them name is taken as the intended one rather than the first.
     */
    public function firstDataRow(): ?int
    {
        $rows = [];

        foreach (array_keys(self::CELL_FIELDS) as $field) {
            if ($cell = CellReference::tryFrom($this->{$field})) {
                $rows[] = $cell->rowNumber;
            }
        }

        if ($rows === []) {
            return null;
        }

        $counts = array_count_values($rows);
        arsort($counts);

        return (int) array_key_first($counts);
    }

    /**
     * How far right of the heading anchor a cell sits. Negative is legitimate: a description column
     * can be left of the heading run that identifies the table, and one of ours is.
     */
    private function columnOffset(?string $reference): ?int
    {
        $cell = CellReference::tryFrom($reference);
        $anchor = $this->headingCell();

        if (! $cell || ! $anchor) {
            return null;
        }

        return $cell->columnIndex - $anchor->columnIndex;
    }

    /**
     * @return array{prefix: string, suffix: string, relativeOffsets: list<int>}|null
     */
    private function compoundDescriptionSpec(): ?array
    {
        $offsets = [];

        //Each entry is a cell reference: StoreTemplateRequest validates them one by one
        foreach ($this->compound_description_cells ?? [] as $reference) {
            $offset = $this->columnOffset($reference);

            if ($offset !== null) {
                $offsets[] = $offset;
            }
        }

        //A prefix with no cells to join builds the same string for every row, which is not a description
        if ($offsets === []) {
            return null;
        }

        return [
            'prefix' => (string) $this->compound_description_prefix,
            'suffix' => (string) $this->compound_description_suffix,
            'relativeOffsets' => $offsets,
        ];
    }

    /**
     * COLUMN counts from 1 where every other offset counts from 0 - a wart of the rule's own making,
     * kept because CsvService::getAssemblyMark() applies it. Storing a cell rather than a number
     * means nobody has to remember which of the two a given field is.
     *
     * @return array{0: string, 1?: int|array{0: int, 1: int}}
     */
    private function assemblyMarkSpec(): array
    {
        $cell = CellReference::tryFrom($this->assembly_mark_cell);
        $anchor = $this->headingCell();

        if (! $cell || ! $anchor) {
            return ['NONE'];
        }

        return match ($this->assembly_mark_rule) {
            'COLUMN' => ['COLUMN', $cell->columnIndex - $anchor->columnIndex + 1],
            'FIXED' => ['FIXED', [
                $cell->columnIndex - $anchor->columnIndex,
                $cell->rowNumber - $anchor->rowNumber,
            ]],
            default => ['NONE'],
        };
    }

    /**
     * The screenshot's media type, read off the data URL it is stored as.
     *
     * Only the types StoreTemplateRequest accepts can be stored, so this is a lookup
     * rather than a guess - and it deliberately never answers image/svg+xml, which would
     * let a stored screenshot carry script when served as a file.
     */
    public function screenshotMimeType(): ?string
    {
        if (! preg_match('/^data:(image\/(?:png|jpeg|jpg|gif|webp));base64,/', (string) $this->screenshot, $matches)) {
            return null;
        }

        return $matches[1];
    }

    /**
     * The screenshot as the bytes of an image file, or null if what is stored is not a
     * data URL this app wrote. Rows created before the data-URL rule existed can hold
     * anything at all, so the caller has to handle null.
     */
    public function decodedScreenshot(): ?string
    {
        if (! $this->screenshotMimeType()) {
            return null;
        }

        $base64 = substr((string) $this->screenshot, strpos((string) $this->screenshot, ',') + 1);

        //strict: reject anything that is not valid base64 rather than decoding it loosely
        $bytes = base64_decode($base64, true);

        return $bytes === false ? null : $bytes;
    }
}
