<?php

namespace App\Models;

use App\Actions\RawMaterialQuote\DeleteMaterialRows;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * One spreadsheet somebody uploaded, and the materials that came out of it.
 *
 * Kept so an upload can be taken back off a batch as one thing. Before this, a file that had been
 * imported was only its rows, scattered through a table carrying several projects' steel, and undoing
 * a wrong revision meant recognising them by eye.
 *
 * @property int $id
 * @property int $project_id
 * @property int|null $user_id
 * @property string|null $path
 * @property string $original_filename
 * @property string|null $mime_type
 * @property int $size_bytes
 */
class MaterialListFile extends Model
{
    /** @use HasFactory<\Database\Factories\MaterialListFileFactory> */
    use HasFactory;

    protected $guarded = [];

    /**
     * The disk these live on. Private, for the reason TemplateLearningAttempt keeps its samples
     * private: a bill of materials is a customer's job - what they are building and how much of it -
     * and nothing about it belongs on a URL somebody can guess.
     */
    public const DISK = 'local';

    public const DIRECTORY = 'material-list-files';

    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
        ];
    }

    //Relationships
    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    //Optional - the uploader's account may since have been deleted
    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<RawMaterialQuote, $this> */
    public function rawMaterialQuotes(): HasMany
    {
        return $this->hasMany(RawMaterialQuote::class);
    }

    //Creation
    /**
     * Record an upload, and keep a copy of it.
     *
     * The row comes first and the file is named after it, the way TemplateLearningService stores its
     * samples: two customers' "BOM.xlsx" are two files, and an original filename is whatever somebody
     * typed into Windows - including characters that have no business deciding a path. The name they
     * gave it is in the row and is what every screen shows.
     *
     * A disk that will not take the file does not cost anybody their import. The row is written
     * anyway, with no path on it, so the materials still trace back to a named upload - there is just
     * nothing to download.
     */
    public static function record(UploadedFile $file, Project $project, User $uploader): self
    {
        $materialListFile = self::create([
            'project_id' => $project->id,
            'user_id' => $uploader->id,
            'original_filename' => $file->getClientOriginalName(),
            'mime_type' => $file->getClientMimeType(),
            'size_bytes' => $file->getSize() ?: 0,
        ]);

        $materialListFile->update(['path' => $materialListFile->store($file)]);

        return $materialListFile;
    }

    private function store(UploadedFile $file): ?string
    {
        try {
            $extension = pathinfo($file->getClientOriginalName(), PATHINFO_EXTENSION);
            $extension = preg_match('/^[A-Za-z0-9]{1,8}$/', $extension) === 1 ? strtolower($extension) : 'xlsx';

            return $file->storeAs(self::DIRECTORY, $this->id.'.'.$extension, self::DISK) ?: null;
        }
        //A full disk must not cost the customer their upload - see record() above
        catch (Throwable $exception) {
            report($exception);

            return null;
        }
    }

    //Methods
    public function disk(): \Illuminate\Contracts\Filesystem\Filesystem
    {
        return Storage::disk(self::DISK);
    }

    //A row whose file could not be stored, or whose file has since gone from the disk
    public function isDownloadable(): bool
    {
        return $this->path !== null && $this->disk()->exists($this->path);
    }

    /**
     * The rows from this file that have been committed to - quoted or ordered.
     *
     * Empty for an upload still sitting on the open batch, which is the case this whole screen is
     * built around. Anything in here is the reason the file may not be removed.
     *
     * @return Collection<int, RawMaterialQuote>
     */
    public function committedRows(): Collection
    {
        return $this->rawMaterialQuotes()
            ->with('piece.quotes', 'piece.order')
            ->get()
            ->filter(fn (RawMaterialQuote $rawMaterialQuote) => $rawMaterialQuote->status() !== null)
            ->values();
    }

    /**
     * The rows from this file that have been nested into a batch.
     *
     * Not the same question as committedRows() above and it bites earlier: "Start quoting" sweeps
     * everything waiting into one batch, and from that moment the batch is a thing being priced as a
     * whole - the nest is saved against it and the order lists are read off that nest. Taking a file
     * out from under it would leave both describing steel that is no longer on the job.
     *
     * Asked of the file's own pieces rather than of the card somebody happened to open, because a
     * project can have both: materials uploaded last week on a quoted batch, and materials uploaded
     * this morning still waiting. The second file is removable and the first is not, whichever BOM
     * they are being read on.
     *
     * @return Collection<int, RawMaterialQuote>
     */
    public function batchedRows(): Collection
    {
        return $this->rawMaterialQuotes()
            ->with('piece')
            ->get()
            ->filter(fn (RawMaterialQuote $rawMaterialQuote) => $rawMaterialQuote->piece?->batch_id !== null)
            ->values();
    }

    /**
     * May this upload still be taken off the job?
     *
     * Only while none of its steel has been nested into a batch, and none of it quoted or ordered -
     * the same line the per-project BOM draws around an individual row, asked of the whole file. Once
     * a supplier has been asked to price a length, or sent an order for one, that row is a commitment
     * to somebody outside the business, and deleting it quietly would leave a quote nobody can trace
     * back to a material list.
     *
     * All or nothing on purpose. Half-deleting an upload - taking the loose rows and leaving the
     * quoted ones - produces a file that is listed, is named after a spreadsheet, and no longer
     * contains what that spreadsheet said. The refusal names the committed rows instead, so the
     * answer is "these four lengths are on order" rather than "no".
     */
    public function isDeletable(): bool
    {
        return $this->committedRows()->isEmpty() && $this->batchedRows()->isEmpty();
    }

    /**
     * Take the upload, its materials, and everything downstream of them off the project.
     *
     * The rows go first and through DeleteMaterialRows, which knows the order the pieces, quotes and
     * emptied batches have to come apart in. Then the file row, then the file itself - last, because
     * a file still on disk with no row pointing at it is litter, while a row pointing at a file that
     * is gone is a download that 404s on somebody who had every right to it.
     *
     * Guarded here as well as in the controller, because this is the method that actually destroys
     * them. The controller answers the user; this is what makes the rule true for the next caller.
     */
    public function deleteWithRows(Business $business): void
    {
        if (! $this->isDeletable()) {
            throw new RuntimeException(
                'Material list file #'.$this->id.' has materials that are already on a batch, or that '
                .'have been quoted or ordered, so it is part of what this business is buying.',
            );
        }

        $path = $this->path;

        DB::transaction(function () use ($business): void {
            DeleteMaterialRows::run(
                $this->rawMaterialQuotes()->with('piece.quotes', 'piece.order')->get(),
                $business,
            );

            $this->delete();
        });

        if ($path !== null) {
            $this->disk()->delete($path);
        }
    }
}
