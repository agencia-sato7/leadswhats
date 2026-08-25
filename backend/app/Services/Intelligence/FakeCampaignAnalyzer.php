<?php

namespace App\Services\Intelligence;

use App\Contracts\Intelligence\CampaignAnalyzer;

class FakeCampaignAnalyzer implements CampaignAnalyzer
{
    public function analyzeEvidenceBatch(array $evidences): array
    {
        return array_map(static function (array $evidence): array {
            $inbound = (int) ($evidence['metrics']['inbound_count'] ?? 0);
            $outbound = (int) ($evidence['metrics']['outbound_count'] ?? 0);
            $rescueAttempts = (int) ($evidence['metrics']['rescue_attempts'] ?? 0);
            $score = $outbound === 0 ? 30 : ($inbound === 0 ? 48 : ($rescueAttempts > 0 ? 78 : 74));

            return [
                'key' => (string) $evidence['key'],
                'score' => $score,
                'criteria_scores' => [
                    'discovery' => max($score - 6, 0),
                    'clarity' => $score,
                    'empathy' => min($score + 3, 100),
                    'objection_handling' => max($score - 4, 0),
                ],
                'summary' => $score >= 70
                    ? 'O atendimento manteve troca ativa e condução comercial adequada no recorte.'
                    : 'O atendimento do recorte apresenta lacunas que exigem acompanhamento do gestor.',
                'positive_points' => $outbound > 0 ? ['A equipe deu continuidade ao contato.'] : [],
                'errors' => $outbound === 0 ? ['Não houve resposta do atendimento no recorte analisado.'] : [],
                'improvement_suggestion' => 'Confirmar a necessidade e combinar um próximo passo objetivo com o lead.',
                'prompt_version' => 'campaign-evidence-fake-v1',
                'model_provider' => 'fake',
                'model_name' => 'deterministic-campaign-analyzer',
            ];
        }, $evidences);
    }

    public function consolidate(array $payload): array
    {
        $qualityScore = $payload['quality']['score'] ?? null;
        $current = (int) ($payload['metrics']['volume']['current_new_leads'] ?? 0);
        $previous = (int) ($payload['metrics']['volume']['previous_new_leads'] ?? 0);
        $verdict = $qualityScore === null ? 'needs_improvement' : ($qualityScore >= 70 ? 'good' : ($qualityScore >= 50 ? 'needs_improvement' : 'poor'));

        return [
            'executive_summary' => "O período recebeu {$current} leads novos, contra {$previous} no período anterior. A qualidade foi avaliada sem misturar volume e atendimento.",
            'overall_verdict' => $verdict,
            'volume_summary' => $current > $previous
                ? 'O volume de novos leads cresceu em relação ao período anterior equivalente.'
                : ($current < $previous ? 'O volume de novos leads caiu em relação ao período anterior equivalente.' : 'O volume de novos leads ficou estável.'),
            'service_summary' => $qualityScore === null
                ? 'Não há evidências suficientes para pontuar o atendimento.'
                : "A qualidade média do atendimento foi {$qualityScore} de 100.",
            'new_leads_summary' => 'A coorte de novos leads foi avaliada pelas interações realizadas dentro do período.',
            'rescued_leads_summary' => 'A coorte de resgatados considera a retomada a partir do primeiro resgate do período.',
            'priorities' => ['Acompanhar os leads sem resposta.', 'Transformar cada contato em um próximo passo verificável.'],
            'prompt_version' => 'campaign-consolidation-fake-v1',
            'model_provider' => 'fake',
            'model_name' => 'deterministic-campaign-analyzer',
        ];
    }
}
