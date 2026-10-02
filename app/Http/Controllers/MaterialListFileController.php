<?php

namespace App\Http\Controllers;

use App\Models\MaterialListFile;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class MaterialListFileController extends Controller
{
    /**
     * Take a whole upload back off the job - the file, its materials, and everything downstream.
     *
     * The thing somebody actually wants when the wrong revision has been uploaded onto the open
     * batch. Row by row through the per-project BOM was the only way before this, which means
     * recognising one spreadsheet's steel by eye in a table carrying several projects'.
     *
     * Three rules, each answered rather than aborted. This is reachable from a button the Bill of
     * Materials modal draws, and the button is drawn from the same three answers the batch BOM sends
     * back with each file - so a 403 here would mean the two had drifted apart. Somebody who has just
     * pressed it deserves to be told which of their steel is already on order.
     */
    public function destroy(Request $request, MaterialListFile $materialListFile): RedirectResponse
    {
        //The file has no owner of its own - it is as private as the project it was uploaded against
        Gate::authorize('owned', $materialListFile->project);

        /*
         * Changing a material list is narrower than reading one: the project's manager, or whoever
         * uploaded for them. The same question PrerequisiteConditions::uploadMaterials and
         * RawMaterialQuote::scopeOwnedByUser ask, because this is that same job done to a whole
         * upload at once - see Project::isManagedBy.
         */
        if (! $materialListFile->project->isManagedBy($request->user())) {
            return back()->withErrors([
                'materialListFile' => 'This file was uploaded against a colleague\'s project. Only '
                    .'its project manager, or whoever uploaded it, can remove it.',
            ]);
        }

        /*
         * And the steel itself. Named rather than counted, for the same reason the modal names them:
         * "which ones" is the next question, and the answer decides whether this was a wrong revision
         * worth chasing or a file that can simply be left alone.
         */
        if (! $materialListFile->isDeletable()) {
            return back()->withErrors([
                'materialListFile' => $this->committedMessage($materialListFile),
            ]);
        }

        $materialListFile->deleteWithRows($this->businessOf($request));

        return back();
    }

    /**
     * What to say about an upload whose steel has been committed to.
     *
     * Two different refusals, because they are two different situations: material that has been
     * quoted or ordered is a promise to somebody outside the business, while material merely nested
     * into a batch is a promise to nobody yet - it is just that the batch is being priced as a whole
     * and pulling a file out of it would leave its nest and order lists describing steel that is no
     * longer on the job. See MaterialListFile::isDeletable.
     */
    private function committedMessage(MaterialListFile $materialListFile): string
    {
        $committed = $materialListFile->committedRows();

        if ($committed->isNotEmpty()) {
            $names = $committed->take(3)->map(fn ($row) => $row->description)->implode(', ');

            $andMore = $committed->count() > 3 ? ' and '.($committed->count() - 3).' more' : '';

            return 'This file cannot be removed: '.$committed->count().' of its materials have '
                .'already been quoted or ordered ('.$names.$andMore.'). Delete the rows that have '
                .'not, from the project\'s own Bill of Materials.';
        }

        return 'This file\'s materials have been nested into a batch, so they are being bought as '
            .'part of it and cannot be removed here.';
    }
}
