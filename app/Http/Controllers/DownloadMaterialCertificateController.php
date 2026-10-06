<?php

namespace App\Http\Controllers;

use App\Models\MaterialCertificate;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DownloadMaterialCertificateController extends Controller
{
    /**
     * Hand back one certificate file.
     *
     * The only way to read one. They live on the private disk precisely so that possessing the id
     * is not possessing the file - a mill certificate names the customer, the project and the heat
     * of steel it was poured from.
     */
    public function __invoke(MaterialCertificate $materialCertificate): StreamedResponse
    {
        /*
         * Whichever parent this one has. Both policies ask the same question - is this your
         * business's work - and a certificate is exactly as private as the thing it is evidence for.
         */
        Gate::authorize('owned', $materialCertificate->batch_id !== null
            ? $materialCertificate->batch
            : $materialCertificate->order);

        /*
         * 410 rather than 404 where the file reached the end of its retention period and was
         * deliberately disposed of. The distinction is worth a status code: "this was never here"
         * sends somebody looking for a bug, and "this was here and was destroyed on purpose, on this
         * date" is an answer - and it is the answer the certificate row still holds, with the
         * merchant, the heat and the uploader, for anybody who needs to say what used to be behind
         * the link. See config/retention.php.
         */
        //Not abort_if: PHP builds the arguments first, and the message reads a date that is null on
        //every certificate that has not been disposed of, which is all but a handful of them
        if ($materialCertificate->fileWasDisposedOf()) {
            abort(
                410,
                'This certificate file was disposed of on '
                .$materialCertificate->file_disposed_at->toFormattedDateString()
                .', at the end of its retention period. The record of the certificate itself is kept.',
            );
        }

        //The row can outlive its file if the disk was cleared out from under it
        abort_unless($materialCertificate->disk()->exists($materialCertificate->path), 404);

        //Downloaded under the name it arrived with, not the generated one it is stored under
        return $materialCertificate->disk()->download(
            $materialCertificate->path,
            $materialCertificate->original_filename,
        );
    }
}
