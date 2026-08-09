<?php

namespace App\Services;

use App\Models\Company;
use App\Models\CompanyBusinessSetting;
use App\Models\Conversation;
use App\Models\KanbanColumn;
use App\Models\Lead;
use App\Models\LeadSourceHistory;
use App\Models\LeadStageHistory;
use App\Models\Message;
use App\Models\Pipeline;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;

class DemoBootstrapService
{
    /**
     * @return array<string, mixed>
     */
    public function run(): array
    {
        if (app()->environment('production')) {
            throw new RuntimeException('O bootstrap de demonstração não pode ser executado em production.');
        }

        $company = Company::query()->updateOrCreate(
            ['slug' => 'empresa-demo'],
            [
                'name' => 'Empresa Demo',
                'timezone' => 'America/Sao_Paulo',
                'work_start' => '08:00',
                'work_end' => '18:00',
                'lunch_start' => '12:00',
                'lunch_end' => '13:00',
                'active' => true,
            ]
        );

        $users = [
            [
                'name' => 'Platform Admin Demo',
                'email' => 'platform@leadswhats.local',
                'role' => 'platform_admin',
                'company_id' => null,
            ],
            [
                'name' => 'Admin Plataforma',
                'email' => 'admin@leadswhats.local',
                'role' => 'admin',
                'company_id' => $company->id,
            ],
            [
                'name' => 'Gestor Demo',
                'email' => 'gestor@empresa.local',
                'role' => 'gestor',
                'company_id' => $company->id,
            ],
            [
                'name' => 'SDR Demo',
                'email' => 'sdr@empresa.local',
                'role' => 'sdr',
                'company_id' => $company->id,
            ],
        ];

        foreach ($users as $userData) {
            User::query()->updateOrCreate(
                ['email' => $userData['email']],
                [
                    'company_id' => $userData['company_id'],
                    'name' => $userData['name'],
                    'password' => Hash::make('12345678'),
                    'role' => $userData['role'],
                    'active' => true,
                ]
            );
        }

        $settings = CompanyBusinessSetting::query()->firstOrCreate(
            ['company_id' => $company->id],
            [
                'timezone' => 'America/Sao_Paulo',
                'workday_start_time' => '08:00:00',
                'workday_end_time' => '18:00:00',
                'lunch_start_time' => '12:00:00',
                'lunch_end_time' => '13:00:00',
                'working_days' => [1, 2, 3, 4, 5],
                'repeated_lead_window_days' => 90,
                'rescue_threshold_hours' => 24,
                'first_response_sla_minutes' => 15,
                'follow_up_sla_hours' => 24,
                'stale_conversation_hours' => 48,
                'webhook_token' => null,
            ]
        );

        $webhookTokenConfigured = true;
        if (blank($settings->webhook_token)) {
            $settings->forceFill([
                'webhook_token' => 'demo_' . Str::lower(Str::random(28)),
            ])->save();
        }

        $pipeline = Pipeline::query()->firstOrCreate(
            ['company_id' => $company->id, 'name' => 'Pipeline Comercial Padrão'],
            ['is_default' => true]
        );

        if (!$pipeline->is_default) {
            $pipeline->forceFill(['is_default' => true])->save();
        }

        $defaultColumns = [
            ['Novo Contato', 1, 'Contato inicial, ainda sem descoberta comercial suficiente.'],
            ['Em Atendimento', 2, 'Conversa em descoberta, diagnóstico de necessidade ou qualificação.'],
            ['Proposta/Negociação', 3, 'Lead qualificado discutindo proposta, preço, prazo ou condições comerciais.'],
            ['Fechado', 4, 'Cliente confirmou a compra, contratação ou próximo passo de onboarding.'],
            ['Perdido', 5, 'Lead recusou a oferta ou encerrou o processo sem perspectiva de retomada.'],
        ];

        foreach ($defaultColumns as [$name, $position, $rulePrompt]) {
            KanbanColumn::query()->updateOrCreate(
                [
                    'company_id' => $company->id,
                    'pipeline_id' => $pipeline->id,
                    'name' => $name,
                ],
                [
                    'position' => $position,
                    'rule_prompt' => $rulePrompt,
                    'is_terminal' => in_array($name, ['Fechado', 'Perdido'], true),
                ]
            );
        }

        $demoConversationCount = $this->seedConversationIntelligenceDemo($company, $pipeline);

        $token = (string) $settings->fresh()->webhook_token;
        $tokenSuffix = strlen($token) >= 4 ? substr($token, -4) : $token;

        return [
            'company_ok' => true,
            'users_ok' => true,
            'settings_ok' => true,
            'pipeline_ok' => true,
            'columns_ok' => true,
            'demo_conversations_ok' => $demoConversationCount === 5,
            'demo_conversations_count' => $demoConversationCount,
            'webhook_token_configured' => $webhookTokenConfigured,
            'webhook_token_suffix' => $tokenSuffix,
        ];
    }

