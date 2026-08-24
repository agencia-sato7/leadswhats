<?php

namespace App\Jobs;

use App\Models\Company;
use App\Models\CompanyWhatsAppIntegration;
use App\Models\MessageAttachment;
use App\Services\WhatsappIngestionService;
use App\Services\WhatsApp\MetaMediaService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;

class ProcessInboundWhatsAppMedia implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;
    public int $timeout = 120;

    public function __construct(
        public readonly int $companyId,
        public readonly int $integrationId,
        public readonly array $message,
        public readonly array $value,
    ) {}

    public function handle(WhatsappIngestionService $ingestion, MetaMediaService $mediaService): void
    {
        $company = Company::findOrFail($this->companyId);
        $integration = CompanyWhatsAppIntegration::findOrFail($this->integrationId);
        $type = (string) data_get($this->message, 'type');
        $mediaId = (string) data_get($this->message, "{$type}.id");
        $downloaded = $mediaService->download($integration, $mediaId, $type, data_get($this->message, "{$type}.filename"));
        $timestamp = data_get($this->message, 'timestamp');
        $phone = (string) data_get($this->message, 'from');
        $contact = collect((array) data_get($this->value, 'contacts', []))
            ->first(fn ($item) => trim((string) data_get($item, 'wa_id', '')) === trim($phone));
        $profileName = trim((string) data_get($contact, 'profile.name', ''));
        $result = $ingestion->ingest($company, [
            'provider' => 'meta_cloud', 'phone' => $phone,
            'direction' => 'inbound', 'channel' => $type,
            'body' => (string) data_get($this->message, "{$type}.caption", ''), 'source' => 'meta_cloud',
            'external_message_id' => (string) data_get($this->message, 'id'),
            'lead_name' => $profileName !== '' ? $profileName : null,
            'sent_at' => is_numeric($timestamp) ? \Carbon\Carbon::createFromTimestampUTC((int) $timestamp)->toISOString() : null,
            'raw_payload' => ['meta_value' => $this->value, 'meta_message' => $this->message],
        ]);

        if ($result['duplicated'] ?? false) {
            Storage::disk('local')->delete($downloaded['path']);
            return;
        }

        MessageAttachment::create([
            'company_id' => $company->id, 'message_id' => $result['message_id'], 'type' => $type,
            'mime_type' => $downloaded['mime_type'], 'original_name' => $downloaded['original_name'],
            'size_bytes' => $downloaded['size_bytes'], 'disk' => 'local', 'path' => $downloaded['path'],
            'external_media_id' => $mediaId,
        ]);
    }
}
