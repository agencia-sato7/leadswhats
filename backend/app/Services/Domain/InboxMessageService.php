<?php

namespace App\Services\Domain;

use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\User;
use App\Services\WhatsApp\WhatsAppProviderInterface;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class InboxMessageService
{
    public function __construct(
        private readonly InboxService $inboxService,
        private readonly WhatsAppProviderInterface $provider,
        private readonly ConversationEventService $events,
    ) {}

    public function send(User $user, int $conversationId, ?string $body, ?UploadedFile $file): array
    {
        $detail = $this->inboxService->getConversationDetailForUser($user, $conversationId);
        if (! $detail) throw new RuntimeException('Conversa não encontrada.', 404);
        if (! $detail['service_window_open']) throw new RuntimeException('A janela de atendimento de 24 horas está fechada.', 422);

        $body = trim((string) $body);
        if ($body === '' && ! $file) throw new RuntimeException('Informe uma mensagem ou anexo.', 422);

        $companyId = (int) $user->company_id;
        $media = null;
        $path = null;
        if ($file) {
            $type = $this->mediaType((string) $file->getMimeType());
            $this->assertSize($type, (int) $file->getSize());
            $name = Str::uuid().'.'.($file->guessExtension() ?: 'bin');
            $path = $file->storeAs("whatsapp/{$companyId}", $name, 'local');
            $media = [
                'type' => $type, 'path' => Storage::disk('local')->path($path),
                'mime_type' => (string) $file->getMimeType(), 'original_name' => $file->getClientOriginalName() ?: $name,
                'caption' => $body !== '' ? $body : null,
            ];
        }

        $result = $media
            ? $this->provider->sendMediaMessage($detail['lead']['phone'], $media, ['company_id' => $companyId])
            : $this->provider->sendTextMessage($detail['lead']['phone'], $body, ['company_id' => $companyId]);

        if (! $result->success) {
            if ($path) Storage::disk('local')->delete($path);
            throw new RuntimeException($result->errorMessage ?: 'Falha ao enviar mensagem pelo WhatsApp.', 422);
        }

        $message = DB::transaction(function () use ($companyId, $detail, $body, $media, $path, $file, $result, $user) {
            $message = Message::create([
                'company_id' => $companyId,
                'lead_id' => $detail['lead']['lead_id'],
                'conversation_id' => $detail['conversation_id'],
                'provider' => $result->provider,
                'direction' => 'outbound',
                'channel' => $media['type'] ?? 'text',
                'body' => $body !== '' ? $body : null,
                'sent_at' => now(),
                'external_message_id' => $result->externalMessageId,
                'delivery_status' => 'sent',
                'metadata' => ['sent_by_user_id' => $user->id],
            ]);

            if ($media && $path && $file) {
                MessageAttachment::create([
                    'company_id' => $companyId, 'message_id' => $message->id, 'type' => $media['type'],
                    'mime_type' => $media['mime_type'], 'original_name' => $media['original_name'],
                    'size_bytes' => $file->getSize(), 'disk' => 'local', 'path' => $path,
                    'external_media_id' => $result->rawResponse['media_id'] ?? null,
                ]);
            }

            $this->events->registerMessageSent($user, $detail['conversation_id'], $detail['lead']['lead_id'], [
                'provider' => $result->provider, 'external_message_id' => $result->externalMessageId, 'channel' => $media['type'] ?? 'text',
            ]);
            return $message;
        });

        return $this->inboxService->serializeMessage($message->fresh('attachments'));
    }

    private function mediaType(string $mime): string
    {
        return match (true) {
            str_starts_with($mime, 'image/') => 'image',
            str_starts_with($mime, 'video/') => 'video',
            str_starts_with($mime, 'audio/') => 'audio',
            in_array($mime, ['application/pdf', 'text/plain', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'], true) => 'document',
            default => throw new RuntimeException('Tipo de arquivo não suportado.', 422),
        };
    }

    private function assertSize(string $type, int $bytes): void
    {
        $limits = ['image' => 5, 'audio' => 16, 'video' => 16, 'document' => 100];
        if ($bytes <= 0 || $bytes > $limits[$type] * 1024 * 1024) throw new RuntimeException("Arquivo excede o limite para {$type}.", 422);
    }
}
