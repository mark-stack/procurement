<?php

namespace App\Http\Requests;

use App\Models\User;
use App\Rules\BusinessEmailDomain;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProfileUpdateRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'string',
                'lowercase',
                'email',
                'max:255',
                Rule::unique(User::class)->ignore($this->user()->id),
                /*
                 * Only when the address is actually changing. Applied unconditionally this
                 * would lock anyone already on a personal address out of their own profile -
                 * they could not so much as correct a typo in their name without the form
                 * rejecting an email they never touched. The admin account is one of them.
                 *
                 * Changing your address does not move you to another business - business_id
                 * is set once, at registration - so this is about the promise the sign-up
                 * form makes rather than about tenancy.
                 */
                Rule::when(
                    $this->input('email') !== $this->user()->email,
                    [new BusinessEmailDomain],
                ),
            ],
        ];
    }
}
