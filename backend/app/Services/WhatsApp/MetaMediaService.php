<?php

namespace App\Services\WhatsApp;

use App\Models\CompanyWhatsAppIntegration;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class MetaMediaService
{
    /** @return array{path:string,mime_type:string,size_bytes:int,original_name:string} */
    public function download(CompanyWhatsAppIntegration $integration, string $mediaId, string $type, ?string $filename = null): array
    {
        $version = trim((string) config('whatsapp.cloud_api_version', 'v21.0'), '/');
        $token = trim((string) $integration->access_token_encrypted);
        $metadata = Http::withToken($token)->get("https://graph.facebook.com/{$version}/{$mediaId}");
        if (! $metadata->successful() || ! $metadata->json('url')) throw new RuntimeException('Não foi possível obter a mídia da Meta.');

        $content = Http::withToken($token)->get((string) $metadata->json('url'));
        if (! $content->successful()) throw new RuntimeException('Não foi possível baixar a mídia da Meta.');
        $bytes = strlen($content->body());
        $limits = ['image' => 5, 'audio' => 16, 'video' => 16, 'document' => 100];
        if (! isset($limits[$type]) || $bytes > $limits[$type] * 1024 * 1024) throw new RuntimeException('Mídia recebida excede o limite permitido.');

        $mime = (string) ($metadata->json('mime_type') ?: $content->header('Content-Type') ?: 'application/octet-stream');
        $extension = match ($mime) {
            'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp',
            'video/mp4' => 'mp4', 'audio/ogg' => 'ogg', 'audio/mpeg' => 'mp3', 'application/pdf' => 'pdf',
            default => 'bin',
        };
        $path = 'whatsapp/'.$integration->company_id.'/'.Str::uuid().'.'.$extension;
        Storage::disk('local')->put($path, $content->body());
        return ['path' => $path, 'mime_type' => $mime, 'size_bytes' => $bytes, 'original_name' => $filename ?: "whatsapp-{$mediaId}.{$extension}"];
    }
}
