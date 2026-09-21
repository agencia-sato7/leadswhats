<?php

namespace App\Services\WhatsApp;

use App\Exceptions\MetaCoexistenceException;
use App\Models\Company;
use App\Models\CompanyWhatsAppIntegration;
use App\Services\CompanyWhatsAppIntegrationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class WhatsAppDisconnectService
{
    public function __construct(
        private readonly MetaCoexistenceOnboardingService $meta,
        private readonly CompanyWhatsAppIntegrationService $integrations,
    ) {}

    public function disconnect(int $companyId, int $actorId): array
    {
        try {
            return $this->run($companyId, $actorId);
        } catch (MetaCoexistenceException $exception) {
            // Falhas vindas da Meta (não as locais 409) são registradas na
            // integração para diagnóstico — fora da transação, porque o
            // rollback desfaria qualquer marcação feita dentro dela.
            if ($exception->metaErrorCode !== null) {
                $this->integrations->markCoexistenceFailure($companyId, $exception->getMessage());
            }

            throw $exception;
        }
    }

    private function run(int $companyId, int $actorId): array
    {
        return DB::transaction(function () use ($companyId, $actorId): array {
            Company::whereKey($companyId)->lockForUpdate()->firstOrFail();
            $integration = CompanyWhatsAppIntegration::where('company_id', $companyId)
                ->lockForUpdate()->first();

            if (! $integration) {
                return $this->integrations->getForCompany($companyId);
            }

            $wabaId = trim((string) ($integration->waba_id ?: $integration->business_account_id));
            if ($wabaId !== '') {
                $shared = CompanyWhatsAppIntegration::where('company_id', '<>', $companyId)
                    ->where(function ($query) use ($wabaId) {
                        $query->where('waba_id', $wabaId)->orWhere('business_account_id', $wabaId);
                    })->exists();
                if ($shared) {
                    throw new MetaCoexistenceException(
                        'Esta conta WhatsApp é compartilhada por outra empresa. Procure o suporte para desconectar sem afetá-la.', 409,
                    );
                }
                try {
                    $this->meta->unsubscribeApp($wabaId, (string) $integration->access_token_encrypted);
                } catch (MetaCoexistenceException $exception) {
                    // 401 + code 190 com subcodes 463/467 significa token de
                    // acesso definitivamente expirado/revogado: a Meta já não
                    // reconhece esta conexão, então não há nada a revogar.
                    // Mantemos as credenciais salvas seria pior: impediria para
                    // sempre a desconexão. O vínculo local é removido e os
                    // webhooks órfãos passam a ser simplesmente ignorados.
                    if ((int) $exception->metaErrorCode === 190
                        && in_array((string) $exception->metaErrorSubcode, ['463', '467'], true)) {
                        $integration->delete();
                        Log::warning('WhatsApp integration disconnected with a dead access token.', [
                            'company_id' => $companyId,
                            'actor_id' => $actorId,
                            'meta_error_subcode' => $exception->metaErrorSubcode,
                        ]);

                        return $this->integrations->getForCompany($companyId);
                    }

                    // Falha recuperável ou ambígua (rede, 5xx da Meta,
                    // success:false, checkpoint de re-login): preserva o
                    // vínculo e propaga o erro ao usuário.
                    throw $exception;
                }
            } elseif ($integration->phone_number_id || $integration->access_token_encrypted) {
                throw new MetaCoexistenceException('O vínculo está incompleto. Procure o suporte antes de desconectar.', 409);
            }

            // Only the integration is removed, never CRM data. Reconnecting creates
            // a new ID, so old queued jobs cannot run with the new credentials.
            $integration->delete();
            Log::info('WhatsApp integration disconnected.', ['company_id' => $companyId, 'actor_id' => $actorId]);

            return $this->integrations->getForCompany($companyId);
        });
    }
}
