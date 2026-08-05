<?php

namespace App\Services\WhatsApp;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AiRuleEvaluatorService
{
    public function evaluate(string $historyText, array $columnRules): array
    {
        if (empty($columnRules)) {
            return [
                "matched_column_id" => null,
                "reason" => "Nenhuma regra de coluna configurada para avaliação.",
            ];
        }

        $openaiKey = env("OPENAI_API_KEY");
        $geminiKey = env("GEMINI_API_KEY");

        if (!empty($openaiKey)) {
            try {
                return $this->evaluateWithOpenAi($historyText, $columnRules, $openaiKey);
            } catch (\Throwable $e) {
                Log::error("Erro ao avaliar regras de IA com OpenAI, usando fallback de palavra-chave: " . $e->getMessage());
            }
        }

        if (!empty($geminiKey)) {
            try {
                return $this->evaluateWithGemini($historyText, $columnRules, $geminiKey);
            } catch (\Throwable $e) {
                Log::error("Erro ao avaliar regras de IA com Gemini, usando fallback de palavra-chave: " . $e->getMessage());
            }
        }

        return $this->evaluateWithFallback($historyText, $columnRules);
    }

    private function evaluateWithGemini(string $historyText, array $columnRules, string $apiKey): array
    {
        $prompt = $this->buildPrompt($historyText, $columnRules);

        $response = Http::withHeaders([
            "Content-Type" => "application/json",
        ])->post("https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent?key={$apiKey}", [
            "contents" => [
                [
                    "parts" => [
                        ["text" => $prompt]
                    ]
                ]
            ],
            "generationConfig" => [
                "responseMimeType" => "application/json",
            ],
        ]);

        if (!$response->successful()) {
            throw new \Exception("Erro HTTP Gemini: " . $response->status() . " - " . $response->body());
        }

        $data = $response->json();
        $text = data_get($data, "candidates.0.content.parts.0.text");

        if (!$text) {
            throw new \Exception("Resposta vazia do Gemini.");
        }

        return $this->parseLlmResponse($text);
    }

    private function evaluateWithOpenAi(string $historyText, array $columnRules, string $apiKey): array
    {
        $prompt = $this->buildPrompt($historyText, $columnRules);

        $response = Http::withToken($apiKey)
            ->post("https://api.openai.com/v1/chat/completions", [
                "model" => "gpt-4o-mini",
                "messages" => [
                    ["role" => "user", "content" => $prompt],
                ],
                "response_format" => ["type" => "json_object"],
            ]);

        if (!$response->successful()) {
            throw new \Exception("Erro HTTP OpenAI: " . $response->status() . " - " . $response->body());
        }

        $data = $response->json();
        $text = data_get($data, "choices.0.message.content");

        if (!$text) {
            throw new \Exception("Resposta vazia da OpenAI.");
        }

        return $this->parseLlmResponse($text);
    }

    private function parseLlmResponse(string $text): array
    {
        $decoded = json_decode(trim($text), true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            if (preg_match("/\\{.*\\}/s", $text, $matches)) {
                $decoded = json_decode($matches[0], true);
            }
        }

        if (!is_array($decoded)) {
            return [
                "matched_column_id" => null,
                "reason" => "Erro ao parsear resposta JSON da IA: " . substr($text, 0, 100),
            ];
        }

        $matchedId = data_get($decoded, "matched_column_id");
        $reason = data_get($decoded, "reason", "Identificado pela IA.");

        return [
            "matched_column_id" => $matchedId ? (int) $matchedId : null,
            "reason" => $reason,
        ];
    }

    private function buildPrompt(string $historyText, array $columnRules): string
    {
        $rulesStr = "";
        foreach ($columnRules as $id => $rule) {
            $rulesStr .= "ID: {$id} | Regra: {$rule}\n";
        }

        return <<<PROMPT
Voce e um assistente comercial que analisa o historico de chat de um lead e decide se ele deve ser movido para alguma coluna do funil.

Historico de mensagens (em ordem cronologica):
"""
{$historyText}
"""

Colunas e regras disponiveis:
{$rulesStr}

Analise o historico e retorne APENAS um JSON com duas chaves:
- "matched_column_id": o ID da coluna que melhor corresponde a(s) mensagem(ns) mais recente(s), ou null se nenhuma regra for satisfeita.
- "reason": uma explicacao curta em portugues do motivo da escolha.

Exemplo de saida:
{
  "matched_column_id": 10,
  "reason": "O cliente pediu orcamento/preco."
}
Nao retorne texto fora do JSON.
PROMPT;
    }

    private function evaluateWithFallback(string $historyText, array $columnRules): array
    {
        foreach ($columnRules as $id => $rulePrompt) {
            $promptLower = mb_strtolower($rulePrompt);

            $isSpouseRule = str_contains($promptLower, "esposa")
                || str_contains($promptLower, "marido")
                || str_contains($promptLower, "cônjuge")
                || str_contains($promptLower, "conjuge")
                || str_contains($promptLower, "pensar")
                || str_contains($promptLower, "esposo");

            $isQuoteRule = str_contains($promptLower, "orçamento")
                || str_contains($promptLower, "orcamento")
                || str_contains($promptLower, "preço")
                || str_contains($promptLower, "preco")
                || str_contains($promptLower, "valor")
                || str_contains($promptLower, "custo")
                || str_contains($promptLower, "quanto custa")
                || str_contains($promptLower, "tabela");

            if ($isSpouseRule) {
                $keywords = ["esposa", "marido", "cônjuge", "conjuge", "pensar", "conversar com", "falar com", "analisar", "ver com ela", "ver com ele", "esposo"];
                foreach ($keywords as $keyword) {
                    $pattern = "/(?<!\\p{L})" . preg_quote($keyword, "/") . "(?!\\p{L})/iu";
                    if (preg_match($pattern, $historyText)) {
                        return [
                            "matched_column_id" => (int) $id,
                            "reason" => "Regra acionada por correspondência de palavra-chave (fallback) para cônjuge/pensar: {}",
                        ];
                    }
                }
            }

            if ($isQuoteRule) {
                $keywords = ["orçamento", "orcamento", "preço", "preco", "valor", "custo", "quanto custa", "tabela", "valores", "quanto fica"];
                foreach ($keywords as $keyword) {
                    $pattern = "/(?<!\\p{L})" . preg_quote($keyword, "/") . "(?!\\p{L})/iu";
                    if (preg_match($pattern, $historyText)) {
                        return [
                            "matched_column_id" => (int) $id,
                            "reason" => "Regra acionada por correspondência de palavra-chave (fallback) para orçamento/preço: {}",
                        ];
                    }
                }
            }
        }

        return [
            "matched_column_id" => null,
            "reason" => "Nenhuma palavra-chave de fallback correspondeu às regras.",
        ];
    }
}
