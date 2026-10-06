<?php

namespace App\Models;

use App\Models\Concerns\RecordsChanges;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * @property \Illuminate\Support\Carbon|null $file_disposed_at
 */
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
            'file_disposed_at' => 'datetime',
        ];
    }

    //Relationships
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * The other parent, for a certificate that never had an order.
     *
     * A shop that buys over the phone attaches the merchant's PDF to the batch itself, from the
     * Nesting card's "Delivered" - see the 2026_10_03_130000 migration. Exactly one of the two is
     * set, so everything reading these rows asks the one that is there.
     */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class);
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
        /*
         * One attached to a batch instead of an order (a shop that bought over the phone) is held to
         * the same idea at the point that idea starts applying to it. There is no order to be sent,
         * so the line is the batch closing: while it is live this is somebody tidying up a file they
         * have just attached to their own open job, and once it is a past batch it is the record.
         */
        if ($this->batch_id !== null) {
            return $this->batch !== null && ! $this->batch->done;
        }

        //order_id was non-nullable here, but a cert whose order has gone is not something to refuse over
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
                'Material certificate #'.$this->id.' belongs to '
                .($this->batch_id !== null ? 'a batch that has been closed' : 'an order that has been placed')
                .', so it is part of that record. Attach a replacement instead of removing it.',
            );
        }

        $this->disk()->delete($this->path);

        $this->delete();
    }

    /**
     * Has the file behind this row been disposed of under the retention policy?
     *
     * Different from "the file is not on the disk", which is what a disk that lost it looks like.
     * That distinction is the whole reason the column exists - see the 2026_10_06 migration.
     */
    public function fileWasDisposedOf(): bool
    {
        return $this->file_disposed_at !== null;
    }

    /**
     * Delete the file and keep the row, at the end of its retention period.
     *
     * Not deleteWithFile() with a different name. That method refuses on anything that is already a
     * record - a placed order, a closed batch - which by seven years old is every certificate there
     * is, and rightly so: it exists to stop somebody tidying away evidence they have just decided
     * they do not like. This is the other thing entirely, the controlled end of a period somebody
     * wrote down in advance, so it does not ask that question.
     *
     * What it will not do is run on its own. The only caller is App\Console\Commands\
     * DisposeOfExpiredRecords, which refuses without a named person and a reason and writes a
     * record_dispositions row for the act. A method this blunt reached from anywhere else is how a
     * retention policy turns into an accident.
     *
     * The row survives with everything except the file: the heat, the filename it arrived under,
     * the merchant, the uploader and the date. Deleting it instead would take the account of what
     * was received along with the thing received, and that account is small, is itself the record
     * the certificate was evidence for, and costs nothing to keep.
     */
    public function disposeOfFile(): void
    {
        if ($this->fileWasDisposedOf()) {
            return;
        }

        $this->disk()->delete($this->path);

        /*
         * Saved through the model, so the change log records the disposal against the certificate
         * too - RecordsChanges writes an "updated" row naming file_disposed_at. A reader starting
         * from the certificate finds the date, and one starting from record_dispositions finds the
         * person; neither has to know the other table exists.
         */
        $this->file_disposed_at = now();

        $this->save();
    }
}
