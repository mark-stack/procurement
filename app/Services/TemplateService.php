<?php

namespace App\Services;

use App\Imports\ExcelImport;
use Maatwebsite\Excel\Exceptions\NoSheetsFoundException;
use Maatwebsite\Excel\Exceptions\NoTypeDetectedException;
use Maatwebsite\Excel\Exceptions\UnreadableFileException;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\Reader\Exception as ReaderException;

class TemplateService
{
    public function invalidFiles(array $files): array
    {
        //Services
        $csvService = new CsvService;

        $invalidFiles = [];
        $validFiles = []; //Not yet used

        foreach($files as $file){
            $path = $file->store('uploads');

            //Read the CSV
            $csvArray = null;
            try {
                $csvArray = Excel::toArray(new ExcelImport, $file)[0];

                if($csvService->validateTemplateExists($csvArray)){
                    //Not yet used
                    $validFiles[] = $file->getClientOriginalName();
                }
                else{
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
                    //Still clean up the temp file before the exception unwinds
                    $this->deleteTempFile($path);

                    throw $e;
                }

                $invalidFiles[] = $file->getClientOriginalName();
            }

            // Delete the file after processing
            $this->deleteTempFile($path);
        }

        return $invalidFiles;
    }

    private function deleteTempFile(string $path): void
    {
        $fullPath = storage_path("app/private/{$path}");

        if (is_file($fullPath)) {
            unlink($fullPath);
        }
    }
}
