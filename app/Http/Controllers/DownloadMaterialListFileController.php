<?php

namespace App\Http\Controllers;

use App\Models\MaterialListFile;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DownloadMaterialListFileController extends Controller
{
    /**
     * Hand back the spreadsheet a material list was imported from.
     *
     * The only way to read one. They live on the private disk precisely so that possessing the id is
     * not possessing the file - a bill of materials is a customer's job, every length of it.
     *
     * Open to anyone in the business, like the batch BOM it is listed on: the Nesting page is shared,
     * a batch carries several managers' work, and the question "which revision is this steel off" is
     * asked by whoever is buying it. Changing a material list is the narrower right - see the destroy
     * controller.
     */
    public function __invoke(MaterialListFile $materialListFile): StreamedResponse
    {
        //The file has no owner of its own - it is as private as the project it was uploaded against
        Gate::authorize('owned', $materialListFile->project);

        //A row can outlive its file: the disk refused it on upload, or was cleared out from under it
        abort_unless($materialListFile->isDownloadable(), 404);

        //Downloaded under the name it arrived with, not the generated one it is stored under
        return $materialListFile->disk()->download(
            $materialListFile->path,
            $materialListFile->original_filename,
        );
    }
}
