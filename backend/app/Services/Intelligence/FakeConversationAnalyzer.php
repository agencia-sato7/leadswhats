<?php

namespace App\Services\Intelligence;

use App\Contracts\Intelligence\ConversationAnalyzer;
use App\Data\Intelligence\ConversationAnalysisInput;
use App\Data\Intelligence\ConversationAnalysisResult;

class FakeConversationAnalyzer implements ConversationAnalyzer
{
    public function analyze(ConversationAnalysisInput $input): ConversationAnalysisResult
    {
        $scenarios = [
            [
                'score' => 88,
                'intent' => 'Alta intenção de compra',
                'summary' => 'O atendimento conduziu bem a descoberta, confirmou a necessidade e apresentou um próximo passo claro.',
                'objections' => ['Prazo de implantação'],
                'positive_points' => ['Perguntas de descoberta objetivas', 'Tom consultivo e cordial', 'Próximo passo combinado'],
                'errors' => ['Faltou confirmar o orçamento disponível'],
                'improvement' => 'Confirme orçamento e participantes da decisão antes de encerrar a conversa.',
                'commercial' => ['temperature' => 'hot', 'decision_timeline' => 'curto prazo', 'budget_identified' => false],
                'criteria' => ['discovery' => 92, 'clarity' => 88, 'empathy' => 90, 'objection_handling' => 82],
                'stage_terms' => ['proposta', 'negociação'],
                'reason' => 'O lead demonstrou aderência, urgência e aceitou avançar para uma proposta.',
                'confidence' => 0.93,
            ],
            [
                'score' => 72,
                'intent' => 'Interesse em avaliação',
                'summary' => 'A conversa esclareceu a oferta, mas terminou sem um compromisso específico de continuidade.',
                'objections' => ['Preço', 'Comparação com concorrente'],
                'positive_points' => ['Resposta rápida', 'Explicação clara do produto'],
                'errors' => ['Benefícios pouco conectados ao problema do lead', 'Ausência de data para follow-up'],
                'improvement' => 'Relacione valor ao impacto do problema e combine data e horário para o próximo contato.',
                'commercial' => ['temperature' => 'warm', 'competitor_mentioned' => true, 'next_step_defined' => false],
                'criteria' => ['discovery' => 68, 'clarity' => 82, 'empathy' => 76, 'objection_handling' => 62],
                'stage_terms' => ['atendimento', 'contato'],
                'reason' => 'Existe interesse, porém ainda faltam critérios e compromisso para avançar à proposta.',
                'confidence' => 0.86,
            ],
            [
                'score' => 49,
                'intent' => 'Pesquisa inicial',
                'summary' => 'O atendimento respondeu às perguntas básicas, mas não investigou contexto, urgência ou critérios de compra.',
                'objections' => ['Preço'],
                'positive_points' => ['Comunicação respeitosa'],
                'errors' => ['Poucas perguntas abertas', 'Resposta centrada em preço', 'Nenhum próximo passo'],
                'improvement' => 'Use perguntas abertas para entender cenário, impacto e prioridade antes de apresentar valores.',
                'commercial' => ['temperature' => 'cold', 'budget_identified' => false, 'timeline_identified' => false],
                'criteria' => ['discovery' => 32, 'clarity' => 64, 'empathy' => 58, 'objection_handling' => 42],
                'stage_terms' => ['novo', 'contato'],
                'reason' => 'A conversa ainda está em descoberta inicial e não apresenta sinais suficientes para avançar.',
                'confidence' => 0.82,
            ],
            [
                'score' => 94,
                'intent' => 'Decisão de compra',
                'summary' => 'O atendimento identificou necessidade, autoridade, prazo e objeção, respondeu com segurança e confirmou o fechamento.',
                'objections' => ['Segurança da implantação'],
                'positive_points' => ['Descoberta comercial completa', 'Objeção tratada com evidência', 'Confirmação explícita da decisão'],
                'errors' => [],
                'improvement' => 'Registre responsáveis e datas do onboarding para preservar a excelente experiência pós-venda.',
                'commercial' => ['temperature' => 'hot', 'decision_status' => 'confirmed', 'next_step' => 'onboarding'],
                'criteria' => ['discovery' => 96, 'clarity' => 94, 'empathy' => 92, 'objection_handling' => 94],
                'stage_terms' => ['fechado', 'venda'],
                'reason' => 'Há confirmação explícita de compra e definição do próximo passo operacional.',
                'confidence' => 0.97,
            ],
            [
                'score' => 61,
                'intent' => 'Interesse condicionado',
                'summary' => 'O lead reconhece valor, mas depende de alinhamento interno e o atendimento não aprofundou o processo decisório.',
                'objections' => ['Aprovação do sócio', 'Momento financeiro'],
                'positive_points' => ['Objeção reconhecida sem pressão', 'Tom profissional'],
                'errors' => ['Decisor não mapeado', 'Follow-up genérico'],
                'improvement' => 'Mapeie quem participa da decisão e proponha um follow-up com pauta e data definidas.',
                'commercial' => ['temperature' => 'warm', 'decision_maker_identified' => false, 'dependency' => 'aprovação interna'],
                'criteria' => ['discovery' => 55, 'clarity' => 70, 'empathy' => 74, 'objection_handling' => 52],
                'stage_terms' => ['negociação', 'proposta'],
                'reason' => 'O lead está avaliando condições e depende de validação interna antes da decisão.',
                'confidence' => 0.84,
            ],
        ];

        $scenario = $scenarios[$input->conversationId % count($scenarios)];
        $recommendedColumnId = $this->recommendColumn($input->kanbanColumns, $scenario['stage_terms']);

        return new ConversationAnalysisResult(
            score: $scenario['score'],
            summary: $scenario['summary'],
            intent: $scenario['intent'],
            objections: $scenario['objections'],
            positivePoints: $scenario['positive_points'],
            errors: $scenario['errors'],
            improvementSuggestion: $scenario['improvement'],
            commercialData: $scenario['commercial'],
            criteriaScores: $scenario['criteria'],
            recommendedKanbanColumnId: $recommendedColumnId,
            classificationReason: $scenario['reason'],
            confidence: $scenario['confidence'],
            promptVersion: 'conversation-quality-v1',
            modelProvider: 'fake',
            modelName: 'fake-conversation-analyzer-v1',
        );
    }

    /**
     * @param array<int, array{id:int,name:string,rule_prompt:?string}> $columns
     * @param string[] $preferredTerms
     */
    private function recommendColumn(array $columns, array $preferredTerms): ?int
    {
        foreach ($columns as $column) {
            $context = mb_strtolower($column['name'].' '.($column['rule_prompt'] ?? ''));
            foreach ($preferredTerms as $term) {
                if (str_contains($context, mb_strtolower($term))) {
                    return $column['id'];
                }
            }
        }

        return $columns[0]['id'] ?? null;
    }
}
