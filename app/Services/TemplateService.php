<?php

namespace App\Services;

use App\Imports\ExcelImport;
use Maatwebsite\Excel\Facades\Excel;

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
            catch (\Throwable $e) {
                $invalidFiles[] = $file->getClientOriginalName();
            }

            // Delete the file after processing
            unlink(storage_path("app/private/{$path}"));
        }

        return $invalidFiles;
    }
}
