<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

return new class extends Migration
{
    /**
     * items_not_found held a PHP-serialized flat list of descriptions, read back with a bare
     * unserialize() - a fatal TypeError on any value that did not round-trip. It also merged
     * two different causes (nothing in the price book matched / matched but outside the plan)
     * into one list the modal then labelled with only the first.
     *
     * Convert to JSON keyed by cause. Legacy rows cannot say which cause they were, and the
     * label they were shown under was "not recognised", so they convert to notRecognised.
     *
     * Read through the query builder rather than the Project model, so the array cast is not
     * applied on the way in or out and each row is converted exactly once.
     */
    public function up(): void
    {
        DB::table('projects')
            ->whereNotNull('items_not_found')
            ->orderBy('id')
            ->chunkById(100, function ($projects) {
                foreach ($projects as $project) {
                    $value = ltrim((string) $project->items_not_found);

                    //Already JSON (written after this change shipped)
                    if ($value === '' || str_starts_with($value, '{') || str_starts_with($value, '[')) {
                        continue;
                    }

                    $decoded = @unserialize($value);

                    if (! is_array($decoded)) {
                        Log::warning('Could not convert items_not_found to JSON', ['project_id' => $project->id]);

                        continue;
                    }

                    DB::table('projects')
                        ->where('id', $project->id)
                        ->update([
                            'items_not_found' => json_encode([
                                'notRecognised' => array_values(array_unique($decoded)),
                                'otherPlan' => [],
                            ]),
                        ]);
                }
            });
    }

    /**
     * Back to a PHP-serialized flat list. The two causes collapse back into one, which is
     * what the previous release stored anyway.
     */
    public function down(): void
    {
        DB::table('projects')
            ->whereNotNull('items_not_found')
            ->orderBy('id')
            ->chunkById(100, function ($projects) {
                foreach ($projects as $project) {
                    $value = ltrim((string) $project->items_not_found);

                    if ($value === '' || ! str_starts_with($value, '{')) {
                        continue;
                    }

                    $decoded = json_decode($value, true);

                    if (! is_array($decoded)) {
                        continue;
                    }

                    $flat = array_merge($decoded['notRecognised'] ?? [], $decoded['otherPlan'] ?? []);

                    DB::table('projects')
                        ->where('id', $project->id)
                        ->update(['items_not_found' => serialize(array_values(array_unique($flat)))]);
                }
            });
    }
};
