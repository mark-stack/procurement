<?php

namespace App\Formatters;

use App\Enums\GradeEnums;
use App\Enums\MaterialEnums;
use App\Enums\MeasurementUnitEnums;
use App\Enums\NestingEnums;
use App\Enums\ProductEnums;
use App\Enums\SurfaceEnums;
use App\Models\Business;
use App\Models\Offcut;
use App\Models\Piece;
use App\Models\Project;
use App\Models\RawMaterialQuote;
use App\Models\User;
use App\Services\ProductService;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;
use App\Imports\ExcelImport;
use Symfony\Component\HttpFoundation\File\File;

class TestingFormatter
{
    public function createBusiness(String $name, bool $adminSetupComplete): Business
    {
        return Business::create([
            'name' => $name,
            'domain' => str_replace(' ', '-', $name).'.com',
            'admin_setup_complete' => $adminSetupComplete,
        ]);
    }

    public function createUser(bool $isAdmin, int $id, Business $business, bool $emailVerified): User
    {
        return User::factory()->create([
            'name' => 'Mark',
            'email' => $isAdmin
                ? config('env.admin_email')
                : ($id.'@'.$business->domain),
            'business_id' => $business->id,
            'email_verified_at' => $emailVerified ? now() : null,
        ]);
    }

    public function createProject(User $user): Project
    {
        return Project::create([
            'name' => 'some project',
            'user_id' => $user->id,
            'reference' => 'ref',
            'date_materials_required' => null,
            'tentative' => true,
            'archive' => false,
        ]);
    }

    public function piecePfc(int $nominalHeight, int $length, int $qty, Project $project, object $dataClassificationService): array
    {
        $description = $nominalHeight.'PFC';
        $generalProductMatches = $dataClassificationService->findGeneralProductMatchesFromText($description, $project->user);

        return [
            'description' => $description,
            'material' => MaterialEnums::PLAIN_CARBON_STEEL->value,
            'grade' => GradeEnums::GR300,
            'surface' => SurfaceEnums::NONE,
            'nominal_units' => MeasurementUnitEnums::MILLIMETERS,
            'nominal_length' => null,
            'precise_length' => null,
            'nominal_width' => null,
            'precise_width' => null,
            'nominal_height' => $nominalHeight,
            'precise_height' => null,
            'length_required' => $length,
            'width_required' => null,
            'sub_qty' => $qty,
            'project_id' => $project->id,
            'general_product_matches' => serialize($generalProductMatches),
            'assembly_mark' => 'on the thing',
        ];
    }

    public function sampleBOM(Project $project, object $dataClassificationService, array $nest): array
    {
        $bom = [];
        foreach ($nest as $items) {
            $bom[] = $this->piecePfc(200, $items[0], $items[1], $project, $dataClassificationService);
        }

        return $bom;
    }

    public function createRawMaterialQuote(array $row, object $dataClassificationService, Project $project)
    {
        $productCategory = $dataClassificationService->findProductConfigFromText($row['description']);
        $productCategory = $productCategory ? $productCategory['productCategory'] : null;

        return RawMaterialQuote::create([
            'csv_index' => 999,
            'description' => $row['description'],
            'product_category' => $productCategory,
            'material' => $row['material'] ?? null,
            'grade' => $row['grade'] ?? null,
            'surface' => $row['surface'] ?? null,
            'nominal_units' => $dataClassificationService->findMeasurementUnit($productCategory),
            'length_required' => $row['length_required'] ?? null,
            'width_required' => $row['width_required'] ?? null,
            'sub_qty' => $row['sub_qty'],
            'project_id' => $project->id,
            'general_product_matches' => serialize($row['general_product_matches']),
            'assembly_mark' => $row['assembly_mark'] ?? '',
        ]);
    }

    function createPieces(array $sampleBOM, Project $project, object $dataClassificationService): array
    {
        $pieces = [];

        foreach ($sampleBOM as $row) {
            $rawMaterialQuote = $this->createRawMaterialQuote($row, $dataClassificationService, $project);

            //Create piece
            $piece = $this->createPiece($project, $rawMaterialQuote, $row);
            $pieces[] = $piece;
        }

        return $pieces;
    }

    function createPiece(Project $project, RawMaterialQuote $rawMaterialQuote, array $row): Piece
    {
        $lengthRequired = $row['length_required'];
        $widthRequired = $row['width_required'];

        return Piece::create([
            'project_id' => $project->id,
            'raw_material_quote_id' => $rawMaterialQuote->id,
            'product_category' => $rawMaterialQuote->product_category,
            'material' => $rawMaterialQuote->material,
            'grade' => $rawMaterialQuote->grade->value,
            'surface' => SurfaceEnums::NONE->value,
            'nominal_units' => MeasurementUnitEnums::MILLIMETERS->value,
            'nesting_algo' => NestingEnums::METERAGE,
            'nominal_length' => $row['length_required'] ?? null,
            'precise_length' => $row['precise_length'] ?? null,
            'nominal_width' => $row['nominal_width'] ?? null,
            'precise_width' => $row['precise_width'] ?? null,
            'nominal_height' => $row['nominal_height'] ?? null,
            'precise_height' => $row['precise_height'] ?? null,
            'actual_length' => $lengthRequired < 20 ? ($lengthRequired * 1000) : $lengthRequired,
            'actual_width' => $widthRequired < 20 ? ($widthRequired * 1000) : $widthRequired,
            'wall' => $row['wall'] ?? null,
            'kg_per_m' => $row['kg_per_m'] ?? null,
            'actual_qty' => $row['sub_qty'],
        ]);
    }

    public function csvArray(): ?array
    {
        $filePath = 'templatesForTesting/Monthly budget excel.xlsx';
        $csvArray = null;

        if (Storage::disk('local')->exists($filePath)) {
            $file = new File(Storage::path($filePath));
            $csvArray = Excel::toArray(new ExcelImport, $file)[0];
        }

        return $csvArray;
    }

    function create_offcut_200PFC(int $length, int $batchFromId): Offcut
    {
        return Offcut::create([
            'batch_from_id' => $batchFromId,
            'batch_to_id' => null,
            'piece_to_id' => null,
            'product_category' => ProductEnums::PFC,
            'material' => MaterialEnums::PLAIN_CARBON_STEEL,
            'grade' => GradeEnums::GR300,
            'surface' => SurfaceEnums::NONE,
            'nominal_length' => null,
            'precise_length' => null,
            'nominal_width' => null,
            'precise_width' => null,
            'nominal_height' => 200,
            'precise_height' => null,
            'wall' => null,
            'length' => $length,

            "unique_mark" => (new UniqueLetterIDGenerator())->generate(ProductEnums::PFC->value),
        ]);
    }
}
