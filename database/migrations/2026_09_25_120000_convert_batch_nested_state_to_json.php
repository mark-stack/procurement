<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

return new class extends Migration
{
    /**
     * nested_state held PHP-serialized stdClass graphs, which tied every saved batch to the exact
     * class shapes of the release that wrote it. Convert the stored nesting to JSON.
     *
     * Read through the query builder rather than the Batch model, so the NestedState cast is not
     * applied on the way in or out and each row is converted exactly once.
     */
    public function up(): void
    {
        DB::table('batches')
            ->whereNotNull('nested_state')
            ->orderBy('id')
            ->chunkById(100, function ($batches) {
                foreach ($batches as $batch) {
                    $value = ltrim((string) $batch->nested_state);

                    //Already JSON (written after this change shipped)
                    if ($value === '' || str_starts_with($value, '{') || str_starts_with($value, '[')) {
                        continue;
                    }

                    $decoded = @unserialize($batch->nested_state);

                    if (! is_array($decoded)) {
                        Log::warning('Could not convert nested_state to JSON', ['batch_id' => $batch->id]);

                        continue;
                    }

                    DB::table('batches')
                        ->where('id', $batch->id)
                        ->update(['nested_state' => json_encode($decoded)]);
                }
            });
    }

    /**
     * Back to PHP-serialized arrays. Products come back as arrays rather than the stdClass objects
     * the original release wrote, which the readers of that release handled via (array) casts.
     */
    public function down(): void
    {
        DB::table('batches')
            ->whereNotNull('nested_state')
            ->orderBy('id')
            ->chunkById(100, function ($batches) {
                foreach ($batches as $batch) {
                    $value = ltrim((string) $batch->nested_state);

                    if ($value === '' || (! str_starts_with($value, '{') && ! str_starts_with($value, '['))) {
                        continue;
                    }

                    $decoded = json_decode($batch->nested_state, true);

                    if (! is_array($decoded)) {
                        continue;
                    }

                    DB::table('batches')
                        ->where('id', $batch->id)
                        ->update(['nested_state' => serialize($decoded)]);
                }
            });
    }
};
