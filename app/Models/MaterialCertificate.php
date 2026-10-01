<?php

namespace App\Models;

use App\Models\Concerns\RecordsChanges;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class MaterialCertificate extends Model
{
    /** @use HasFactory<\Database\Factories\MaterialCertificateFactory> */
    use HasFactory, RecordsChanges;

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
     * May this certificate still be taken back off the order?
     *
     * Only while the order has not been placed. Before that it is a draft attachment and deleting the
     * wrong file is just tidying up. Once the order is sent, the certificate is the evidence behind
     * steel the business has committed to buying - and once it is delivered, behind steel that is in
     * the yard and quite possibly already cut, where the offcut ancestry walks back to this very row
     * for its trail (see Batch::offcutOrdersWithCertificates).
     *
     * Deleting it then is not a correction, it is the destruction of a record, and ISO 9001 7.5.3.2
     * is explicit that documented information is protected from unintended alteration. The way to fix
     * a wrong certificate on a placed order is the way the migration describes: attach the right one.
     * Both show, the trail reports both, and nothing has been thrown away.
     */
    public function isDeletable(): bool
    {
        //order_id is non-nullable, but a cert whose order has gone is not something to refuse over
        return $this->order === null || ! $this->order->order_sent;
    }

    /**
     * Delete the row and the file behind it.
     *
     * The file goes first: a row with no file behind it is a download that 404s, which is worse than
     * an orphaned file nothing points at. If the unlink fails the row survives and can be retried.
     *
     * Guarded here as well as in the controller because this is the method that actually destroys the
     * file. The controller answers the user; this is what makes the rule true for any caller that
     * arrives later.
     */
    public function deleteWithFile(): void
    {
        if (! $this->isDeletable()) {
            throw new RuntimeException(
                'Material certificate #'.$this->id.' belongs to an order that has been placed, so it '
                .'is part of that order\'s record. Attach a replacement instead of removing it.',
            );
        }

        $this->disk()->delete($this->path);

        $this->delete();
    }
}
