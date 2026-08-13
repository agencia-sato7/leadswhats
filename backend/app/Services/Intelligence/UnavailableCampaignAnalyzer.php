<?php

namespace App\Services\Intelligence;

use App\Contracts\Intelligence\CampaignAnalyzer;
use App\Exceptions\ConversationAnalyzerUnavailableException;

class UnavailableCampaignAnalyzer implements CampaignAnalyzer
{
    public function analyzeEvidenceBatch(array $evidences): array
    {
        throw new ConversationAnalyzerUnavailableException('Nenhum analisador de campanha foi configurado neste ambiente.');
    }

    public function consolidate(array $payload): array
    {
        throw new ConversationAnalyzerUnavailableException('Nenhum analisador de campanha foi configurado neste ambiente.');
    }
}
