<?php

namespace App\Services\Domain;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Responsible for OpenAI-powered marketing intelligence:
 *  - Classifying lead origin from the first inbound message.
 *  - Analyzing ad creatives (image/video) from a tracked link.
 *  - Suggesting a source for leads classified as "desconhecido".
 */
class OpenAiIntelligenceService
{
    public const SOURCE_FACEBOOK = 'facebook';
    public const SOURCE_INSTAGRAM = 'instagram';
    public const SOURCE_GOOGLE = 'google';
    public const SOURCE_TIKTOK = 'tiktok';
    public const SOURCE_INDICACAO = 'indicacao';
    public const SOURCE_UNKNOWN = 'desconhecido';

    /**
     * Classifies the origin of a lead by reading the first inbound message text.
     *
     * @return array{source:string,confidence:float,reason:string,method:string}
     */
    public function classifySourceFromFirstMessage(string $message): array
    {
        $openaiKey = env('OPENAI_API_KEY');

        if (!empty($openaiKey)) {
            try {
                return $this->classifyWithOpenAi($message, $openaiKey);
            } catch (\Throwable $e) {
                Log::error('Erro ao classificar origem com OpenAI, usando fallback: ' . $e->getMessage());
            }
        }

        return $this->classifyWithFallback($message);
    }

    /**
     * Analyzes an ad creative URL using OpenAI vision and returns a structured description.
     *
     * @return array{status:string,platform:string|null,creative_id:string|null,description:string|null,headline:string|null,cta:string|null,image_url:string|null}
     */
    public function analyzeCreative(string $url): array
    {
        $platform = $this->detectPlatformFromUrl($url);
        $creativeId = $this->extractCreativeIdFromUrl($url);
        $apiKey = env('OPENAI_API_KEY');

        if (!empty($apiKey)) {
            try {
                return $this->analyzeCreativeWithOpenAi($url, $platform, $creativeId, $apiKey);
            } catch (\Throwable $e) {
                Log::error('Erro ao analisar criativo com OpenAI, usando fallback: ' . $e->getMessage());
            }
        }

        return [
            'status' => 'failed',
            'platform' => $platform,
            'creative_id' => $creativeId,
            'description' => null,
            'headline' => null,
            'cta' => null,
            'image_url' => str_starts_with($url, 'http') ? $url : null,
        ];
    }

    /**
     * Extracts a tracking/ad identifier from a URL query string, if any.
     */
    public function extractCreativeIdFromUrl(string $url): ?string
    {
        $parts = parse_url($url);

        if (!isset($parts['query'])) {
            return null;
        }

        parse_str($parts['query'], $query);

        $candidates = ['ad_id', 'creative_id', 'creative', 'utm_creative', 'ad_set_id', 'wbraid', 'gbraid'];
        foreach ($candidates as $candidate) {
            $value = $query[$candidate] ?? null;
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return null;
    }

    /**
     * Detects which ad platform a tracked URL belongs to.
     */
    public function detectPlatformFromUrl(string $url): ?string
    {
        $host = (string) parse_url($url, PHP_URL_HOST);

        if (preg_match('/facebook\.com|fb\.com/i', $host) || str_contains($url, 'fbclid')) {
            return self::SOURCE_FACEBOOK;
        }

        if (preg_match('/instagram\.com/i', $host)) {
            return self::SOURCE_INSTAGRAM;
        }

        if (preg_match('/google\.com|google\.ad|g\.co|goo\.gl/i', $host) || str_contains($url, 'gclid') || str_contains($url, 'gbraid') || str_contains($url, 'wbraid')) {
            return self::SOURCE_GOOGLE;
        }

        if (preg_match('/tiktok\.com/i', $host)) {
            return self::SOURCE_TIKTOK;
        }

        return null;
    }

    /**
     * @return array{source:string,confidence:float,reason:string,method:string}
     */
    private function classifyWithOpenAi(string $message, string $apiKey): array
    {
        $response = Http::withToken($apiKey)
            ->post('https://api.openai.com/v1/chat/completions', [
                'model' => 'gpt-4o-mini',
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => 'Você é um assistente de marketing que classifica a origem de um lead de WhatsApp com base na primeira mensagem que o cliente enviou. Devolva APENAS um JSON com as chaves: "source" (um destes: facebook, instagram, google, tiktok, indicacao, desconhecido), "confidence" (0.0 a 1.0) e "reason" (frase curta em português explicando).',
                    ],
                    [
                        'role' => 'user',
                        'content' => 'Primeira mensagem do cliente: "' . $message . '"',
                    ],
                ],
                'response_format' => ['type' => 'json_object'],
            ]);

