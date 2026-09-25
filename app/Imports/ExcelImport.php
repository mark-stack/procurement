<?php

namespace App\Imports;

use Maatwebsite\Excel\Concerns\ToArray;
use Maatwebsite\Excel\Concerns\WithCalculatedFormulas;

class ExcelImport implements ToArray, WithCalculatedFormulas
{
    public function array(array $array): void
    {
        // The Excel file will be converted to an array
    }
}
