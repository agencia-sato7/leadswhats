<?php

namespace App\Services\WhatsApp;

use App\Exceptions\MetaCoexistenceException;
use App\Models\CompanyWhatsAppIntegration;
use App\Services\CompanyWhatsAppIntegrationService;

/**
 * Orquestra a conclusão da Coexistência: troca o code pelo token, persiste a
 * conexão, assina o app no WABA e dispara a sincronização de contatos e
 * histórico do WhatsApp Business app.
 */
class WhatsAppCoexistenceSignupService
{
    public function __construct(
        private readonly MetaCoexistenceTokenExchangeService $tokenExchange,
        private readonly MetaCoexistenceOnboardingService $onboarding,
        private readonly CompanyWhatsAppIntegrationService $integrationService,
    ) {}

    /**
     * @param  array<string, mixed>  $coexistenceData
     * @return array<string, mixed>
     */
    public function complete(int $companyId, string $code, array $coexistenceData): array
    {
        $token = $this->tokenExchange->exchange($code);

        $wabaId = trim((string) ($coexistenceData['waba_id'] ?? ''));

        $this->integrationService->completeCoexistenceForCompany(
            $companyId,
            $coexistenceData,
            $token['access_token'],
            $token['expires_in'],
        );

        try {
            $this->onboarding->subscribeApp($wabaId, $token['access_token']);
        } catch (MetaCoexistenceException $exception) {
            // A conexão existe, mas sem a assinatura do app a Meta não entrega
            // webhooks: marcamos como error para o gestor reconectar.
            $this->integrationService->markCoexistenceFailure($companyId, $exception->getMessage());

            throw $exception;
        }

        if ((bool) config('whatsapp.coexistence_auto_sync', true)) {
            $this->requestDataSync(
                $companyId,
                array_map('strval', (array) config('whatsapp.coexistence_sync_types', ['contacts', 'history'])),
                false,
            );
        }

        return $this->integrationService->getForCompany($companyId);
    }

    /**
     * @param  array<int, string>  $syncTypes
     * @return array<string, mixed>
     */
    public function requestDataSync(int $companyId, array $syncTypes, bool $throwOnFailure = true): array
    {
        $allowed = [
            MetaCoexistenceOnboardingService::SYNC_TYPE_CONTACTS,
            MetaCoexistenceOnboardingService::SYNC_TYPE_HISTORY,
        ];

        $types = array_values(array_intersect($allowed, $syncTypes));

        if ($types === []) {
            throw new MetaCoexistenceException(
                'Tipo de sincronização inválido. Use contacts, history ou both.',
                422,
            );
        }

        $integration = CompanyWhatsAppIntegration::query()
            ->where('company_id', $companyId)
            ->first();

        if (! CompanyWhatsAppIntegrationService::isConfigured($integration)) {
            throw new MetaCoexistenceException(
                'A conexão com o WhatsApp não está ativa para esta empresa.',
                409,
            );
        }

        $accessToken = (string) $integration->access_token_encrypted;
        $phoneNumberId = (string) $integration->phone_number_id;

        foreach ($types as $syncType) {
            try {
                $this->onboarding->requestDataSync($phoneNumberId, $accessToken, $syncType);
                $this->integrationService->markSyncStatus(
                    $companyId,
                    $syncType,
                    CompanyWhatsAppIntegrationService::SYNC_STATUS_REQUESTED,
                );
            } catch (MetaCoexistenceException $exception) {
                $this->integrationService->markSyncStatus(
                    $companyId,
                    $syncType,
                    CompanyWhatsAppIntegrationService::SYNC_STATUS_ERROR,
                );

                if ($throwOnFailure) {
                    throw $exception;
                }
            }
        }

        return $this->integrationService->getForCompany($companyId);
    }
}