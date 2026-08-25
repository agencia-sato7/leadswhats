<?php

namespace App\Contracts\Intelligence;

interface CampaignAnalyzer
{
    /**
     * @param array<int, array<string, mixed>> $evidences
     * @return array<int, array<string, mixed>>
     */
    public function analyzeEvidenceBatch(array $evidences): array;

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function consolidate(array $payload): array;
}
