<?php

namespace App\Services\Intelligence;

use App\Contracts\Intelligence\DailyReportAnalyzer;
use App\Exceptions\ConversationAnalyzerUnavailableException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Throwable;

class PythonDailyReportAnalyzer implements DailyReportAnalyzer
{
    public function generate(array $payload): array
    {
        try {
            $response = Http::connectTimeout((int) config('intelligence.connect_timeout_seconds', 5))
                ->timeout((int) config('intelligence.timeout_seconds', 90))
                ->acceptJson()
                ->asJson()
                ->post(rtrim((string) config('intelligence.service_url'), '/').'/v1/analyze/daily-report', $payload);
        } catch (ConnectionException $exception) {
            throw new ConversationAnalyzerUnavailableException('O serviço de IA está indisponível no momento.', previous: $exception);
        } catch (Throwable $exception) {
            throw new ConversationAnalyzerUnavailableException('Não foi possível concluir o relatório diário.', previous: $exception);
        }

        if (! $response->successful()) {
            $message = $response->json('detail.message');
            throw new ConversationAnalyzerUnavailableException(is_string($message) && $message !== ''
                ? $message
                : 'O serviço de IA não conseguiu concluir o relatório diário.');
        }

        $result = $response->json();

        if (! is_array($result) || ! isset($result['executive_summary'], $result['overall_verdict'], $result['priorities'])) {
            throw new ConversationAnalyzerUnavailableException('O serviço de IA retornou um relatório incompatível com o contrato.');
        }

        return $result;
    }
}
