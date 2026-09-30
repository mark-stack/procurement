<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class MaterialCertificate extends Model
{
    /** @use HasFactory<\Database\Factories\MaterialCertificateFactory> */
    use HasFactory;

    protected $guarded = [];

    /**
     * The disk these live on. Private: a mill certificate names a customer, a project and a heat of
     * steel, and nothing about it belongs on a public URL.
     */
    public const DISK = 'local';

    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
        ];
    }

    //Relationships
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    //Optional - the uploader's account may since have been deleted
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    //Methods
    public function disk(): \Illuminate\Contracts\Filesystem\Filesystem
    {
        return Storage::disk(self::DISK);
    }

    /**
     * Delete the row and the file behind it.
     *
     * The file goes first: a row with no file behind it is a download that 404s, which is worse than
     * an orphaned file nothing points at. If the unlink fails the row survives and can be retried.
     */
    public function deleteWithFile(): void
    {
        $this->disk()->delete($this->path);

        $this->delete();
    }
}
