<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use JsonException;
use RuntimeException;

/**
 * The whole of this app's contact with OpenAI: one call, one answer shape.
 *
 * Every caller wants structured data rather than prose, so the only method asks for a strict JSON
 * schema and hands back a decoded array. Anything that goes wrong - no key, a timeout, a 429, a
 * model that ignored the schema - throws RuntimeException with a sentence fit to show an admin,
 * because the feature this serves is a convenience and must degrade rather than break.
 */
class OpenAiService
{
    /**
     * Whether a key is configured at all. Callers check this first and skip the call entirely:
     * asking without a key wastes a request to be told what config already knows.
     */
    public function isConfigured(): bool
    {
        return filled(config('openai.key'));
    }

    public function model(): string
    {
        return (string) config('openai.model');
    }

    /**
     * Ask for one answer in the shape of $schema.
     *
     * $schema is a JSON Schema object. Structured Outputs is strict, which means every property
     * has to be listed in "required" and additionalProperties has to be false - a field that may
     * be absent is expressed as a nullable type instead. buildSchema() below is not clever about
     * this; the caller states the schema it wants.
     *
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     *
     * @throws RuntimeException
     */
    public function structuredJson(string $schemaName, array $schema, string $instructions, string $input): array
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('OPENAI_API_KEY is not set, so nothing was asked of OpenAI.');
        }

        $payload = [
            'model' => $this->model(),
            'messages' => [
                ['role' => 'system', 'content' => $instructions],
                ['role' => 'user', 'content' => $input],
            ],
            /*
             * strict: the model is constrained to this schema rather than asked politely for it,
             * so a missing field or an invented one is not a case the caller has to handle.
             */
            'response_format' => [
                'type' => 'json_schema',
                'json_schema' => [
                    'name' => $schemaName,
                    'strict' => true,
                    'schema' => $schema,
                ],
            ],
            /*
             * max_completion_tokens, not max_tokens: the older name is rejected outright by the
             * reasoning models, and OPENAI_MODEL is somebody's environment variable, not ours.
             *
             * Nothing sets "temperature" for the same reason - those models accept one value for
             * it and error on anything else, so the default is the only portable choice.
             */
            'max_completion_tokens' => (int) config('openai.max_output_tokens'),
        ];

        try {
            $response = Http::withToken((string) config('openai.key'))
                ->timeout((int) config('openai.timeout'))
                ->acceptJson()
                ->asJson()
                ->post(rtrim((string) config('openai.base_url'), '/').'/chat/completions', $payload);
        }
        //A timeout or DNS failure is not an exception an admin should see a stack trace for
        catch (ConnectionException $exception) {
            report($exception);

            throw new RuntimeException('OpenAI could not be reached, so nothing was suggested. Try again, or fill the form in by hand.');
        }

        if ($response->failed()) {
            /*
             * The API's own message names the real problem - an invalid key, a model this account
             * cannot use, a rate limit - and all three are things the admin reading this can act
             * on. The status is included because "invalid_api_key" and "rate_limit_exceeded" are
             * worth telling apart at a glance.
             */
            $message = $response->json('error.message') ?? 'no message';

            throw new RuntimeException("OpenAI refused the request ({$response->status()}): {$message}");
        }

        return $this->decodeContent($response->json());
    }

    /**
     * The answer lives in choices[0].message.content as a JSON string. A refusal arrives in a
     * "refusal" field instead, and a reply cut short by the token ceiling arrives as valid JSON
     * that stops mid-object - both are reported rather than returned as an empty answer.
     *
     * @param  array<string, mixed>|null  $body
     * @return array<string, mixed>
     */
    private function decodeContent(?array $body): array
    {
        $choice = $body['choices'][0] ?? null;

        if (filled($choice['message']['refusal'] ?? null)) {
            throw new RuntimeException('OpenAI declined to answer: '.$choice['message']['refusal']);
        }

        if (($choice['finish_reason'] ?? null) === 'length') {
            throw new RuntimeException('OpenAI ran out of output budget before finishing. Raise OPENAI_MAX_OUTPUT_TOKENS or use a smaller sample.');
        }

        $content = $choice['message']['content'] ?? null;

        if (! is_string($content) || trim($content) === '') {
            throw new RuntimeException('OpenAI answered with no content.');
        }

        try {
            $decoded = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('OpenAI answered with something that is not JSON.');
        }

        if (! is_array($decoded)) {
            throw new RuntimeException('OpenAI answered with JSON that is not an object.');
        }

        return $decoded;
    }
}
