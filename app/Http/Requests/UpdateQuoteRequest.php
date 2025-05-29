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
        return [
            'batch_id' => 'required',
            'quote_sent' => 'required',
            'supplier_quote_reference' => 'nullable',
            'quoted_price' => 'nullable',
            'quoted_lead_time' => 'nullable',
        ];
    }
}
