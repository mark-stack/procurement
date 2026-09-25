<?php

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

/**
 * The nesting saved against a batch.
 *
 * Stored as JSON, keyed by nesting algo:
 *
 *   {"METERAGE": [ {product spec, "pieces": [...], "nested": {...}}, ... ], "BUNDLE": [...]}
 *
 * Consumers read a product as an object (`$product->nested`) whose values are arrays
 * (`$product->nested['utilisedBars']`), so decoding is associative and only the product level is
 * cast back to an object. json_decode's own object mode would make every level a stdClass and
 * break the array access.
 *
 * @implements CastsAttributes<array<string, array<int, object>>, array<string, mixed>>
 */
class NestedState implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): array
    {
        if (blank($value)) {
            return [];
        }

        $decoded = $this->decode($value, $model, $key);

        $result = [];
        foreach ($decoded as $algo => $products) {
            if (! is_array($products)) {
                continue;
            }

            $result[$algo] = array_map(
                fn ($product) => is_array($product) ? (object) $product : $product,
                array_values($products),
            );
        }

        return $result;
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): array
    {
        if (blank($value)) {
            return [$key => null];
        }

        return [$key => json_encode($value, JSON_THROW_ON_ERROR)];
    }

    private function decode(string $value, Model $model, string $key): array
    {
        /*
         * Batches nested before the move to JSON hold PHP-serialized stdClass graphs. The migration
         * converts them, but reading both keeps a batch legible either side of that migration.
         */
        if (! str_starts_with(ltrim($value), '{') && ! str_starts_with(ltrim($value), '[')) {
            $legacy = @unserialize($value);

            return is_array($legacy)
                ? json_decode(json_encode($legacy), true)
                : [];
        }

        $decoded = json_decode($value, true);

        if (! is_array($decoded)) {
            Log::error('Unreadable nested_state', [
                'model' => $model::class,
                'id' => $model->getKey(),
                'attribute' => $key,
            ]);

            return [];
        }

        return $decoded;
    }
}
