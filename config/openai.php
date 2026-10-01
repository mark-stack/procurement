<?php

/*
 * The only place in this app that talks to OpenAI is App\Services\OpenAiService, and everything that
 * asks it anything is about reading a customer's spreadsheet: the proposal that describes a table, and
 * the review that reads the extracted rows back to check the description was right.
 *
 * This used to say "nothing here is load bearing", and that was true while the only caller was an
 * admin filling in a form more quickly than they could type it. It is not true any more. A customer's
 * upload that matches none of their templates is read here and becomes a template, which is the whole
 * of how a new business sets itself up - see App\Services\TemplateLearningService. With no key, every
 * unrecognised spreadsheet is a failed attempt waiting on one of us, which is the queue the feature
 * was built to empty.
 *
 * It still degrades rather than breaks, and the tests still run with no key: an absent one is a
 * reduced product, never an error, and never a 500 on a customer's upload.
 */
return [

    /*
    |--------------------------------------------------------------------------
    | Credentials and model
    |--------------------------------------------------------------------------
    |
    | Both come from the environment. There is no default key on purpose: a missing key must
    | read as "not configured" rather than as a key that fails at the API.
    |
    */

    'key' => env('OPENAI_API_KEY'),

    'model' => env('OPENAI_MODEL', 'gpt-5-mini'),

    /*
     * Overridable so a compatible gateway or proxy can be pointed at without a code change.
     * Chat Completions with a strict JSON schema is what OpenAiService asks for, which every
     * current OpenAI model and most compatible gateways support.
     */
    'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com/v1'),

    /*
     * Seconds. An admin is sat in front of the form waiting, so this is a "give up and say so"
     * limit rather than a generous one. A reasoning model on a large sheet can genuinely take
     * half a minute.
     */
    'timeout' => (int) env('OPENAI_TIMEOUT', 60),

    /*
     * The answer is a dozen short fields, so the ceiling exists to bound a runaway rather than
     * to fit the reply. Reasoning models spend this budget on thinking too, hence the headroom.
     */
    'max_output_tokens' => (int) env('OPENAI_MAX_OUTPUT_TOKENS', 4000),

    /*
    |--------------------------------------------------------------------------
    | What may leave this server
    |--------------------------------------------------------------------------
    |
    | A customer's spreadsheet is sent to OpenAI to be read, so the amount of it that goes is
    | capped rather than unbounded: the top-left corner is where a template's heading row and
    | first data row always are, and it is all the parser needs to place the cells.
    |
    | These caps bound the prompt's cost as well as its contents.
    |
    */

    'grid' => [
        'max_rows' => (int) env('OPENAI_GRID_MAX_ROWS', 60),
        'max_columns' => (int) env('OPENAI_GRID_MAX_COLUMNS', 60),
        'max_cell_characters' => (int) env('OPENAI_GRID_MAX_CELL_CHARACTERS', 60),
        'max_characters' => (int) env('OPENAI_GRID_MAX_CHARACTERS', 20000),
    ],

];
