<?php

namespace App\Http\Controllers;

use App\Models\Business;
use App\Models\Template;
use Symfony\Component\HttpFoundation\Response;

class AdminTemplateScreenshotController extends Controller
{
    /*
     * The screenshot of a recorded template never changes without the row changing, so the
     * browser is told it can keep it. A week, revalidated against the ETag below, which is
     * what turns "every screenshot on every visit" into one request per template.
     *
     * private: it is a customer's document, so it must not be held by a shared cache.
     */
    private const CACHE_SECONDS = 604_800;

    /**
     * Serve one recorded template's screenshot as an image file.
     *
     * It used to travel inline in the index props, up to 750KB of base64 per template, on
     * every visit to the page and on the redirect back after every create, update and
     * delete - to render a 128x80 thumbnail.
     */
    public function __invoke(Business $business, Template $template): Response
    {
        $bytes = $template->decodedScreenshot();

        /*
         * Rows recorded before the data-URL rule existed can hold anything at all, and the
         * screenshot column is only ever read here, so there is nothing to serve and
         * nothing broken. The page shows its placeholder.
         */
        if ($bytes === null) {
            abort(404);
        }

        return response($bytes, 200, [
            'Content-Type' => $template->screenshotMimeType(),
            'Cache-Control' => 'private, max-age='.self::CACHE_SECONDS,
            /*
             * The row's own version. Editing a template changes updated_at, so the cached
             * copy is replaced rather than being served until the week is out.
             */
            'ETag' => '"'.md5($template->id.'-'.$template->updated_at?->getTimestamp()).'"',
            /*
             * inline, and a filename with no user-controlled characters in it: the name is
             * an admin-entered string, and it does not belong in a header.
             */
            'Content-Disposition' => 'inline; filename="template-'.$template->id.'-screenshot"',
            //Belt and braces against a stored payload being sniffed as anything but an image
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
