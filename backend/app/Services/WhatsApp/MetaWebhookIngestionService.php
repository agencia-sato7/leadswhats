<?php

namespace App\Services\WhatsApp;

use App\Jobs\ProcessInboundWhatsAppMedia;
use App\Jobs\ProcessMetaCoexistenceHistory;
use App\Models\Company;
use App\Models\CompanyWhatsAppIntegration;
use App\Models\Message;
use App\Services\CompanyWhatsAppIntegrationService;
use App\Services\WhatsappIngestionService;
use Carbon\Carbon;

/**
 * Ingestão dos webhooks da Meta, roteada pelo `field` de cada change:
 *
 * - messages: mensagens recebidas + statuses de entrega (fluxo original);
 * - smb_message_echoes: mensagens enviadas pelo WhatsApp Business app (os
 *   envios feitos pela nossa API voltam como echo do mesmo wamid e são
 *   descartados pela idempotência por external_message_id);
 * - history: contatos e histórico dos últimos 180 dias (processado async);
 * - account_update: alterações de vínculo da conta WhatsApp Business.
 */
class MetaWebhookIngestionService
{
    /**
     * @var array{processed:int,ignored:int,unmatched:int,duplicated:int}
     */
    private const COUNTERS = ['processed' => 0, 'ignored' => 0, 'unmatched' => 0, 'duplicated' => 0];

    public function __construct(
        private readonly WhatsappIngestionService $ingestionService,
        private readonly CompanyWhatsAppIntegrationService $integrationService,
    ) {}

    /**
     * @param  array<int, mixed>  $entries
     * @return array{processed:int,ignored:int,unmatched:int,duplicated:int,queued:int}
     */
    public function process(array $entries): array
    {
        $counts = self::COUNTERS;
        $counts['queued'] = 0;

        foreach ($entries as $entry) {
            foreach ((array) data_get($entry, 'changes', []) as $change) {
                $value = (array) data_get($change, 'value', []);
                $field = trim((string) data_get($change, 'field', ''));

                if ($field === 'account_update') {
                    $this->handleAccountUpdate($value);
                    continue;
                }

                $phoneNumberId = trim((string) data_get($value, 'metadata.phone_number_id', ''));

                if ($phoneNumberId === '') {
                    $counts['ignored']++;
                    continue;
                }

                $integration = CompanyWhatsAppIntegration::query()
                    ->where('provider', CompanyWhatsAppIntegrationService::PROVIDER_META_CLOUD)
                    ->where('phone_number_id', $phoneNumberId)
                    ->first();

                if (! CompanyWhatsAppIntegrationService::isConfigured($integration) || ! $integration?->company_id) {
                    $counts['unmatched']++;
                    continue;
                }

                $company = Company::query()->find($integration->company_id);
                if (! $company) {
                    $counts['unmatched']++;
                    continue;
                }

                if ($field === 'smb_message_echoes') {
                    $counts = $this->accumulate($counts, $this->ingestMessageEchoes($company, $value));
                    continue;
                }

                if ($field === 'history') {
                    ProcessMetaCoexistenceHistory::dispatch($company->id, $integration->id, $value);
                    $counts['queued']++;
                    continue;
                }

                $counts = $this->accumulate($counts, $this->ingestMessages($company, $integration, $value));
            }
        }

        return $counts;
    }

