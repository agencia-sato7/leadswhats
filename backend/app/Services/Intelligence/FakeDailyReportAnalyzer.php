<?php

namespace App\Services\Intelligence;

use App\Contracts\Intelligence\DailyReportAnalyzer;

class FakeDailyReportAnalyzer implements DailyReportAnalyzer
{
    public function generate(array $payload): array
    {
        $qualityScore = $payload['quality']['average_score'] ?? null;
        $newLeads = (int) ($payload['metrics']['new_leads_today'] ?? 0);
        $previousLeads = (int) ($payload['metrics']['previous_new_leads'] ?? 0);
        $verdict = $qualityScore === null
            ? 'needs_improvement'
            : ($qualityScore >= 70 ? 'good' : ($qualityScore >= 50 ? 'needs_improvement' : 'poor'));

        return [
            'executive_summary' => "O dia registrou {$newLeads} leads novos, contra {$previousLeads} no dia anterior."
                .($qualityScore !== null ? " A qualidade média do atendimento foi {$qualityScore} de 100." : ' Não houveram conversas analisadas no dia.'),
            'overall_verdict' => $verdict,
            'volume_summary' => $newLeads > $previousLeads
                ? 'O volume de novos leads cresceu em relação ao dia anterior.'
                : ($newLeads < $previousLeads ? 'O volume de novos leads caiu em relação ao dia anterior.' : 'O volume de novos leads ficou estável.'),
            'quality_summary' => $qualityScore === null
                ? 'Não há pontuações de qualidade para o dia; nenhuma conversa foi analisada pela IA.'
                : "A pontuação média das conversas foi {$qualityScore} de 100, com base nas avaliações de discovery, clareza, empatia e tratamento de objeções.",
            'opportunities' => ['Revisar as conversas com pontuação abaixo de 60 e orientar a equipe nos critérios mais fracos.'],
            'priorities' => ['Acompanhar os leads sem resposta do dia.', 'Transformar cada contato em um próximo passo verificável.'],
            'prompt_version' => 'daily-report-fake-v1',
            'model_provider' => 'fake',
            'model_name' => 'deterministic-daily-report-analyzer',
        ];
    }
}
