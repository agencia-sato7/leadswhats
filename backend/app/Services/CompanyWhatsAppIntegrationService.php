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

    public const CONNECTION_MODE_EMBEDDED_SIGNUP = 'embedded_signup';

    public const CONNECTION_MODE_COEXISTENCE = 'coexistence';

    public const SYNC_STATUS_REQUESTED = 'requested';

    public const SYNC_STATUS_COMPLETED = 'completed';

    public const SYNC_STATUS_ERROR = 'error';

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
     * Marca o andamento da sincronização de dados (history/contacts) do
     * WhatsApp Business app.
     */
    public function markSyncStatus(int $companyId, string $syncType, string $status): void
    {
        $column = $syncType === 'history' ? 'history_sync_status' : 'contacts_sync_status';

        CompanyWhatsAppIntegration::query()
            ->where('company_id', $companyId)
            ->update([$column => $status]);
    }

    /**
     * Registra falha depois de o code já ter sido trocado (ex.: assinatura do
     * app no WABA recusada pela Meta) sem descartar as credenciais salvas.
     */
    public function markCoexistenceFailure(int $companyId, string $message): void
    {
        CompanyWhatsAppIntegration::query()
            ->where('company_id', $companyId)
            ->update([
                'status' => self::STATUS_ERROR,
                'last_error' => Str::limit($message, 2000, ''),
            ]);
    }

    /**
     * Conclui a conexão por Coexistência (Embedded Signup para WhatsApp
     * Business app), persistindo o token criptografado e os IDs dos ativos.
     *
     * @param  array<string, mixed>  $coexistenceData
     * @return array<string, mixed>
     */
    public function completeCoexistenceForCompany(
        int $companyId,
        array $coexistenceData,
        string $accessToken,
        ?int $tokenExpiresIn = null,
    ): array {
        return DB::transaction(function () use ($companyId, $coexistenceData, $accessToken, $tokenExpiresIn): array {
            $integration = CompanyWhatsAppIntegration::query()
                ->where('company_id', $companyId)
                ->lockForUpdate()
                ->first();

            if (! $integration) {
                $integration = new CompanyWhatsAppIntegration;
                $integration->company_id = $companyId;
            }

            $wabaId = trim((string) $coexistenceData['waba_id']);

            $integration->provider = self::PROVIDER_META_CLOUD;
            $integration->status = self::STATUS_CONFIGURED;
            $integration->connection_mode = self::CONNECTION_MODE_COEXISTENCE;
            $integration->phone_number_id = trim((string) $coexistenceData['phone_number_id']);
            $integration->waba_id = $wabaId;
            $integration->business_account_id = $wabaId;
            $integration->access_token_encrypted = $accessToken;
            $integration->last_error = null;
            $integration->connected_at ??= now();
            $integration->coexistence_opted_in_at = now();
            $integration->token_expires_at = $tokenExpiresIn !== null && $tokenExpiresIn > 0
                ? now()->addSeconds($tokenExpiresIn)
                : null;
            $integration->webhook_verify_token ??= Str::random(40);

            $this->setOptionalString($integration, $coexistenceData, 'business_id');
            $this->setOptionalString($integration, $coexistenceData, 'phone_number');
            $this->setOptionalIdList($integration, $coexistenceData, 'page_ids');
            $this->setOptionalIdList($integration, $coexistenceData, 'catalog_ids');
            $this->setOptionalIdList($integration, $coexistenceData, 'dataset_ids');
            $this->setOptionalIdList($integration, $coexistenceData, 'instagram_account_ids');

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
                'connection_mode' => self::CONNECTION_MODE_EMBEDDED_SIGNUP,
                'is_coexistence' => false,
                'coexistence_config_id' => $this->coexistenceConfigId(),
                'coexistence_app_id' => $this->coexistenceAppId(),
                'coexistence_feature_type' => $this->coexistenceFeatureType(),
                'coexistence_session_info_version' => (string) config('whatsapp.coexistence_session_info_version', '3'),
                'phone_number' => null,
                'phone_number_id' => null,
                'business_account_id' => null,
                'waba_id' => null,
                'business_id' => null,
                'page_ids' => [],
                'catalog_ids' => [],
                'dataset_ids' => [],
                'instagram_account_ids' => [],
                'coexistence_opted_in_at' => null,
                'history_sync_status' => null,
                'contacts_sync_status' => null,
                'token_expires_at' => null,
                'webhook_verify_token_configured' => false,
                'access_token_configured' => false,
                'connected_at' => null,
                'last_error' => null,
            ];
        }

        $isConfigured = self::isConfigured($integration);
        $connectionMode = $integration->connection_mode ?: self::CONNECTION_MODE_EMBEDDED_SIGNUP;

        return [
            'provider' => self::PROVIDER_META_CLOUD,
            'status' => $isConfigured ? self::STATUS_CONFIGURED : self::STATUS_NOT_CONFIGURED,
            'connection_mode' => $connectionMode,
            'is_coexistence' => $connectionMode === self::CONNECTION_MODE_COEXISTENCE,
            'coexistence_config_id' => $this->coexistenceConfigId(),
            'coexistence_app_id' => $this->coexistenceAppId(),
            'coexistence_feature_type' => $this->coexistenceFeatureType(),
            'coexistence_session_info_version' => (string) config('whatsapp.coexistence_session_info_version', '3'),
            'phone_number' => $integration->phone_number,
            'phone_number_id' => $integration->phone_number_id,
            'business_account_id' => $integration->business_account_id,
            'waba_id' => $integration->waba_id ?: $integration->business_account_id,
            'business_id' => $integration->business_id,
            'page_ids' => $integration->page_ids ?? [],
            'catalog_ids' => $integration->catalog_ids ?? [],
            'dataset_ids' => $integration->dataset_ids ?? [],
            'instagram_account_ids' => $integration->instagram_account_ids ?? [],
            'coexistence_opted_in_at' => $integration->coexistence_opted_in_at?->toISOString(),
            'history_sync_status' => $integration->history_sync_status,
            'contacts_sync_status' => $integration->contacts_sync_status,
            'token_expires_at' => $integration->token_expires_at?->toISOString(),
            'webhook_verify_token_configured' => (bool) $integration->webhook_verify_token,
            'webhook_verify_token' => $integration->webhook_verify_token,
            'access_token_configured' => (bool) $integration->access_token_encrypted,
            'connected_at' => $isConfigured ? $integration->connected_at?->toISOString() : null,
            'last_error' => $integration->last_error,
        ];
    }

    private function coexistenceConfigId(): ?string
    {
        $configId = trim((string) config('whatsapp.coexistence_config_id', ''));

        return $configId === '' ? null : $configId;
    }

    /**
     * App ID público da Meta (o mesmo usado pelo SDK JavaScript). Não é segredo.
     */
    private function coexistenceAppId(): ?string
    {
        $appId = trim((string) config('whatsapp.meta_app_id', ''));

        return $appId === '' ? null : $appId;
    }

    private function coexistenceFeatureType(): string
    {
        $featureType = trim((string) config('whatsapp.coexistence_feature_type', ''));

        return $featureType === '' ? 'whatsapp_business_app_onboarding' : $featureType;
    }
}
