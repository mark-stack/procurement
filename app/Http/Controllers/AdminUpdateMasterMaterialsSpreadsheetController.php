<?php

namespace App\Http\Controllers;

use App\Jobs\AdminMaterialsImport;
use App\Services\MasterMaterialsParser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Queue\Events\UniqueJobSkipped;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Throwable;

class AdminUpdateMasterMaterialsSpreadsheetController extends Controller
{
    public const FILE = 'master_materials.csv';

    /**
     * Handle the incoming request.
     */
    public function __invoke(Request $request, MasterMaterialsParser $parser): RedirectResponse
    {
        if (! Storage::exists(self::FILE)) {
            return back()->with('materialsImport', [
                'ok' => false,
                /*
                 * Name the disk that was actually searched. The message hardcoded
                 * 'storage/app/private', which is only where the local disk happens to look.
                 */
                'messages' => [sprintf(
                    '%s not found on the %s disk.',
                    self::FILE,
                    config('filesystems.default'),
                )],
            ]);
        }

        /*
         * Read through the Storage disk rather than a hardcoded storage_path(). The existence
         * check above and the read are then guaranteed to be talking about the same file.
         */
        $handle = Storage::readStream(self::FILE);

        if (! is_resource($handle)) {
            return back()->with('materialsImport', [
                'ok' => false,
                'messages' => [self::FILE.' could not be read from the configured disk.'],
            ]);
        }

        try {
            $result = $parser->parse($handle);
        } catch (Throwable $exception) {
            return back()->with('materialsImport', [
                'ok' => false,
                'messages' => [$exception->getMessage()],
            ]);
        } finally {
            fclose($handle);
        }

        if ($result->rows === []) {
            return back()->with('materialsImport', [
                'ok' => false,
                'messages' => ['No usable rows found in '.self::FILE.'. Nothing was imported.', ...$result->messages()],
            ]);
        }

        /*
         * AdminMaterialsImport is ShouldBeUnique, and a dispatch the unique lock blocks is
         * skipped without a word: the admin would be told the import had started when nothing
         * was queued at all. Laravel announces the skip, so the answer can be honest about it.
         */
        $alreadyRunning = false;

        Event::listen(UniqueJobSkipped::class, function () use (&$alreadyRunning) {
            $alreadyRunning = true;
        });

        AdminMaterialsImport::dispatch($result->collection(), $result->messages());

        if ($alreadyRunning) {
            return back()->with('materialsImport', [
                'ok' => false,
                'messages' => [
                    'A master materials import is already queued or running. Nothing was started - wait for its email before running another.',
                ],
            ]);
        }

        return back()->with('materialsImport', [
            'ok' => true,
            'messages' => [
                'Master materials import started. You will be emailed when it finishes.',
                ...$result->messages(),
            ],
        ]);
    }
}
