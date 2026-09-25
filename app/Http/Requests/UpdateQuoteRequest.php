<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateQuoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        /*
         * batch_id is deliberately absent. The page sends it, but this endpoint never reads it - and
         * because validated() feeds straight into $quote->update() on a $guarded = [] model, accepting
         * it let anyone move their own quote onto another business's batch.
         */
        return [
            'quote_sent' => ['required', 'boolean'],
            'supplier_quote_reference' => ['nullable', 'string', 'max:255'],
            'quoted_price' => ['nullable', 'numeric', 'min:0'],
            'quoted_lead_time' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