    /**
     * Importa um payload de `history` (executado pelo job enfileirado).
     *
     * @return array{processed:int,ignored:int,unmatched:int,duplicated:int}
     */
    public function ingestHistoryValue(Company $company, CompanyWhatsAppIntegration $integration, array $value): array
    {
        $counts = self::COUNTERS;
        $progress = null;

        foreach ((array) data_get($value, 'history', []) as $chunk) {
            $chunkProgress = data_get($chunk, 'metadata.progress');
            if (is_numeric($chunkProgress)) {
                $progress = max((int) $progress, (int) $chunkProgress);
            }

            foreach ((array) data_get($chunk, 'threads', []) as $thread) {
                $threadId = trim((string) data_get($thread, 'id', ''));
                if ($threadId === '') {
                    $counts['ignored']++;
                    continue;
                }

                foreach ((array) data_get($thread, 'messages', []) as $message) {
                    $type = trim((string) data_get($message, 'type', ''));
                    $body = (string) data_get($message, 'text.body', '');

                    // `media_placeholder` (mídia sem asset) e demais tipos não
                    // têm conteúdo recuperável no histórico.
                    if ($type !== 'text' || trim($body) === '') {
                        $counts['ignored']++;
                        continue;
                    }

                    // O `id` do thread é o contato; quem não for o contato foi a
                    // própria empresa falando pelo app WhatsApp Business.
                    $from = trim((string) data_get($message, 'from', ''));
                    $direction = $from === $threadId ? 'inbound' : 'outbound';

                    $result = $this->ingestionService->ingest($company, [
                        'provider' => CompanyWhatsAppIntegrationService::PROVIDER_META_CLOUD,
                        'phone' => $threadId,
                        'direction' => $direction,
                        'channel' => 'text',
                        'body' => $body,
                        'source' => 'meta_cloud',
                        'external_message_id' => trim((string) data_get($message, 'id', '')) ?: null,
                        'sent_at' => $this->sentAtFromTimestamp(data_get($message, 'timestamp')),
                        'sync_source' => 'history',
                        'is_historical_sync' => true,
                        'raw_payload' => [
                            'meta_value' => $value,
                            'meta_message' => $message,
                        ],
                    ]);

                    $counts = $this->registerIngested($counts, $result);
                }
            }
        }

        $this->integrationService->markSyncStatus(
            $company->id,
            MetaCoexistenceOnboardingService::SYNC_TYPE_HISTORY,
            $progress !== null && $progress >= 100
                ? CompanyWhatsAppIntegrationService::SYNC_STATUS_COMPLETED
                : CompanyWhatsAppIntegrationService::SYNC_STATUS_REQUESTED,
        );

        return $counts;
    }

    /**
     * Fluxo original do webhook `messages` (inbound + statuses + mídia).
     *
     * @return array{processed:int,ignored:int,unmatched:int,duplicated:int}
     */
    private function ingestMessages(Company $company, CompanyWhatsAppIntegration $integration, array $value): array
    {
        $counts = self::COUNTERS;

        foreach ((array) data_get($value, 'statuses', []) as $status) {
            $externalId = trim((string) data_get($status, 'id', ''));
            $state = trim((string) data_get($status, 'status', ''));
            if ($externalId !== '' && in_array($state, ['sent', 'delivered', 'read', 'failed'], true)) {
                Message::query()->where('company_id', $company->id)->where('external_message_id', $externalId)->update([
                    'delivery_status' => $state,
                    'delivery_error' => $state === 'failed' ? (string) data_get($status, 'errors.0.title', 'Falha de entrega.') : null,
                ]);
            }
        }

        $messages = data_get($value, 'messages');
        if (! is_array($messages) || $messages === []) {
            $counts['ignored']++;

            return $counts;
        }

        foreach ($messages as $message) {
            $type = (string) data_get($message, 'type', '');
            if (! in_array($type, ['text', 'image', 'video', 'audio', 'document'], true)) {
                $counts['ignored']++;
                continue;
            }

            $phone = trim((string) data_get($message, 'from', ''));
            $profileName = collect((array) data_get($value, 'contacts', []))
                ->first(fn ($contact) => trim((string) data_get($contact, 'wa_id', '')) === $phone);
            $profileName = trim((string) data_get($profileName, 'profile.name', ''));
            $body = $type === 'text' ? (string) data_get($message, 'text.body', '') : (string) data_get($message, "{$type}.caption", '');
            $mediaId = $type === 'text' ? '' : trim((string) data_get($message, "{$type}.id", ''));
            if ($phone === '' || ($type === 'text' && trim($body) === '') || ($type !== 'text' && $mediaId === '')) {
                $counts['ignored']++;
                continue;
            }

            if ($mediaId !== '') {
                ProcessInboundWhatsAppMedia::dispatch($company->id, $integration->id, $message, $value);
                $counts['processed']++;
                continue;
            }

            $result = $this->ingestionService->ingest($company, [
                'provider' => CompanyWhatsAppIntegrationService::PROVIDER_META_CLOUD,
                'phone' => $phone,
                'direction' => 'inbound',
                'channel' => $type,
                'body' => $body,
                'source' => 'meta_cloud',
                'lead_name' => $profileName !== '' ? $profileName : null,
                'external_message_id' => trim((string) data_get($message, 'id', '')) ?: null,
                'sent_at' => $this->sentAtFromTimestamp(data_get($message, 'timestamp')),
                'raw_payload' => [
                    'meta_value' => $value,
                    'meta_message' => $message,
                ],
            ]);

            $counts = $this->registerIngested($counts, $result);
        }

        return $counts;
    }

