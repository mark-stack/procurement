<?php

namespace App\Http\Controllers;

use App\Formatters\NestingFormatter;
use App\Formatters\SupplierFormatter;
use App\Models\Batch;
use App\Models\Business;
use App\Models\MaterialCertificate;
use App\Models\Piece;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class BatchCertificateController extends Controller
{
    /**
     * The mill certificates held against a batch itself, a merchant at a time.
     *
     * MaterialCertificateController's counterpart for the shop that has no orders to attach them to.
     * Everything about the files is the same - same table, same private disk, same download route -
     * and the two differences are which parent the row points at and the merchant written beside it
     * (see the 2026_10_03_130000 and _150000 migrations).
     *
     * A merchant at a time because a certificate belongs to one: the steel merchant's heat numbers
     * are not the aluminium supplier's, and one pile of PDFs against the whole batch is the filing
     * cabinet this is meant to replace. Only the groups whose products come with a certificate are
     * offered - a bag of bolts never gets one, and a box asking for theirs would be asking for
     * paperwork that is not coming.
     *
     * Read over axios rather than as a page prop, the way the BOM modal reads its own contents: the
     * Nesting page draws a card per batch and almost nobody opens this, so a list of files per card
     * on every render would be a query nobody asked for.
     */
    public function index(Batch $batch): JsonResponse
    {
        Gate::authorize('owned', $batch);

        $business = auth()->user()->business;

        $certificates = $batch->certificates()->with('user:id,name')->latest('id')->get();

        $groups = [];

        foreach ($this->certificatedGroups($batch, $business) as $supplierGroup) {
            $groups[] = [
                'supplierGroup' => $supplierGroup,
                'certificates' => $certificates
                    ->filter(fn (MaterialCertificate $certificate) => $certificate->supplier_group === $supplierGroup)
                    ->map(fn (MaterialCertificate $certificate) => $this->certificate($certificate))
                    ->values(),
            ];
        }

        return response()->json([
            //Which batch answered, so a late reply cannot be drawn into another card's modal
            'batch_id' => $batch->id,
            'groups' => $groups,
        ]);
    }

    /**
     * Attach one or more certificate files to one merchant's material on the batch.
     *
     * The same rules as the order-side upload, deliberately to the letter: a merchant sends what a
     * merchant sends, and a PDF that is acceptable evidence against an order is acceptable evidence
     * against a batch. Held to an extension AND a mime type - an extension alone is whatever the
     * uploader typed, and this file is evidence.
     */
    public function store(Request $request, Batch $batch): RedirectResponse
    {
        Gate::authorize('owned', $batch);

        /*
         * A closed batch takes no more paperwork. It is a past batch, its certificates are its
         * record, and MaterialCertificate::isDeletable has already stopped them being removed - this
         * is the same line drawn on the way in.
         */
        abort_if($batch->done, 403);

        $validated = $request->validate([
            'supplier_group' => ['required', 'string', 'max:191'],
            'certificates' => ['required', 'array', 'min:1', 'max:20'],
            'certificates.*' => [
                'required',
                'file',
                'max:20480', //20MB each, which is a generous scan
                'mimes:pdf,png,jpg,jpeg,webp,heic,tif,tiff',
            ],
        ], [
            'certificates.*.mimes' => 'A certificate must be a PDF or an image (:values).',
            'certificates.*.max' => 'Each certificate must be 20MB or smaller.',
        ]);

        $supplierGroup = $validated['supplier_group'];

        /*
         * A merchant this batch actually buys from, whose material comes with a certificate. The name
         * arrives from the page, and a file filed under a group the order list will never ask about
         * is a file nobody sees again.
         */
        abort_unless(
            in_array($supplierGroup, $this->certificatedGroups($batch, auth()->user()->business), true),
            422,
        );

        foreach ($validated['certificates'] as $file) {
            /*
             * Stored under a generated name on the private disk, in a folder of its own: two
             * merchants both sending "cert.pdf" must not collide, and a filename off the internet
             * must not decide where anything lands.
             */
            $path = $file->store('material-certificates/batch-'.$batch->id, MaterialCertificate::DISK);

            MaterialCertificate::create([
                'batch_id' => $batch->id,
                'supplier_group' => $supplierGroup,
                'user_id' => auth()->id(),
                'path' => $path,
                'original_filename' => $file->getClientOriginalName(),
                'mime_type' => $file->getClientMimeType(),
                'size_bytes' => $file->getSize(),
            ]);
        }

        return back();
    }

    /**
     * One certificate as the modal lists it.
     *
     * Who attached it and when travel with the filename, which is half of what a certificate trail
     * is for: a heat number answers what the steel was, and a name and a date answer who said so.
     *
     * @return array<string, mixed>
     */
    private function certificate(MaterialCertificate $certificate): array
    {
        return [
            'id' => $certificate->id,
            'filename' => $certificate->original_filename,
            'size_bytes' => $certificate->size_bytes,
            'uploaded_by' => $certificate->user?->name,
            'uploaded_at' => $certificate->created_at?->toDateString(),
            //Whether the Remove button is drawn - see MaterialCertificate::isDeletable
            'deletable' => $certificate->isDeletable(),
        ];
    }

    /**
     * The merchants on this batch whose material comes with a mill certificate.
     *
     * Two questions at once, and both are asked elsewhere in the same words: which supplier groups
     * this batch's material falls into (SupplierFormatter::supplierGroups, which is how the order
     * list blocks the batch up) and which product categories come with a certificate at all
     * (products.certificates, which is what the BOM's certificate column reads).
     *
     * Read off the pieces rather than off the saved nest: the nest is what to buy, and this is about
     * the material on the job, which is the same list of categories either way and one query.
     *
     * @return array<int, string>
     */
    private function certificatedGroups(Batch $batch, Business $business): array
    {
        $categories = Piece::query()
            ->where('batch_id', $batch->id)
            ->distinct()
            ->pluck('product_category')
            ->filter()
            ->all();

        $certificated = (new NestingFormatter)->getCertificateProductLabels();

        $groups = [];

        foreach ((new SupplierFormatter)->supplierGroups($business) as $supplierGroup => $includedProducts) {
            $onThisBatch = array_intersect($categories, $includedProducts);

            if ($onThisBatch === [] || array_intersect($onThisBatch, $certificated) === []) {
                continue;
            }

            $groups[] = $supplierGroup;
        }

        return $groups;
    }
}
