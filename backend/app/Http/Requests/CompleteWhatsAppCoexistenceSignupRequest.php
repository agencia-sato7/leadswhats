<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Payload devolvido pelo Embedded Signup de Coexistência (session logging:
 * type=WA_EMBEDDED_SIGNUP, event=FINISH_WHATSAPP_BUSINESS_APP_ONBOARDING) mais
 * o code temporário do Facebook Login.
 */
class CompleteWhatsAppCoexistenceSignupRequest extends FormRequest
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
            'coexistence' => ['required', 'array'],
            'coexistence.access_token' => ['prohibited'],
            'coexistence.type' => ['required', 'string', Rule::in(['WA_EMBEDDED_SIGNUP'])],
            'coexistence.event' => ['required', 'string', 'max:100', 'starts_with:FINISH'],
            'coexistence.data' => ['required', 'array'],
            'coexistence.data.access_token' => ['prohibited'],
            'coexistence.data.waba_id' => ['required', 'string', 'max:255'],
            'coexistence.data.phone_number_id' => ['required', 'string', 'max:255'],
            'coexistence.data.business_id' => ['sometimes', 'nullable', 'string', 'max:255'],
            'coexistence.data.phone_number' => ['sometimes', 'nullable', 'string', 'max:30'],
            'coexistence.data.page_ids' => ['sometimes', 'array'],
            'coexistence.data.page_ids.*' => ['string', 'max:255'],
            'coexistence.data.catalog_ids' => ['sometimes', 'array'],
            'coexistence.data.catalog_ids.*' => ['string', 'max:255'],
            'coexistence.data.dataset_ids' => ['sometimes', 'array'],
            'coexistence.data.dataset_ids.*' => ['string', 'max:255'],
            'coexistence.data.instagram_account_ids' => ['sometimes', 'array'],
            'coexistence.data.instagram_account_ids.*' => ['string', 'max:255'],
        ];
    }
}