        if (!$response->successful()) {
            throw new \Exception('Erro HTTP OpenAI: ' . $response->status() . ' - ' . $response->body());
        }

        $text = data_get($response->json(), 'choices.0.message.content');

        if (!$text) {
            throw new \Exception('Resposta vazia da OpenAI.');
        }

        $decoded = json_decode(trim($text), true);
        if (!is_array($decoded)) {
            throw new \Exception('JSON inválido da OpenAI: ' . substr($text, 0, 100));
        }

        $source = strtolower((string) data_get($decoded, 'source', 'desconhecido'));
        if (!in_array($source, $this->validSources(), true)) {
            $source = self::SOURCE_UNKNOWN;
        }

        return [
            'source' => $source,
            'confidence' => (float) data_get($decoded, 'confidence', 0.5),
            'reason' => (string) data_get($decoded, 'reason', 'Classificado pela IA.'),
            'method' => 'openai',
        ];
    }

    /**
     * @return array{source:string,confidence:float,reason:string,method:string}
     */
    private function classifyWithFallback(string $message): array
    {
        $lower = mb_strtolower($message);

        $map = [
            'tiktok' => ['tiktok', 'tk'],
            'instagram' => ['instagram', 'insta', 'vídeo do insta', 'post do insta', 'stories'],
            'facebook' => ['facebook', 'faceboock', 'anúncio do facebook', 'anuncio do facebook'],
            'google' => ['google', 'pesquisei no google', 'vi no google', 'anúncio do google', 'anuncio do google'],
            'indicacao' => ['indicacao', 'indicação', 'amigo', 'amiga', 'conhecido', 'conheceu', 'parente', 'sobrinho', 'tia', 'tio'],
        ];

        foreach ($map as $source => $keywords) {
            foreach ($keywords as $keyword) {
                if (mb_strpos($lower, $keyword) !== false) {
                    return [
                        'source' => $source,
                        'confidence' => 0.7,
                        'reason' => "Correspondência de palavra-chave (fallback): '{$keyword}'.",
                        'method' => 'fallback',
                    ];
                }
            }
        }

        return [
            'source' => self::SOURCE_UNKNOWN,
            'confidence' => 0.0,
            'reason' => 'Nenhuma indicação de origem detectada (fallback).',
            'method' => 'fallback',
        ];
    }

    /**
     * @return array{status:string,platform:string|null,creative_id:string|null,description:string|null,headline:string|null,cta:string|null,image_url:string|null}
     */
    private function analyzeCreativeWithOpenAi(string $url, ?string $platform, ?string $creativeId, string $apiKey): array
    {
        $response = Http::withToken($apiKey)
            ->post('https://api.openai.com/v1/chat/completions', [
                'model' => 'gpt-4o',
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => 'Você é um analista de criativos de anúncio. Receba uma URL de anúncio de Meta/Google/TikTok e descreva o criativo (imagem/vídeo, texto, chamada para ação, proposta). Se não conseguir acessar a URL, use o contexto disponível. Devolva APENAS um JSON com as chaves: "description", "headline", "cta" e "image_url".',
                    ],
                    [
                        'role' => 'user',
                        'content' => 'Analise o criativo do anúncio a partir desta URL: ' . $url,
                    ],
                ],
                'response_format' => ['type' => 'json_object'],
            ]);

        if (!$response->successful()) {
            throw new \Exception('Erro HTTP OpenAI: ' . $response->status() . ' - ' . $response->body());
        }

        $text = data_get($response->json(), 'choices.0.message.content');

        if (!$text) {
            throw new \Exception('Resposta vazia da OpenAI.');
        }

        $decoded = json_decode(trim($text), true);
        if (!is_array($decoded)) {
            throw new \Exception('JSON inválido da OpenAI: ' . substr($text, 0, 100));
        }

        $analyzedUrl = (string) data_get($decoded, 'image_url', $url);

        return [
            'status' => 'analyzed',
            'platform' => $platform,
            'creative_id' => $creativeId,
            'description' => (string) data_get($decoded, 'description'),
            'headline' => (string) data_get($decoded, 'headline'),
            'cta' => (string) data_get($decoded, 'cta'),
            'image_url' => $analyzedUrl !== '' ? $analyzedUrl : null,
        ];
    }

    /**
     * @return string[]
     */
    private function validSources(): array
    {
        return [
            self::SOURCE_FACEBOOK,
            self::SOURCE_INSTAGRAM,
            self::SOURCE_GOOGLE,
            self::SOURCE_TIKTOK,
            self::SOURCE_INDICACAO,
            self::SOURCE_UNKNOWN,
        ];
    }
}
