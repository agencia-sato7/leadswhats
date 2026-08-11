<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CompleteWhatsAppEmbeddedSignupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:5000'],
            'access_token' => ['prohibited'],
            'embedded_signup' => ['required', 'array'],
            'embedded_signup.access_token' => ['prohibited'],
            'embedded_signup.type' => ['required', 'string', Rule::in(['WA_EMBEDDED_SIGNUP'])],
            'embedded_signup.event' => ['required', 'string', Rule::in(['FINISH'])],
            'embedded_signup.data' => ['required', 'array'],
            'embedded_signup.data.access_token' => ['prohibited'],
            'embedded_signup.data.waba_id' => ['required', 'string', 'max:255'],
            'embedded_signup.data.phone_number_id' => ['required', 'string', 'max:255'],
            'embedded_signup.data.business_id' => ['sometimes', 'nullable', 'string', 'max:255'],
            'embedded_signup.data.page_ids' => ['sometimes', 'array'],
            'embedded_signup.data.page_ids.*' => ['string', 'max:255'],
            'embedded_signup.data.catalog_ids' => ['sometimes', 'array'],
            'embedded_signup.data.catalog_ids.*' => ['string', 'max:255'],
            'embedded_signup.data.dataset_ids' => ['sometimes', 'array'],
            'embedded_signup.data.dataset_ids.*' => ['string', 'max:255'],
            'embedded_signup.data.instagram_account_ids' => ['sometimes', 'array'],
            'embedded_signup.data.instagram_account_ids.*' => ['string', 'max:255'],
        ];
    }
}
