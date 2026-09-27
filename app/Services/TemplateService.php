<?php

namespace App\Services;

use App\Imports\ExcelImport;
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
     * Returns ['invalid' => [filename, ...], 'tables' => [index => detectedTables, ...]],
     * keyed by the file's position in $files. The detected tables are handed back because
     * the caller used to throw them away and start again from the file: two full
     * spreadsheet parses and three template-detection passes per upload, five uploads
     * to a submit.
     *
     * @param  array<int, UploadedFile>  $files
     * @return array{invalid: array<int, string>, tables: array<int, array>}
     */
    public function readFiles(array $files): array
    {
        //Services
        $csvService = new CsvService;

        //Same for every file in the submit - it only depends on who is uploading
        $eligibleTables = $csvService->eligibleTables();

        $invalidFiles = [];
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
                    $invalidFiles[] = $file->getClientOriginalName();
                }
            }
            /*
             * The upload genuinely isn't a readable spreadsheet.
             * Nothing to investigate - tell the user about the file.
             */
            catch (ReaderException|UnreadableFileException|NoTypeDetectedException|NoSheetsFoundException $e) {
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

                $invalidFiles[] = $file->getClientOriginalName();
            }
        }

        return ['invalid' => $invalidFiles, 'tables' => $tables];
    }

    /**
     * @param  array<int, UploadedFile>  $files
     * @return array<int, string>
     */
    public function invalidFiles(array $files): array
    {
        return $this->readFiles($files)['invalid'];
    }
}
