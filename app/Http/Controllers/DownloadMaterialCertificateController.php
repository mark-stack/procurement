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
        Gate::authorize('owned', $materialCertificate->order);

        //The row can outlive its file if the disk was cleared out from under it
        abort_unless($materialCertificate->disk()->exists($materialCertificate->path), 404);

        //Downloaded under the name it arrived with, not the generated one it is stored under
        return $materialCertificate->disk()->download(
            $materialCertificate->path,
            $materialCertificate->original_filename,
        );
    }
}
