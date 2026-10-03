<?php

namespace App\Http\Controllers;

use App\Models\MaterialCertificate;
use App\Models\Order;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class MaterialCertificateController extends Controller
{
    /**
     * Attach one or more certificate files to an order.
     *
     * Separate from OrderController::update, which owns the written reference. Keeping them apart
     * means saving a PO number stays a plain JSON request rather than a multipart one, and a failed
     * upload cannot take an unrelated field down with it.
     */
    public function store(Request $request, Order $order): RedirectResponse
    {
        Gate::authorize('owned', $order);

        $validated = $request->validate([
            'certificates' => ['required', 'array', 'min:1', 'max:20'],
            /*
             * What a merchant actually sends: a PDF, a scan, or a photo of the docket taken in the
             * yard. Held to an extension AND a mime type - an extension alone is whatever the
             * uploader typed, and this file is evidence.
             */
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

        foreach ($validated['certificates'] as $file) {
            /*
             * Stored under a generated name on the private disk. The original name is kept in the
             * row and never used as a path: two merchants both sending "cert.pdf" must not collide,
             * and a filename off the internet must not decide where anything lands.
             */
            $path = $file->store('material-certificates/'.$order->id, MaterialCertificate::DISK);

            MaterialCertificate::create([
                'order_id' => $order->id,
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
     * Remove the specified resource from storage.
     */
    public function destroy(MaterialCertificate $materialCertificate): RedirectResponse
    {
        //The certificate has no owner of its own - it is as private as the thing it hangs off
        Gate::authorize('owned', $materialCertificate->batch_id !== null
            ? $materialCertificate->batch
            : $materialCertificate->order);

        /*
         * Refused once the order has been placed - see MaterialCertificate::isDeletable for why, and
         * for what to do instead. Answered rather than aborted: this is reachable from a button the
         * page draws, and somebody who has just attached the wrong file deserves to be told what the
         * right move is rather than handed a 403.
         */
        if (! $materialCertificate->isDeletable()) {
            return back()->withErrors([
                'certificate' => 'This certificate belongs to '
                    .($materialCertificate->batch_id !== null
                        ? 'a batch that has been closed'
                        : 'an order that has already been placed')
                    .', so it is part of that record and cannot be removed. Attach the correct '
                    .'certificate instead - both will show, and the traceability trail will report '
                    .'both.',
            ]);
        }

        $materialCertificate->deleteWithFile();

        return back();
    }
}
