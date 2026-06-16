<?php

namespace App\Services\WhatsApp;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AiRuleEvaluatorService
{
    /**
     * Evaluates message history against a set of column rules.
     *
     * @param string $historyText Recent message history.
     * @param array<int, string> $columnRules Array of column ID => rule_prompt.
     * @return array{matched_column_id: int|null, reason: string|null}
     */
    public function evaluate(string $historyText, array $columnRules): array
    {
        if (empty($columnRules)) {
            return [
                'matched_column_id' => null,
                'reason' => 'Nenhuma regra de coluna configurada para avaliação.',
            ];
        }

        $geminiKey = env('GEMINI_API_KEY');
        $openaiKey = env('OPENAI_API_KEY');

        if (!empty($geminiKey)) {
            try {
                return $this->evaluateWithGemini($historyText, $columnRules, $geminiKey);
            } catch (\Throwable $e) {
                Log::error('Erro ao avaliar regras de IA com Gemini, usando fallback de palavra-chave: ' . $e->getMessage());
            }
        }

        if (!empty($openaiKey)) {
            try {
                return $this->evaluateWithOpenAi($historyText, $columnRules, $openaiKey);
            } catch (\Throwable $e) {
                Log::error('Erro ao avaliar regras de IA com OpenAI, usando fallback de palavra-chave: ' . $e->getMessage());
            }
        }

        return $this->evaluateWithFallback($historyText, $columnRules);
    }

    /**
     * Evaluates using Gemini API.
     */
    private function evaluateWithGemini(string $historyText, array $columnRules, string $apiKey): array
    {
        $prompt = $this->buildPrompt($historyText, $columnRules);

        $response = Http::withHeaders([
            'Content-Type' => 'application/json',
        ])->post("https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent?key={$apiKey}", [
            'contents' => [
                [
                    'parts' => [
                        ['text' => $prompt]
                    ]
                ]
            ],
            'generationConfig' => [
                'responseMimeType' => 'application/json',
            ],
        ]);

        if (!$response->successful()) {
            throw new \Exception("Erro HTTP Gemini: " . $response->status() . " - " . $response->body());
        }

        $data = $response->json();
        $text = data_get($data, 'candidates.0.content.parts.0.text');

        if (!$text) {
            throw new \Exception("Resposta vazia do Gemini.");
        }

        return $this->parseLlmResponse($text);
    }

    /**
     * Evaluates using OpenAI API.
     */
    private function evaluateWithOpenAi(string $historyText, array $columnRules, string $apiKey): array
    {
        $prompt = $this->buildPrompt($historyText, $columnRules);

        $response = Http::withToken($apiKey)
            ->post('https://api.openai.com/v1/chat/completions', [
                'model' => 'gpt-4o-mini',
                'messages' => [
                    ['role' => 'user', 'content' => $prompt],
                ],
                'response_format' => ['type' => 'json_object'],
            ]);

        if (!$response->successful()) {
            throw new \Exception("Erro HTTP OpenAI: " . $response->status() . " - " . $response->body());
        }

        $data = $response->json();
        $text = data_get($data, 'choices.0.message.content');

        if (!$text) {
            throw new \Exception("Resposta vazia da OpenAI.");
        }

        return $this->parseLlmResponse($text);
    }

    /**
     * Parses the JSON response from the LLM.
     */
    private function parseLlmResponse(string $text): array
    {
        $decoded = json_decode(trim($text), true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            // Attempt to extract JSON if LLM returned markdown codeblock
            if (preg_match('/\{.*\}/s', $text, $matches)) {
                $decoded = json_decode($matches[0], true);
            }
        }

        if (!is_array($decoded)) {
            return [
                'matched_column_id' => null,
                'reason' => 'Erro ao parsear resposta JSON da IA: ' . substr($text, 0, 100),
            ];
        }

        $matchedId = data_get($decoded, 'matched_column_id');
        $reason = data_get($decoded, 'reason', 'Identificado pela IA.');

        return [
            'matched_column_id' => $matchedId ? (int) $matchedId : null,
            'reason' => $reason,
        ];
    }

    /**
     * Builds the system prompt for the LLM.
     */
    private function buildPrompt(string $historyText, array $columnRules): string
    {
        $rulesStr = "";
        foreach ($columnRules as $id => $rule) {
            $rulesStr .= "ID: {$id} | Rule: {$rule}\n";
        }

        return <<<PROMPT
You are an expert sales assistant analyzing chat histories to classify leads into stages based on strict rules.

Chat History (messages in chronological order):
"""
{$historyText}
"""

Available Stages & Rules:
{$rulesStr}

Analyze the chat history. Determine if the most recent message(s) satisfy any of the stage rules.
Reply with a JSON object containing exactly two keys:
- "matched_column_id": the integer ID of the matched column, or null if none matches.
- "reason": a brief explanation of why it matches (or why it doesn't match).

Example Output:
{
  "matched_column_id": 10,
  "reason": "The customer asked for a price list/quote."
}
Only output the JSON object, with no markdown code blocks or extra text.
PROMPT;
    }

    /**
     * Fallback keyword matcher if LLM is not configured/fails.
     */
    private function evaluateWithFallback(string $historyText, array $columnRules): array
    {
        foreach ($columnRules as $id => $rulePrompt) {
            $promptLower = mb_strtolower($rulePrompt);

            // Check if the rule prompt mentions spouse / thinking
            $isSpouseRule = str_contains($promptLower, 'esposa') 
                || str_contains($promptLower, 'marido') 
                || str_contains($promptLower, 'cônjuge') 
                || str_contains($promptLower, 'conjuge') 
                || str_contains($promptLower, 'pensar')
                || str_contains($promptLower, 'esposo');

            // Check if the rule prompt mentions quote / price
            $isQuoteRule = str_contains($promptLower, 'orçamento') 
                || str_contains($promptLower, 'orcamento') 
                || str_contains($promptLower, 'preço') 
                || str_contains($promptLower, 'preco') 
                || str_contains($promptLower, 'valor') 
                || str_contains($promptLower, 'custo')
                || str_contains($promptLower, 'quanto custa')
                || str_contains($promptLower, 'tabela');

            if ($isSpouseRule) {
                $keywords = ['esposa', 'marido', 'cônjuge', 'conjuge', 'pensar', 'conversar com', 'falar com', 'analisar', 'ver com ela', 'ver com ele', 'esposo'];
                foreach ($keywords as $keyword) {
                    $pattern = '/(?<!\p{L})' . preg_quote($keyword, '/') . '(?!\p{L})/iu';
                    if (preg_match($pattern, $historyText)) {
                        return [
                            'matched_column_id' => (int) $id,
                            'reason' => "Regra acionada por correspondência de palavra-chave (fallback) para cônjuge/pensar: '{$keyword}'",
                        ];
                    }
                }
            }

            if ($isQuoteRule) {
                $keywords = ['orçamento', 'orcamento', 'preço', 'preco', 'valor', 'custo', 'quanto custa', 'tabela', 'valores', 'quanto fica'];
                foreach ($keywords as $keyword) {
                    $pattern = '/(?<!\p{L})' . preg_quote($keyword, '/') . '(?!\p{L})/iu';
                    if (preg_match($pattern, $historyText)) {
                        return [
                            'matched_column_id' => (int) $id,
                            'reason' => "Regra acionada por correspondência de palavra-chave (fallback) para orçamento/preço: '{$keyword}'",
                        ];
                    }
                }
            }
        }

        return [
            'matched_column_id' => null,
            'reason' => 'Nenhuma palavra-chave de fallback correspondeu às regras.',
        ];
    }
}