    /**
     * Mensagens enviadas pelo WhatsApp Business app (ou por um dispositivo
     * vinculado): sem esse campo a coexistência ficaria invisível no CRM.
     *
     * @return array{processed:int,ignored:int,unmatched:int,duplicated:int}
     */
    private function ingestMessageEchoes(Company $company, array $value): array
    {
        $counts = self::COUNTERS;

        $echoes = data_get($value, 'message_echoes');
        if (! is_array($echoes) || $echoes === []) {
            $counts['ignored']++;

            return $counts;
        }

        foreach ($echoes as $echo) {
            // revoke/edit e mídia ficam fora do escopo passivo atual.
            if ((string) data_get($echo, 'type', '') !== 'text') {
                $counts['ignored']++;
                continue;
            }

            $leadPhone = trim((string) data_get($echo, 'to', ''));
            $body = (string) data_get($echo, 'text.body', '');
            if ($leadPhone === '' || trim($body) === '') {
                $counts['ignored']++;
                continue;
            }

            $result = $this->ingestionService->ingest($company, [
                'provider' => CompanyWhatsAppIntegrationService::PROVIDER_META_CLOUD,
                'phone' => $leadPhone,
                'direction' => 'outbound',
                'channel' => 'text',
                'body' => $body,
                'source' => 'meta_cloud',
                'external_message_id' => trim((string) data_get($echo, 'id', '')) ?: null,
                'sent_at' => $this->sentAtFromTimestamp(data_get($echo, 'timestamp')),
                'sync_source' => 'smb_echo',
                'raw_payload' => [
                    'meta_value' => $value,
                    'meta_message' => $echo,
                ],
            ]);

            $counts = $this->registerIngested($counts, $result);
        }

        return $counts;
    }

    /**
     * A Meta dispara `account_update` na conclusão do fluxo e também quando o
     * vínculo com o parceiro muda.
     */
    private function handleAccountUpdate(array $value): void
    {
        $wabaId = trim((string) data_get($value, 'waba_info.waba_id', ''));
        if ($wabaId === '') {
            return;
        }

        $integration = CompanyWhatsAppIntegration::query()
            ->where('provider', CompanyWhatsAppIntegrationService::PROVIDER_META_CLOUD)
            ->where('waba_id', $wabaId)
            ->first();

        if (! $integration) {
            return;
        }

        $event = trim((string) data_get($value, 'event', ''));

        if (in_array($event, ['PARTNER_REMOVED', 'ACCOUNT_OFFBOARDED', 'ACCOUNT_RESTRICTED'], true)) {
            $integration->status = CompanyWhatsAppIntegrationService::STATUS_ERROR;
            $integration->last_error = 'A Meta informou uma alteração na conta WhatsApp Business ('.$event.'). Reconecte o WhatsApp.';
        } else {
            $integration->connected_at ??= now();
        }

        $integration->save();
    }

    /**
     * @param  array<string, int>  $counts
     * @param  array<string, mixed>  $partial
     * @return array<string, int>
     */
    private function accumulate(array $counts, array $partial): array
    {
        foreach (['processed', 'ignored', 'unmatched', 'duplicated'] as $key) {
            $counts[$key] += (int) ($partial[$key] ?? 0);
        }

        return $counts;
    }

    /**
     * @param  array<string, int>  $counts
     * @param  array<string, mixed>  $result
     * @return array<string, int>
     */
    private function registerIngested(array $counts, array $result): array
    {
        if ((bool) ($result['duplicated'] ?? false)) {
            $counts['duplicated']++;
        } else {
            $counts['processed']++;
        }

        return $counts;
    }

    private function sentAtFromTimestamp(mixed $timestamp): ?string
    {
        return is_numeric($timestamp)
            ? Carbon::createFromTimestampUTC((int) $timestamp)->toISOString()
            : null;
    }
}