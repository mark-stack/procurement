<?php

namespace App\Http\Requests;

use App\Enums\OffcutRemovalEnums;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class RemoveOffcutRequest extends FormRequest
{
    public function authorize(): bool
    {
        //Which offcut may be removed is a question about the business's inventory, not about the
        //request body - OffcutRemoveController resolves it out of that inventory or 404s
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'reason' => ['required', Rule::enum(OffcutRemovalEnums::class)],
            //Long enough for "Dave took it for the Jarrah St handrails", short enough to stay a note
            'note' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * "Other" with nothing beside it records that something happened and nothing about what, which is
     * the one removal nobody can make sense of a month later. Every other reason says enough on its
     * own, so the note stays optional there.
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                $reason = OffcutRemovalEnums::tryFrom((string) $this->input('reason'));

                if ($reason?->requiresNote() && trim((string) $this->input('note')) === '') {
                    $validator->errors()->add('note', 'Say what happened to it.');
                }
            },
        ];
    }

    public function reason(): OffcutRemovalEnums
    {
        return OffcutRemovalEnums::from($this->validated('reason'));
    }

    public function note(): ?string
    {
        $note = trim((string) $this->validated('note'));

        return $note === '' ? null : $note;
    }
}
