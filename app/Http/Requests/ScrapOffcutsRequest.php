<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ScrapOffcutsRequest extends FormRequest
{
    /**
     * How many may go in one press.
     *
     * Not a security limit - the controller intersects whatever arrives with the business's own
     * cleanout list, so a longer array simply scraps fewer rows than it names. It is a bound on the
     * work one request does, and on how much steel one mis-click can write off.
     */
    private const MAX_IN_ONE_GO = 200;

    public function authorize(): bool
    {
        //Which offcuts may be scrapped is a question about this business's cleanout list, not about
        //the request body - OffcutScrapController resolves it there
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'offcut_ids' => ['required', 'array', 'min:1', 'max:'.self::MAX_IN_ONE_GO],
            'offcut_ids.*' => ['integer'],
            //Why this rack was cleared, in the words of whoever cleared it. Optional: the reason
            //itself already says what happened, which is not true of a removal marked "Other"
            'note' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'offcut_ids.required' => 'Tick the offcuts to scrap.',
            'offcut_ids.max' => 'Scrap up to '.self::MAX_IN_ONE_GO.' offcuts at a time.',
        ];
    }

    /**
     * @return array<int, int>
     */
    public function offcutIds(): array
    {
        return array_values(array_unique(array_map('intval', (array) $this->validated('offcut_ids'))));
    }

    public function note(): ?string
    {
        $note = trim((string) $this->validated('note'));

        return $note === '' ? null : $note;
    }
}
