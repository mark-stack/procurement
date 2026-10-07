<?php

namespace App\Services;

use App\Imports\ExcelImport;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Maatwebsite\Excel\Exceptions\NoSheetsFoundException;
use Maatwebsite\Excel\Exceptions\NoTypeDetectedException;
use Maatwebsite\Excel\Exceptions\UnreadableFileException;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\Reader\Exception as ReaderException;

class TemplateService
{
    /**
     * Single purpose: read each upload once and say which ones carry a template we know.
     *
     * The detected tables are handed back because the caller used to throw them away and start
     * again from the file: two full spreadsheet parses and three template-detection passes per
     * upload, five uploads to a submit.
     *
     * "invalid" used to be the whole answer and is now the least useful part of it. There are two
     * ways a file ends up in it, and they are no longer the same thing to the caller:
     *
     *  - "unmatched": a spreadsheet we read perfectly well and have no template for. This is now
     *    something to act on rather than refuse - TemplateLearningService writes the template for
     *    it - so it has to be told apart, and by index, because acting on it means going back to
     *    that file.
     *  - "unreadable": not a spreadsheet, or our own detection breaking on one. Nothing can be
     *    written for these and they are still refused outright.
     *
     * "invalid" is kept, as both of those by name, because invalidFiles() below is the question
     * several callers actually ask.
     *
     * @param  array<int, UploadedFile>  $files
     * @param  User|null  $user  Whose templates to match against; null means whoever is signed in.
     *                           See CsvService::detectTables() for why that is a question.
     * @return array{invalid: array<int, string>, unmatched: array<int, string>, unreadable: array<int, string>, tables: array<int, array>}
     */
    public function readFiles(array $files, ?User $user = null): array
    {
        //Services
        $csvService = new CsvService;

        //Same for every file in the submit - it only depends on whose templates they are read with
        $eligibleTables = $csvService->eligibleTables($user);

        $invalidFiles = [];
        $unmatched = [];
        $unreadable = [];
        $tables = [];

        foreach ($files as $index => $file) {
            /*
             * Excel::toArray() reads the uploaded temp file directly. Both this and the
             * controller used to $file->store('uploads') first and then never read the
             * copy back - a write and a delete per file, for nothing.
             */
            try {
                $csvArray = Excel::toArray(new ExcelImport, $file)[0];

                $detectedTables = $csvService->detectedTables($csvArray, $eligibleTables);

                if (count($detectedTables) > 0) {
                    $tables[$index] = $detectedTables;
                } else {
                    //Read fine, matched nothing. The learnable case
                    $unmatched[$index] = $file->getClientOriginalName();
                    $invalidFiles[] = $file->getClientOriginalName();
                }
            }
            /*
             * The upload genuinely isn't a readable spreadsheet.
             * Nothing to investigate - tell the user about the file.
             */
            catch (ReaderException|UnreadableFileException|NoTypeDetectedException|NoSheetsFoundException $e) {
                $unreadable[$index] = $file->getClientOriginalName();
                $invalidFiles[] = $file->getClientOriginalName();
            }
            /*
             * Anything else is our own detection code breaking on a file that may
             * be perfectly fine. Reporting that as "did this template change?" hides
             * the bug and sends the user off to re-calibrate a template that isn't
             * the problem. Log it, and let admins see the real exception.
             */
            catch (\Throwable $e) {
                report($e);

                if (auth()->user()?->isAdmin()) {
                    throw $e;
                }

                /*
                 * Unreadable rather than unmatched, even though the file may be perfectly fine: the
                 * fault is ours, and asking a model to describe a sheet our own detection just threw
                 * on would answer a question nobody asked.
                 */
                $unreadable[$index] = $file->getClientOriginalName();
                $invalidFiles[] = $file->getClientOriginalName();
            }
        }

        return [
            'invalid' => $invalidFiles,
            'unmatched' => $unmatched,
            'unreadable' => $unreadable,
            'tables' => $tables,
        ];
    }

    /**
     * Every upload that produced no tables, by name - unmatched and unreadable together.
     *
     * @param  array<int, UploadedFile>  $files
     * @return array<int, string>
     */
    public function invalidFiles(array $files): array
    {
        return $this->readFiles($files)['invalid'];
    }
}
