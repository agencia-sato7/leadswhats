<?php

namespace App\Services;

use App\Models\CompanyWhatsAppIntegration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CompanyWhatsAppIntegrationService
{
    public const PROVIDER_META_CLOUD = 'meta_cloud';

    public const STATUS_NOT_CONFIGURED = 'not_configured';

    public const STATUS_CONFIGURED = 'configured';

    public const STATUS_ERROR = 'error';

    /**
     * @return array<string, mixed>
     */
    public function getForCompany(int $companyId): array
    {
        $integration = CompanyWhatsAppIntegration::query()
            ->where('company_id', $companyId)
            ->first();

        return $this->toResponseData($integration);
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    public function upsertForCompany(int $companyId, array $validated): array
    {
        $integration = CompanyWhatsAppIntegration::query()
            ->where('company_id', $companyId)
            ->first();

        if (! $integration) {
            $integration = new CompanyWhatsAppIntegration;
            $integration->company_id = $companyId;
            $integration->provider = self::PROVIDER_META_CLOUD;
            $integration->status = self::STATUS_NOT_CONFIGURED;
        }

        $integration->provider = self::PROVIDER_META_CLOUD;
        $integration->phone_number = $this->nullableString($validated, 'phone_number');
        $integration->phone_number_id = $this->nullableString($validated, 'phone_number_id');
        $integration->business_account_id = $this->nullableString($validated, 'business_account_id');
        $integration->waba_id = $integration->business_account_id;

        if (array_key_exists('last_error', $validated)) {
            $integration->last_error = $this->nullableString($validated, 'last_error');
        }

        if (array_key_exists('webhook_verify_token', $validated)) {
            $integration->webhook_verify_token = $this->nullableString($validated, 'webhook_verify_token');
        } elseif (! $integration->webhook_verify_token) {
            $integration->webhook_verify_token = Str::random(40);
        }

        if (array_key_exists('access_token', $validated)) {
            $integration->access_token_encrypted = $this->nullableString($validated, 'access_token');
        }

        $integration->status = self::hasRequiredCredentials($integration)
            ? self::STATUS_CONFIGURED
            : self::STATUS_NOT_CONFIGURED;
        if ($integration->status === self::STATUS_CONFIGURED && ! $integration->connected_at) {
            $integration->connected_at = now();
        }

        if ($integration->status !== self::STATUS_CONFIGURED) {
            $integration->connected_at = null;
        }

        $integration->save();

        return $this->toResponseData($integration->fresh());
    }

    /**
     * @param  array<string, mixed>  $embeddedSignupData
     * @return array<string, mixed>
     */
    public function completeEmbeddedSignupForCompany(
        int $companyId,
        array $embeddedSignupData,
        string $accessToken,
    ): array {
        return DB::transaction(function () use ($companyId, $embeddedSignupData, $accessToken): array {
            $integration = CompanyWhatsAppIntegration::query()
                ->where('company_id', $companyId)
                ->lockForUpdate()
                ->first();

            if (! $integration) {
                $integration = new CompanyWhatsAppIntegration;
                $integration->company_id = $companyId;
            }

            $wabaId = trim((string) $embeddedSignupData['waba_id']);

            $integration->provider = self::PROVIDER_META_CLOUD;
            $integration->status = self::STATUS_CONFIGURED;
            $integration->phone_number_id = trim((string) $embeddedSignupData['phone_number_id']);
            $integration->waba_id = $wabaId;
            $integration->business_account_id = $wabaId;
            $integration->access_token_encrypted = $accessToken;
            $integration->last_error = null;
            $integration->connected_at ??= now();
            $integration->webhook_verify_token ??= Str::random(40);

            $this->setOptionalString($integration, $embeddedSignupData, 'business_id');
            $this->setOptionalIdList($integration, $embeddedSignupData, 'page_ids');
            $this->setOptionalIdList($integration, $embeddedSignupData, 'catalog_ids');
            $this->setOptionalIdList($integration, $embeddedSignupData, 'dataset_ids');
            $this->setOptionalIdList($integration, $embeddedSignupData, 'instagram_account_ids');

            $integration->save();

            return $this->toResponseData($integration->fresh());
        });
    }

    public static function hasRequiredCredentials(?CompanyWhatsAppIntegration $integration): bool
    {
        if (! $integration || $integration->provider !== self::PROVIDER_META_CLOUD) {
            return false;
        }

        $hasAccessToken = is_string($integration->access_token_encrypted) && trim($integration->access_token_encrypted) !== '';
        $hasPhoneNumberId = is_string($integration->phone_number_id) && trim($integration->phone_number_id) !== '';
        $wabaId = $integration->waba_id ?: $integration->business_account_id;
        $hasBusinessAccountId = is_string($wabaId) && trim($wabaId) !== '';

        return $hasAccessToken && $hasPhoneNumberId && $hasBusinessAccountId;
    }

    public static function isConfigured(?CompanyWhatsAppIntegration $integration): bool
    {
        return $integration?->status === self::STATUS_CONFIGURED
            && self::hasRequiredCredentials($integration);
    }

    private function nullableString(array $payload, string $key): ?string
    {
        if (! array_key_exists($key, $payload)) {
            return null;
        }

        $value = $payload[$key];
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function setOptionalString(
        CompanyWhatsAppIntegration $integration,
        array $payload,
        string $key,
    ): void {
        if (array_key_exists($key, $payload)) {
            $integration->setAttribute($key, $this->nullableString($payload, $key));
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function setOptionalIdList(
        CompanyWhatsAppIntegration $integration,
        array $payload,
        string $key,
    ): void {
        if (! array_key_exists($key, $payload)) {
            return;
        }

        $values = is_array($payload[$key]) ? $payload[$key] : [];
        $integration->setAttribute($key, array_values($values));
    }

    /**
     * @return array<string, mixed>
     */
    private function toResponseData(?CompanyWhatsAppIntegration $integration): array
    {
        if (! $integration) {
            return [
                'provider' => self::PROVIDER_META_CLOUD,
                'status' => self::STATUS_NOT_CONFIGURED,
                'phone_number' => null,
                'phone_number_id' => null,
                'business_account_id' => null,
                'waba_id' => null,
                'business_id' => null,
                'page_ids' => [],
                'catalog_ids' => [],
                'dataset_ids' => [],
                'instagram_account_ids' => [],
                'webhook_verify_token_configured' => false,
                'access_token_configured' => false,
                'connected_at' => null,
                'last_error' => null,
            ];
        }

        $isConfigured = self::isConfigured($integration);

        return [
            'provider' => self::PROVIDER_META_CLOUD,
            'status' => $isConfigured ? self::STATUS_CONFIGURED : self::STATUS_NOT_CONFIGURED,
            'phone_number' => $integration->phone_number,
            'phone_number_id' => $integration->phone_number_id,
            'business_account_id' => $integration->business_account_id,
            'waba_id' => $integration->waba_id ?: $integration->business_account_id,
            'business_id' => $integration->business_id,
            'page_ids' => $integration->page_ids ?? [],
            'catalog_ids' => $integration->catalog_ids ?? [],
            'dataset_ids' => $integration->dataset_ids ?? [],
            'instagram_account_ids' => $integration->instagram_account_ids ?? [],
            'webhook_verify_token_configured' => (bool) $integration->webhook_verify_token,
            'webhook_verify_token' => $integration->webhook_verify_token,
            'access_token_configured' => (bool) $integration->access_token_encrypted,
            'connected_at' => $isConfigured ? $integration->connected_at?->toISOString() : null,
            'last_error' => $integration->last_error,
        ];
    }
}