    private function seedConversationIntelligenceDemo(Company $company, Pipeline $pipeline): int
    {
        $owner = User::query()
            ->where('company_id', $company->id)
            ->where('email', 'sdr@empresa.local')
            ->firstOrFail();

        $scenarios = [
            [
                'lead' => ['name' => 'Ana Martins', 'phone' => '+5511911101001', 'source' => 'instagram', 'creative_id' => 'meta-demo-ana', 'creative_url' => 'https://instagram.com/p/demo-ana', 'campaign_name' => 'Expansão Comercial SP'],
                'stage' => 'Proposta/Negociação',
                'hours_ago' => 2,
                'status' => 'active',
                'messages' => [
                    ['inbound', 'Olá! Vi o conteúdo de vocês no Instagram. Preciso organizar o atendimento comercial da minha equipe.'],
                    ['outbound', 'Olá, Ana! Para entender o cenário: quantas pessoas atendem hoje e onde vocês mais perdem oportunidades?'],
                    ['inbound', 'São seis vendedores. Perdemos muitos leads porque ninguém acompanha as conversas e os retornos atrasam.'],
                    ['outbound', 'Entendi. Com visibilidade das conversas e dos gargalos, o gestor consegue agir antes da oportunidade esfriar. Existe prazo para resolver isso?'],
                    ['inbound', 'Quero colocar em operação ainda este mês. Pode me enviar uma proposta para seis usuários?'],
                    ['outbound', 'Perfeito. Vou estruturar a proposta para seis usuários e amanhã às 10h podemos revisar juntos. Funciona para você?'],
                    ['inbound', 'Funciona sim, pode agendar.'],
                ],
            ],
            [
                'lead' => ['name' => 'Bruno Almeida', 'phone' => '+5511911101002', 'source' => 'google', 'creative_id' => 'search-demo-bruno', 'creative_url' => 'https://google.com/search?campaign=demo', 'campaign_name' => 'Revenue Intelligence'],
                'stage' => 'Em Atendimento',
                'hours_ago' => 6,
                'status' => 'active',
                'messages' => [
                    ['inbound', 'Encontrei vocês no Google. Quanto custa a plataforma?'],
                    ['outbound', 'Olá, Bruno! O valor depende do tamanho da operação. Hoje quantas pessoas fazem atendimento comercial?'],
                    ['inbound', 'Quatro. Estou comparando com outra ferramenta que custa menos de mil por mês.'],
                    ['outbound', 'Temos planos para esse porte e o diferencial é a análise gerencial das conversas, não apenas um CRM.'],
                    ['inbound', 'Entendi. Vou avaliar as opções e volto a falar.'],
                    ['outbound', 'Combinado, fico à disposição.'],
                ],
            ],
            [
                'lead' => ['name' => 'Carla Souza', 'phone' => '+5511911101003', 'source' => 'indicacao', 'creative_id' => null, 'creative_url' => null, 'campaign_name' => null],
                'stage' => 'Novo Contato',
                'hours_ago' => 12,
                'status' => 'active',
                'messages' => [
                    ['inbound', 'A Fernanda me indicou vocês. Queria saber como funciona e qual o valor.'],
                    ['outbound', 'Olá, Carla! A plataforma acompanha indicadores das conversas comerciais e organiza os leads no funil.'],
                    ['inbound', 'Certo, e quanto fica?'],
                    ['outbound', 'Temos opções conforme o número de usuários. Posso preparar uma estimativa.'],
                    ['inbound', 'Pode mandar por aqui.'],
                ],
            ],
            [
                'lead' => ['name' => 'Diego Rocha', 'phone' => '+5511911101004', 'source' => 'facebook', 'creative_id' => 'meta-demo-diego', 'creative_url' => 'https://facebook.com/ads/demo-diego', 'campaign_name' => 'Gestão de Vendas'],
                'stage' => 'Fechado',
                'hours_ago' => 28,
                'status' => 'closed',
                'messages' => [
                    ['inbound', 'Vi o anúncio de gestão comercial. Temos doze consultores e pouca visibilidade do que acontece no WhatsApp.'],
                    ['outbound', 'Olá, Diego! Além da visibilidade, quais resultados vocês precisam melhorar primeiro?'],
                    ['inbound', 'Tempo de resposta e conversão. Também preciso garantir que os dados fiquem seguros.'],
                    ['outbound', 'A coleta será pela API oficial da Meta e o gestor terá indicadores por equipe, conversa e etapa. Podemos fazer um piloto com os doze consultores.'],
                    ['inbound', 'Ótimo. A diretoria aprovou o piloto. Quero seguir com a contratação.'],
                    ['outbound', 'Excelente. Vou enviar os dados do onboarding e marcar a reunião de implantação para terça-feira.'],
                    ['inbound', 'Fechado, terça às 14h está ótimo.'],
                ],
            ],
            [
                'lead' => ['name' => 'Elisa Nogueira', 'phone' => '+5511911101005', 'source' => 'evento', 'creative_id' => null, 'creative_url' => null, 'campaign_name' => 'Summit de Vendas 2026'],
                'stage' => 'Proposta/Negociação',
                'hours_ago' => 48,
                'status' => 'closed',
                'messages' => [
                    ['inbound', 'Conheci o LEADSWHATS no evento. A solução faz sentido, mas preciso alinhar com meu sócio.'],
                    ['outbound', 'Claro, Elisa. Qual é a principal dúvida dele para eu ajudar com informações objetivas?'],
                    ['inbound', 'Ele está preocupado com o momento financeiro e quer entender o retorno antes de aprovar.'],
                    ['outbound', 'Podemos demonstrar os indicadores e começar com uma equipe menor para validar o retorno.'],
                    ['inbound', 'Boa ideia. Vou conversar com ele e depois retorno.'],
                    ['outbound', 'Perfeito. Posso falar com você na próxima semana para saber como foi?'],
                    ['inbound', 'Pode sim.'],
                ],
            ],
        ];

        foreach ($scenarios as $scenarioIndex => $scenario) {
            $leadData = $scenario['lead'];
            $startedAt = now()->startOfMinute()->subHours($scenario['hours_ago']);

            $lead = Lead::query()->updateOrCreate(
                ['company_id' => $company->id, 'phone_e164' => $leadData['phone']],
                [
                    'owner_user_id' => $owner->id,
                    'name' => $leadData['name'],
                    'source' => $leadData['source'],
                    'source_method' => 'auto',
                    'source_updated_at' => $startedAt,
                    'creative_id' => $leadData['creative_id'],
                    'creative_url' => $leadData['creative_url'],
                    'campaign_name' => $leadData['campaign_name'],
                    'is_repeat_lead' => false,
                    'first_inbound_at' => $startedAt,
                    'last_inbound_at' => $startedAt->copy()->addMinutes((count($scenario['messages']) - 1) * 7),
                    'last_outbound_at' => $startedAt->copy()->addMinutes((count($scenario['messages']) - 2) * 7),
                    'first_response_seconds' => 420,
                    'metadata' => ['demo' => true, 'scenario' => $scenarioIndex + 1],
                ]
            );

            LeadSourceHistory::query()->firstOrCreate(
                [
                    'company_id' => $company->id,
                    'lead_id' => $lead->id,
                    'new_source' => $leadData['source'],
                    'change_type' => 'auto',
                    'reason' => 'Demo bootstrap: origem inicial.',
                ],
                [
                    'previous_source' => null,
                    'changed_by_user_id' => null,
                    'changed_at' => $startedAt,
                ]
            );

            $conversation = Conversation::query()->updateOrCreate(
                ['company_id' => $company->id, 'lead_id' => $lead->id],
                [
                    'owner_user_id' => $owner->id,
                    'status' => $scenario['status'],
                    'started_at' => $startedAt,
                    'last_message_at' => $startedAt->copy()->addMinutes((count($scenario['messages']) - 1) * 7),
                    'closed_at' => $scenario['status'] === 'closed'
                        ? $startedAt->copy()->addMinutes((count($scenario['messages']) - 1) * 7)
                        : null,
                ]
            );

            foreach ($scenario['messages'] as $messageIndex => [$direction, $body]) {
                Message::query()->updateOrCreate(
                    [
                        'company_id' => $company->id,
                        'provider' => 'fake',
                        'external_message_id' => sprintf('demo-ci-%d-%d', $scenarioIndex + 1, $messageIndex + 1),
                    ],
                    [
                        'lead_id' => $lead->id,
                        'conversation_id' => $conversation->id,
                        'direction' => $direction,
                        'channel' => 'text',
                        'body' => $body,
                        'sent_at' => $startedAt->copy()->addMinutes($messageIndex * 7),
                        'raw_payload' => ['demo' => true],
                        'is_rescue' => false,
                        'metadata' => ['classification' => 'lead_novo', 'raw_source' => $leadData['source']],
                    ]
                );
            }

            $column = KanbanColumn::query()
                ->where('company_id', $company->id)
                ->where('pipeline_id', $pipeline->id)
                ->where('name', $scenario['stage'])
                ->firstOrFail();

            LeadStageHistory::query()->firstOrCreate(
                [
                    'company_id' => $company->id,
                    'lead_id' => $lead->id,
                    'move_source' => 'system',
                    'reason' => 'Demo bootstrap: posicionamento inicial.',
                ],
                [
                    'from_column_id' => null,
                    'to_column_id' => $column->id,
                    'moved_by_user_id' => null,
                    'moved_at' => $startedAt,
                ]
            );
        }

        return Conversation::query()
            ->where('company_id', $company->id)
            ->whereHas('lead', static fn ($query) => $query->where('metadata->demo', true))
            ->count();
    }
}
