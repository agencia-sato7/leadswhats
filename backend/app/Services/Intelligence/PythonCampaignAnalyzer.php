<?php

namespace App\Services\Intelligence;

use App\Contracts\Intelligence\CampaignAnalyzer;
use App\Exceptions\ConversationAnalyzerUnavailableException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

class PythonCampaignAnalyzer implements CampaignAnalyzer
{
    public function analyzeEvidenceBatch(array $evidences): array
    {
        $response = $this->post('/v1/analyze/campaign/evidence-batch', ['evidences' => $evidences]);
        $items = $response->json('evidences');

        if (!is_array($items) || count($items) !== count($evidences)) {
            throw new ConversationAnalyzerUnavailableException('O serviço de IA retornou evidências incompatíveis com o contrato.');
        }

        return array_values($items);
    }

    public function consolidate(array $payload): array
    {
        $response = $this->post('/v1/analyze/campaign/consolidate', $payload);
        $result = $response->json();

        if (!is_array($result) || !isset($result['executive_summary'], $result['overall_verdict'], $result['priorities'])) {
            throw new ConversationAnalyzerUnavailableException('O serviço de IA retornou uma consolidação incompatível com o contrato.');
        }

        return $result;
    }

    /** @param array<string, mixed> $payload */
    private function post(string $path, array $payload): Response
    {
        try {
            $response = Http::connectTimeout((int) config('intelligence.connect_timeout_seconds', 5))
                ->timeout((int) config('intelligence.timeout_seconds', 90))
                ->acceptJson()
                ->asJson()
                ->post(rtrim((string) config('intelligence.service_url'), '/').$path, $payload);
        } catch (ConnectionException $exception) {
            throw new ConversationAnalyzerUnavailableException('O serviço de IA está indisponível no momento.', previous: $exception);
        } catch (Throwable $exception) {
            throw new ConversationAnalyzerUnavailableException('Não foi possível concluir a análise da campanha.', previous: $exception);
        }

        if (!$response->successful()) {
            $message = $response->json('detail.message');
            throw new ConversationAnalyzerUnavailableException(is_string($message) && $message !== ''
                ? $message
                : 'O serviço de IA não conseguiu concluir a análise da campanha.');
        }

        return $response;
    }
}
