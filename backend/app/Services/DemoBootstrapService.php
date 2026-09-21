<?php

namespace App\Services;

use App\Models\Company;
use App\Models\CompanyBusinessSetting;
use App\Models\Conversation;
use App\Models\ConversationQualityScore;
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
    public function __construct(private readonly AccessControlService $accessControl) {}

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
                'name' => 'Oral Sin - Unidade Demo',
                'timezone' => 'America/Sao_Paulo',
                'work_start' => '08:00',
                'work_end' => '18:00',
                'lunch_start' => '12:00',
                'lunch_end' => '13:00',
                'active' => true,
            ]
        );
        $profiles = $this->accessControl->createProfilesForCompany($company);

        $users = [
            [
                'name' => 'Platform Admin Demo',
                'email' => 'platform@leadswhats.local',
                'role' => 'platform_admin',
                'company_id' => null,
            ],
            [
                'name' => 'Administração Oral Sin',
                'email' => 'admin@leadswhats.local',
                'role' => 'admin',
                'company_id' => $company->id,
            ],
            [
                'name' => 'Gestora da Clínica',
                'email' => 'gestor@empresa.local',
                'role' => 'gestor',
                'company_id' => $company->id,
            ],
            [
                'name' => 'Camila Nunes',
                'email' => 'sdr@empresa.local',
                'role' => 'sdr',
                'company_id' => $company->id,
            ],
            [
                'name' => 'Marina Costa',
                'email' => 'marina@empresa.local',
                'role' => 'sdr',
                'company_id' => $company->id,
            ],
            [
                'name' => 'Rafael Lima',
                'email' => 'rafael@empresa.local',
                'role' => 'sdr',
                'company_id' => $company->id,
            ],
        ];

        foreach ($users as $userData) {
            User::query()->updateOrCreate(
                ['email' => $userData['email']],
                [
                    'company_id' => $userData['company_id'],
                    'access_profile_id' => $userData['company_id'] ? $profiles[$userData['role'] === 'admin' ? 'administrador' : $userData['role']]->id : null,
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
                'webhook_token' => 'demo_'.Str::lower(Str::random(28)),
            ])->save();
        }

        $pipeline = Pipeline::query()
            ->where('company_id', $company->id)
            ->whereIn('name', ['Jornada do Paciente - Oral Sin', 'Pipeline Comercial Padrão'])
            ->orderByRaw("CASE WHEN name = 'Jornada do Paciente - Oral Sin' THEN 0 ELSE 1 END")
            ->first();

        if ($pipeline === null) {
            $pipeline = Pipeline::query()->create([
                'company_id' => $company->id,
                'name' => 'Jornada do Paciente - Oral Sin',
                'is_default' => true,
            ]);
        } else {
            $pipeline->forceFill([
                'name' => 'Jornada do Paciente - Oral Sin',
                'is_default' => true,
            ])->save();
        }

        $legacyColumnNames = [
            'Novo Contato' => 'Novo Lead',
            'Proposta/Negociação' => 'Em Negociação',
            'Fechado' => 'Tratamento Fechado',
            'Perdido' => 'Não Convertido',
        ];

        foreach ($legacyColumnNames as $legacyName => $newName) {
            $legacyColumn = KanbanColumn::query()
                ->where('company_id', $company->id)
                ->where('pipeline_id', $pipeline->id)
                ->where('name', $legacyName)
                ->first();
            $newColumnExists = KanbanColumn::query()
                ->where('company_id', $company->id)
                ->where('pipeline_id', $pipeline->id)
                ->where('name', $newName)
                ->exists();

            if ($legacyColumn !== null && ! $newColumnExists) {
                $legacyColumn->forceFill(['name' => $newName])->save();
            }
        }

        $defaultColumns = [
            ['Novo Lead', 1, 'Recomende quando o contato acabou de chegar e ainda falta entender sua necessidade e disponibilidade.'],
            ['Em Atendimento', 2, 'Recomende quando a equipe está acolhendo o contato, entendendo o interesse e buscando um próximo passo.'],
            ['Avaliação Agendada', 3, 'Recomende somente quando o paciente confirmou uma data e horário para avaliação profissional.'],
            ['Avaliação Realizada', 4, 'Recomende quando a conversa informa que a avaliação profissional já aconteceu, sem confirmação de fechamento.'],
            ['Em Negociação', 5, 'Recomende quando o paciente está avaliando condições, pagamento ou decisão após a avaliação.'],
            ['Tratamento Fechado', 6, 'Recomende somente quando o paciente confirmou que seguirá com o tratamento e há próximo passo combinado.'],
            ['Não Convertido', 7, 'Recomende quando o paciente recusou ou encerrou a oportunidade sem intenção atual de continuar.'],
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
                    'is_terminal' => in_array($name, ['Tratamento Fechado', 'Não Convertido'], true),
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
            'demo_conversations_ok' => $demoConversationCount >= 14,
            'demo_conversations_count' => $demoConversationCount,
            'webhook_token_configured' => $webhookTokenConfigured,
            'webhook_token_suffix' => $tokenSuffix,
        ];
    }

    private function seedConversationIntelligenceDemo(Company $company, Pipeline $pipeline): int
    {
        $owners = User::query()
            ->where('company_id', $company->id)
            ->whereIn('email', ['sdr@empresa.local', 'marina@empresa.local', 'rafael@empresa.local'])
            ->get()
            ->keyBy('email');

        $scenarios = [
            [
                'lead' => ['name' => 'Ana Martins', 'phone' => '+5511911101001', 'source' => 'instagram', 'creative_id' => 'instagram-implantes-ana', 'creative_url' => 'https://instagram.com/p/oralsin-implantes-demo', 'campaign_name' => 'Implantes | Avaliação'],
                'stage' => 'Avaliação Agendada',
                'owner_email' => 'sdr@empresa.local',
                'received_today' => true,
                'minutes_ago' => 100,
                'is_repeat_lead' => false,
                'first_response_seconds' => 240,
                'status' => 'active',
                'analysis' => [
                    'score' => 92,
                    'intent' => 'Avaliação confirmada',
                    'objections' => ['Receio sobre o procedimento'],
                    'next_step' => 'Comparecer à avaliação amanhã às 14h',
                    'recommended_stage' => 'Avaliação Agendada',
                    'confidence' => 0.96,
                    'summary' => 'Ana foi acolhida, explicou o interesse em implante e confirmou avaliação para amanhã às 14h.',
                    'classification_reason' => 'Data e horário da avaliação foram confirmados explicitamente pela paciente.',
                    'commercial_data' => [
                        'treatment_interest' => 'Implantes',
                        'main_need' => 'Entender opções para substituir um dente perdido',
                        'urgency' => 'Deseja iniciar a avaliação nesta semana',
                        'objection' => 'Receio sobre o procedimento',
                        'availability' => 'Amanhã à tarde',
                        'appointment_status' => 'Agendada',
                        'appointment_date' => 'Amanhã às 14h',
                        'next_step' => 'Comparecer à avaliação',
                    ],
                    'positive_points' => ['Acolhimento diante do receio', 'Necessidade e disponibilidade confirmadas', 'Agendamento concluído'],
                    'errors' => [],
                ],
                'messages' => [
                    ['inbound', 'Oi, vi o Instagram da Oral Sin. Perdi um dente e queria entender melhor sobre implante, mas confesso que tenho um pouco de medo.'],
                    ['outbound', 'Oi, Ana! Seja bem-vinda 😊 Entendo sua preocupação. A equipe pode te acolher e explicar tudo com calma em uma avaliação profissional, sem compromisso de decisão.'],
                    ['inbound', 'Obrigada. Eu queria resolver isso logo. Vocês têm horário essa semana?'],
                    ['outbound', 'Temos amanhã às 14h ou sexta às 9h. Qual fica melhor para você?'],
                    ['inbound', 'Amanhã às 14h fica ótimo.'],
                    ['outbound', 'Perfeito, Ana. Sua avaliação ficou agendada para amanhã às 14h. Vou te enviar a confirmação e as orientações de chegada.'],
                    ['inbound', 'Combinado, obrigada pelo atendimento!'],
                ],
            ],
            [
                'lead' => ['name' => 'Bruno Almeida', 'phone' => '+5511911101002', 'source' => 'google', 'creative_id' => 'google-implantes-bruno', 'creative_url' => 'https://google.com/search?q=oral+sin+implantes', 'campaign_name' => 'Google | Implantes'],
                'stage' => 'Em Atendimento',
                'owner_email' => 'marina@empresa.local',
                'received_today' => true,
                'minutes_ago' => 210,
                'is_repeat_lead' => true,
                'first_response_seconds' => 600,
                'status' => 'active',
                'analysis' => [
                    'score' => 68,
                    'intent' => 'Quer agendar após esclarecer preço',
                    'objections' => ['Dúvida de preço', 'Condições de pagamento'],
                    'next_step' => 'Confirmar no sistema a avaliação solicitada para sábado às 9h',
                    'recommended_stage' => 'Avaliação Agendada',
                    'confidence' => 0.88,
                    'summary' => 'Bruno retomou o contato sobre implantes, perguntou sobre valores e solicitou o horário de sábado às 9h.',
                    'classification_reason' => 'O paciente escolheu data e horário; falta apenas refletir a confirmação no funil.',
                    'commercial_data' => [
                        'treatment_interest' => 'Implantes',
                        'main_need' => 'Entender possibilidades e investimento',
                        'urgency' => 'Deseja atendimento no sábado',
                        'objection' => 'Preço e formas de pagamento',
                        'availability' => 'Sábado pela manhã',
                        'appointment_status' => 'Horário solicitado',
                        'appointment_date' => 'Sábado às 9h',
                        'payment_concern' => 'Quer conhecer valores e condições',
                        'next_step' => 'Confirmar o agendamento de sábado às 9h',
                    ],
                    'positive_points' => ['Retomada do histórico anterior', 'Disponibilidade identificada'],
                    'errors' => ['Confirmar o agendamento de forma objetiva e encerrar com instruções claras'],
                ],
                'messages' => [
                    ['inbound', 'Bom dia. Falei com vocês alguns meses atrás sobre implante. Queria saber se os valores e formas de pagamento são explicados pelo WhatsApp.'],
                    ['outbound', 'Bom dia, Bruno! Que bom falar com você novamente. Os valores dependem da avaliação de cada caso, e a equipe apresenta as condições comerciais com transparência depois dessa etapa.'],
                    ['inbound', 'Entendi. Eu consigo ir só no sábado de manhã.'],
                    ['outbound', 'Temos uma possibilidade às 9h neste sábado. Posso separar esse horário para sua avaliação?'],
                    ['inbound', 'Pode sim, sábado às 9h.'],
                    ['outbound', 'Ótimo, vou conferir a confirmação na agenda e já te retorno com os detalhes.'],
                ],
            ],
            [
                'lead' => ['name' => 'Carla Souza', 'phone' => '+5511911101003', 'source' => 'indicacao', 'creative_id' => null, 'creative_url' => null, 'campaign_name' => 'Indicação de paciente'],
                'stage' => 'Novo Lead',
                'owner_email' => 'rafael@empresa.local',
                'received_today' => true,
                'minutes_ago' => 260,
                'is_repeat_lead' => false,
                'first_response_seconds' => 90,
                'status' => 'active',
                'analysis' => [
                    'score' => 84,
                    'intent' => 'Interesse ativo em avaliação',
                    'objections' => ['Quer saber se prótese é adequada ao caso'],
                    'next_step' => 'Oferecer horários após as 17h para avaliação profissional',
                    'recommended_stage' => 'Em Atendimento',
                    'confidence' => 0.9,
                    'summary' => 'Carla chegou por indicação, informou interesse em prótese e disponibilidade após as 17h; precisa receber opções de agenda.',
                    'classification_reason' => 'A necessidade e a disponibilidade já foram descobertas, portanto o contato avançou além de um novo lead.',
                    'commercial_data' => [
                        'treatment_interest' => 'Prótese',
                        'main_need' => 'Avaliar uma alternativa para melhorar mastigação e segurança ao sorrir',
                        'objection' => 'Precisa de avaliação profissional para saber qual opção é adequada',
                        'availability' => 'Dias úteis após as 17h',
                        'appointment_status' => 'A agendar',
                        'next_step' => 'Enviar opções de horário após as 17h',
                    ],
                    'positive_points' => ['Direcionamento seguro para avaliação profissional', 'Necessidade e disponibilidade identificadas'],
                    'errors' => [],
                ],
                'messages' => [
                    ['inbound', 'Oi! A Márcia me indicou a Oral Sin. Estou procurando uma solução de prótese e queria saber se serve para o meu caso.'],
                    ['outbound', 'Oi, Carla! Seja bem-vinda. Para dizer qual opção é adequada, o dentista precisa fazer uma avaliação profissional. Posso entender o que você busca e te ajudar com o agendamento.'],
                    ['inbound', 'Quero voltar a mastigar com mais segurança e também me sentir melhor para sorrir.'],
                    ['outbound', 'Entendi, obrigada por contar. Qual período costuma ser melhor para você vir à clínica?'],
                    ['inbound', 'Durante a semana, depois das 17h.'],
                ],
            ],
            [
                'lead' => ['name' => 'Diego Rocha', 'phone' => '+5511911101004', 'source' => 'facebook', 'creative_id' => 'facebook-avaliacao-diego', 'creative_url' => 'https://facebook.com/oralsin/demo-avaliacao', 'campaign_name' => 'Facebook | Conheça a Clínica'],
                'stage' => 'Tratamento Fechado',
                'owner_email' => 'sdr@empresa.local',
                'hours_ago' => 28,
                'created_days_ago' => 3,
                'is_repeat_lead' => false,
                'first_response_seconds' => 300,
                'status' => 'closed',
                'analysis' => [
                    'score' => 95,
                    'intent' => 'Tratamento confirmado',
                    'objections' => ['Organização da forma de pagamento'],
                    'next_step' => 'Comparecer à etapa inicial combinada na terça-feira às 10h',
                    'recommended_stage' => 'Tratamento Fechado',
                    'confidence' => 0.98,
                    'summary' => 'Após avaliação e conversa comercial, Diego confirmou que seguirá com o tratamento e combinou o próximo comparecimento.',
                    'classification_reason' => 'O paciente confirmou a decisão e o próximo passo foi agendado.',
                    'commercial_data' => [
                        'treatment_interest' => 'Implantes',
                        'main_need' => 'Dar continuidade ao plano apresentado após avaliação',
                        'objection' => 'Organização da forma de pagamento',
                        'availability' => 'Terça-feira pela manhã',
                        'appointment_status' => 'Próxima etapa confirmada',
                        'appointment_date' => 'Terça-feira às 10h',
                        'payment_concern' => 'Condições revisadas com o atendimento',
                        'next_step' => 'Comparecer à clínica terça-feira às 10h',
                    ],
                    'positive_points' => ['Objeção comercial tratada com clareza', 'Decisão e próximo passo confirmados'],
                    'errors' => [],
                ],
                'messages' => [
                    ['inbound', 'Oi, Camila. Conversei com minha esposa sobre o que foi apresentado na avaliação.'],
                    ['outbound', 'Oi, Diego! Claro. Ficou alguma dúvida sobre as etapas ou sobre as condições que conversamos?'],
                    ['inbound', 'A principal dúvida era organizar o pagamento, mas do jeito que vocês explicaram conseguimos seguir.'],
                    ['outbound', 'Que bom. Posso então registrar sua confirmação e combinar a próxima ida à clínica?'],
                    ['inbound', 'Pode, quero fechar e começar.'],
                    ['outbound', 'Perfeito. Temos terça-feira às 10h para a etapa inicial combinada. Funciona para você?'],
                    ['inbound', 'Funciona sim. Terça às 10h estarei aí.'],
                ],
            ],
            [
                'lead' => ['name' => 'Elisa Nogueira', 'phone' => '+5511911101005', 'source' => 'site', 'creative_id' => 'site-form-avaliacao-elisa', 'creative_url' => 'https://oralsin.local/avaliacao', 'campaign_name' => 'Site | Formulário de Avaliação'],
                'stage' => 'Em Negociação',
                'owner_email' => 'marina@empresa.local',
                'hours_ago' => 30,
                'created_days_ago' => 5,
                'is_repeat_lead' => false,
                'first_response_seconds' => 780,
                'status' => 'active',
                'analysis' => [
                    'score' => 64,
                    'intent' => 'Avaliando condições comerciais',
                    'objections' => ['Precisa organizar o orçamento familiar'],
                    'next_step' => 'Retomar o contato e confirmar se deseja revisar as condições com a consultora',
                    'recommended_stage' => 'Em Negociação',
                    'confidence' => 0.86,
                    'summary' => 'Elisa realizou a avaliação, recebeu as condições e pediu tempo para organizar a decisão com a família.',
                    'classification_reason' => 'A avaliação já ocorreu e a conversa está concentrada nas condições e na decisão comercial.',
                    'commercial_data' => [
                        'treatment_interest' => 'Prótese',
                        'main_need' => 'Avaliar o início do tratamento apresentado na clínica',
                        'objection' => 'Organização do orçamento familiar',
                        'appointment_status' => 'Avaliação realizada',
                        'payment_concern' => 'Precisa conversar com a família antes de decidir',
                        'next_step' => 'Confirmar interesse em nova conversa sobre condições',
                    ],
                    'positive_points' => ['Objeção financeira identificada sem pressionar a paciente'],
                    'errors' => ['Combinar uma data objetiva para o próximo contato'],
                ],
                'messages' => [
                    ['inbound', 'Oi, Marina. Fiz a avaliação ontem e gostei bastante do atendimento.'],
                    ['outbound', 'Oi, Elisa! Fico feliz em saber. Como você se sentiu em relação às condições apresentadas pela consultora?'],
                    ['inbound', 'Preciso organizar o orçamento e conversar com minha família antes de decidir.'],
                    ['outbound', 'Claro, é importante decidir com tranquilidade. Quer que eu retome com você em algum dia específico?'],
                    ['inbound', 'Pode me chamar no começo da próxima semana.'],
                    ['outbound', 'Combinado. Se preferir, também posso organizar uma nova conversa com a consultora para revisar as condições.'],
                ],
            ],
            [
                'lead' => ['name' => 'Fernanda Ribeiro', 'phone' => '+5511911101006', 'source' => 'instagram', 'creative_id' => 'instagram-avaliacao-fernanda', 'creative_url' => 'https://instagram.com/p/oralsin-avaliacao-demo', 'campaign_name' => 'Instagram | Avaliação Odontológica'],
                'stage' => 'Avaliação Agendada',
                'owner_email' => 'rafael@empresa.local',
                'received_today' => true,
                'minutes_ago' => 70,
                'is_repeat_lead' => false,
                'first_response_seconds' => 120,
                'status' => 'active',
                'analysis' => [
                    'score' => 94,
                    'intent' => 'Avaliação confirmada com urgência',
                    'objections' => [],
                    'next_step' => 'Comparecer à avaliação hoje às 16h',
                    'recommended_stage' => 'Avaliação Agendada',
                    'confidence' => 0.98,
                    'summary' => 'Fernanda recebeu atendimento rápido e personalizado e confirmou uma avaliação para hoje às 16h.',
                    'classification_reason' => 'A paciente confirmou data e horário para avaliação profissional.',
                    'commercial_data' => [
                        'treatment_interest' => 'Avaliação odontológica',
                        'main_need' => 'Entender opções para recuperar confiança ao sorrir',
                        'urgency' => 'Gostaria de ser atendida ainda hoje',
                        'availability' => 'Hoje à tarde',
                        'appointment_status' => 'Agendada',
                        'appointment_date' => 'Hoje às 16h',
                        'next_step' => 'Comparecer à avaliação',
                    ],
                    'positive_points' => ['Resposta rápida e acolhedora', 'Personalização pela urgência', 'Agendamento confirmado'],
                    'errors' => [],
                ],
                'messages' => [
                    ['inbound', 'Olá! Vi o perfil de vocês e queria marcar uma avaliação. Tenho evitado sorrir nas fotos e quero entender minhas opções.'],
                    ['outbound', 'Olá, Fernanda! Obrigado por confiar isso à gente. A avaliação profissional é o melhor caminho para entender suas possibilidades com segurança.'],
                    ['inbound', 'Vocês têm algum horário ainda hoje?'],
                    ['outbound', 'Tenho disponibilidade hoje às 16h. Esse horário funciona para você?'],
                    ['inbound', 'Sim, consigo chegar às 16h.'],
                    ['outbound', 'Perfeito! Avaliação confirmada para hoje às 16h. Já vou te enviar a localização e a confirmação.'],
                ],
            ],
            [
                'lead' => ['name' => 'Gustavo Freire', 'phone' => '+5511911101007', 'source' => 'google', 'creative_id' => 'google-protese-gustavo', 'creative_url' => 'https://google.com/search?q=oral+sin+protese', 'campaign_name' => 'Google | Prótese'],
                'stage' => 'Em Negociação',
                'owner_email' => 'sdr@empresa.local',
                'hours_ago' => 18,
                'created_days_ago' => 4,
                'is_repeat_lead' => false,
                'first_response_seconds' => 420,
                'status' => 'active',
                'analysis' => [
                    'score' => 75,
                    'intent' => 'Aceite comercial confirmado',
                    'objections' => ['Condição de pagamento'],
                    'next_step' => 'Registrar o fechamento e enviar a confirmação do próximo encontro',
                    'recommended_stage' => 'Tratamento Fechado',
                    'confidence' => 0.93,
                    'summary' => 'Gustavo confirmou que seguirá com o tratamento nas condições já apresentadas e aguarda o registro do próximo passo.',
                    'classification_reason' => 'O aceite comercial foi explícito, mas o card ainda permanece em negociação.',
                    'commercial_data' => [
                        'treatment_interest' => 'Prótese',
                        'main_need' => 'Seguir com o tratamento apresentado após avaliação',
                        'objection' => 'Condição de pagamento',
                        'appointment_status' => 'Avaliação realizada',
                        'payment_concern' => 'Condição revisada e aceita',
                        'next_step' => 'Registrar fechamento e confirmar próxima ida à clínica',
                    ],
                    'positive_points' => ['Objeção de pagamento esclarecida', 'Aceite obtido'],
                    'errors' => ['Atualizar a etapa e formalizar o próximo compromisso'],
                ],
                'messages' => [
                    ['inbound', 'Oi, Camila. Revisei a condição que conversamos depois da avaliação.'],
                    ['outbound', 'Oi, Gustavo! Ficou alguma dúvida que eu possa esclarecer antes da sua decisão?'],
                    ['inbound', 'Não, ficou claro. Pode seguir com aquela condição, quero fechar.'],
                    ['outbound', 'Ótimo! Vou registrar sua confirmação e organizar o próximo encontro na clínica.'],
                    ['inbound', 'Perfeito, fico aguardando a data.'],
                ],
            ],
            [
                'lead' => ['name' => 'Helena Prado', 'phone' => '+5511911101008', 'source' => 'facebook', 'creative_id' => 'facebook-avaliacao-helena', 'creative_url' => 'https://facebook.com/oralsin/demo-agenda', 'campaign_name' => 'Facebook | Agende sua Avaliação'],
                'stage' => 'Em Atendimento',
                'owner_email' => 'marina@empresa.local',
                'hours_ago' => 42,
                'created_days_ago' => 6,
                'is_repeat_lead' => false,
                'first_response_seconds' => 900,
                'status' => 'active',
                'analysis' => [
                    'score' => 38,
                    'intent' => 'Interesse inicial com alto risco de perda',
                    'objections' => ['Dúvida sobre disponibilidade e avaliação'],
                    'next_step' => 'Retomar de forma acolhedora, explicar a avaliação e oferecer dois horários objetivos',
                    'recommended_stage' => 'Em Atendimento',
                    'confidence' => 0.78,
                    'summary' => 'Helena pediu informações sobre avaliação, recebeu uma resposta pouco personalizada e não respondeu aos retornos.',
                    'classification_reason' => 'Ainda há interesse inicial, mas não existe confirmação suficiente para avançar ou encerrar a oportunidade.',
                    'commercial_data' => [
                        'treatment_interest' => 'Avaliação odontológica',
                        'main_need' => 'Conhecer a clínica e entender o atendimento',
                        'objection' => 'Faltaram informações claras sobre agenda e avaliação',
                        'appointment_status' => 'Não agendada',
                        'next_step' => 'Fazer follow-up personalizado com opções de horário',
                    ],
                    'positive_points' => ['Contato de follow-up realizado'],
                    'errors' => ['Pouco acolhimento', 'Necessidade não explorada', 'Nenhuma opção objetiva de horário'],
                ],
                'messages' => [
                    ['inbound', 'Oi, vi o anúncio. Como funciona a avaliação e quais horários vocês têm?'],
                    ['outbound', 'Olá. A avaliação é presencial. Para marcar, preciso dos seus dados.'],
                    ['outbound', 'Ainda tem interesse?'],
                    ['outbound', 'Helena, seguimos à disposição caso queira agendar.'],
                ],
            ],
            [
                'lead' => ['name' => 'Igor Mendes', 'phone' => '+5511911101009', 'source' => 'indicacao', 'creative_id' => null, 'creative_url' => null, 'campaign_name' => 'Indicação de paciente'],
                'stage' => 'Tratamento Fechado',
                'owner_email' => 'rafael@empresa.local',
                'hours_ago' => 20,
                'created_days_ago' => 3,
                'is_repeat_lead' => false,
                'first_response_seconds' => 150,
                'status' => 'closed',
                'analysis' => [
                    'score' => 90,
                    'intent' => 'Tratamento confirmado',
                    'objections' => [],
                    'next_step' => 'Receber a confirmação da próxima etapa na clínica',
                    'recommended_stage' => 'Tratamento Fechado',
                    'confidence' => 0.97,
                    'summary' => 'Igor chegou por indicação, realizou a avaliação e confirmou que seguirá com o tratamento apresentado.',
                    'classification_reason' => 'A decisão comercial foi confirmada e a equipe já está organizando o próximo passo.',
                    'commercial_data' => [
                        'treatment_interest' => 'Implantes',
                        'main_need' => 'Dar continuidade ao tratamento apresentado na avaliação',
                        'appointment_status' => 'Avaliação realizada',
                        'next_step' => 'Enviar confirmação da próxima etapa',
                    ],
                    'positive_points' => ['Confirmação objetiva da decisão', 'Próximo passo encaminhado'],
                    'errors' => [],
                ],
                'messages' => [
                    ['inbound', 'Oi, Rafael. Vim pela indicação do meu irmão e fiz a avaliação hoje.'],
                    ['outbound', 'Oi, Igor! Obrigado pela confiança. Ficou alguma dúvida sobre o que foi apresentado pela equipe?'],
                    ['inbound', 'Não, foi tudo bem explicado. Quero seguir com o tratamento.'],
                    ['outbound', 'Excelente. Vou registrar sua confirmação e enviar as informações da próxima etapa na clínica.'],
                    ['inbound', 'Certo, fico no aguardo.'],
                ],
            ],
            [
                'lead' => ['name' => 'Juliana Torres', 'phone' => '+5511911101010', 'source' => 'site', 'creative_id' => 'site-form-juliana', 'creative_url' => 'https://oralsin.local/contato', 'campaign_name' => 'Site | Fale com a Clínica'],
                'stage' => 'Não Convertido',
                'owner_email' => 'sdr@empresa.local',
                'hours_ago' => 96,
                'created_days_ago' => 8,
                'is_repeat_lead' => false,
                'first_response_seconds' => 360,
                'status' => 'closed',
                'analysis' => [
                    'score' => 55,
                    'intent' => 'Sem intenção atual de continuar',
                    'objections' => ['Momento financeiro'],
                    'next_step' => 'Encerrar a oportunidade sem novos contatos não solicitados',
                    'recommended_stage' => 'Não Convertido',
                    'confidence' => 0.95,
                    'summary' => 'Juliana informou que não deseja agendar ou continuar neste momento por prioridade financeira.',
                    'classification_reason' => 'A paciente encerrou explicitamente a oportunidade e pediu para não avançar agora.',
                    'commercial_data' => [
                        'treatment_interest' => 'Avaliação odontológica',
                        'main_need' => 'Conhecer opções de atendimento',
                        'objection' => 'Prioridade financeira',
                        'appointment_status' => 'Não agendada',
                        'payment_concern' => 'Não pretende assumir o investimento neste momento',
                        'next_step' => 'Encerrar a oportunidade',
                    ],
                    'positive_points' => ['Decisão da paciente respeitada'],
                    'errors' => ['A descoberta da necessidade poderia ter sido mais completa antes da conversa sobre investimento'],
                ],
                'messages' => [
                    ['inbound', 'Preenchi o formulário para saber sobre avaliação, mas estou preocupada com os custos.'],
                    ['outbound', 'Oi, Juliana. Podemos começar por uma avaliação para entender sua necessidade e depois explicar as possibilidades comerciais.'],
                    ['inbound', 'Obrigada, mas neste momento não quero agendar. Preciso priorizar outras despesas.'],
                    ['outbound', 'Tudo bem, Juliana. Respeitamos sua decisão e não vamos insistir. Se quiser retomar no futuro, estaremos à disposição.'],
                    ['inbound', 'Combinado, obrigada.'],
                ],
            ],
            [
                'lead' => ['name' => 'Larissa Mendes', 'phone' => '+5511911101011', 'source' => 'instagram', 'creative_id' => 'instagram-implantes-larissa', 'creative_url' => 'https://instagram.com/p/oralsin-acolhimento-demo', 'campaign_name' => 'Instagram | Implantes e Acolhimento'],
                'stage' => 'Novo Lead',
                'owner_email' => null,
                'received_today' => true,
                'minutes_ago' => 25,
                'is_repeat_lead' => false,
                'first_response_seconds' => null,
                'status' => 'active',
                'messages' => [
                    ['inbound', 'Oi, cheguei pelo Instagram. Tenho muita insegurança com dentista e queria saber se implante pode ser uma opção para mim.'],
                    ['outbound', 'Oi, Larissa! Obrigado por compartilhar isso. Só uma avaliação profissional pode indicar a opção adequada, mas podemos tornar esse primeiro contato bem tranquilo e explicar cada etapa.'],
                    ['inbound', 'Isso me deixa mais calma. Como faço para marcar?'],
                    ['outbound', 'Posso te ajudar por aqui. Você prefere durante a semana ou no sábado?'],
                    ['inbound', 'No sábado de manhã seria melhor.'],
                ],
            ],
            [
                'lead' => ['name' => 'Renato Alves', 'phone' => '+5511911101012', 'source' => 'google', 'creative_id' => 'google-avaliacao-renato', 'creative_url' => 'https://google.com/search?q=oral+sin+avaliacao', 'campaign_name' => 'Google | Avaliação Odontológica'],
                'stage' => 'Avaliação Realizada',
                'owner_email' => null,
                'hours_ago' => 16,
                'created_days_ago' => 4,
                'is_repeat_lead' => false,
                'first_response_seconds' => null,
                'status' => 'active',
                'messages' => [
                    ['inbound', 'Boa tarde. Fiz minha avaliação ontem e queria conversar melhor sobre as formas de pagamento que foram apresentadas.'],
                    ['outbound', 'Boa tarde, Renato! Claro, posso pedir para nossa consultora retomar as condições com você. Ficou alguma dúvida específica?'],
                    ['inbound', 'Quero entender qual opção se encaixa melhor no meu planejamento antes de decidir.'],
                    ['outbound', 'Perfeito. Qual horário é mais confortável para essa conversa?'],
                    ['inbound', 'Hoje depois das 18h consigo falar com calma.'],
                ],
            ],
            [
                'lead' => ['name' => 'Paula Azevedo', 'phone' => '+5511911101013', 'source' => 'instagram', 'creative_id' => 'instagram-protese-paula', 'creative_url' => 'https://instagram.com/p/oralsin-protese-demo', 'campaign_name' => 'Instagram | Prótese e Avaliação'],
                'stage' => 'Avaliação Agendada',
                'owner_email' => 'rafael@empresa.local',
                'received_today' => true,
                'minutes_ago' => 55,
                'is_repeat_lead' => false,
                'first_response_seconds' => 60,
                'status' => 'active',
                'messages' => [
                    ['inbound', 'Oi, vi um conteúdo da Oral Sin no Instagram sobre prótese. Perdi dois dentes e queria entender se existe alguma opção para o meu caso.'],
                    ['outbound', 'Oi, Paula! Seja bem-vinda 😊 Obrigada por contar o que está buscando. Para indicar uma opção com segurança, o dentista precisa fazer uma avaliação profissional. Posso fazer algumas perguntas para te ajudar com esse primeiro passo?'],
                    ['inbound', 'Pode sim. Tenho evitado sorrir nas fotos e também queria voltar a mastigar com mais tranquilidade.'],
                    ['outbound', 'Entendi, Paula. Então hoje sua prioridade envolve tanto a confiança para sorrir quanto a mastigação, certo? Você gostaria de começar essa avaliação logo ou ainda está pesquisando?'],
                    ['inbound', 'Isso mesmo. Quero começar logo porque tenho um evento de família no fim do ano.'],
                    ['outbound', 'Perfeito. Vamos direcionar você para uma avaliação, sem antecipar nenhuma indicação pelo WhatsApp. Qual período costuma ser melhor para vir à clínica?'],
                    ['inbound', 'Sábado de manhã é melhor para mim.'],
                    ['outbound', 'Temos sábado às 9h ou às 10h30. Qual desses horários funciona melhor?'],
                    ['inbound', 'Às 10h30 fica ótimo.'],
                    ['outbound', 'Combinado, Paula! Sua avaliação ficou agendada para sábado às 10h30. Vou enviar a confirmação com o endereço e, se surgir alguma dúvida antes, pode falar com a gente por aqui.'],
                ],
            ],
            [
                'lead' => ['name' => 'Eduardo Ramos', 'phone' => '+5511911101014', 'source' => 'google', 'creative_id' => 'google-tratamento-eduardo', 'creative_url' => 'https://google.com/search?q=oral+sin+tratamento', 'campaign_name' => 'Google | Tratamentos Odontológicos'],
                'stage' => 'Em Atendimento',
                'owner_email' => 'marina@empresa.local',
                'received_today' => true,
                'minutes_ago' => 360,
                'is_repeat_lead' => false,
                'first_response_seconds' => 720,
                'status' => 'active',
                'messages' => [
                    ['inbound', 'Boa tarde. Encontrei a clínica no Google e queria saber quanto custa um tratamento para melhorar meu sorriso.'],
                    ['outbound', 'Boa tarde! Os valores variam e temos formas de pagamento. Você pode agendar uma avaliação na clínica.'],
                    ['inbound', 'Eu queria ter pelo menos uma ideia antes. Meu orçamento está apertado e nem sei exatamente qual tratamento eu precisaria.'],
                    ['outbound', 'Só conseguimos falar de valores depois da avaliação, mas existem possibilidades de parcelamento.'],
                    ['inbound', 'Entendi... sem uma noção fica difícil para mim. Vou pensar.'],
                    ['outbound', 'Certo. Qualquer coisa, estamos à disposição.'],
                ],
            ],
        ];

        $now = now()->startOfMinute();
        $minutesElapsedToday = (int) $now->copy()->startOfDay()->diffInMinutes($now);

        foreach ($scenarios as $scenarioIndex => $scenario) {
            $leadData = $scenario['lead'];
            $startedAt = ($scenario['received_today'] ?? false)
                ? $now->copy()->subMinutes(min($scenario['minutes_ago'], $minutesElapsedToday))
                : $now->copy()->subHours($scenario['hours_ago']);
            $createdAt = ($scenario['received_today'] ?? false)
                ? $startedAt->copy()
                : $now->copy()->startOfDay()->subDays($scenario['created_days_ago'])->addHours(10);
            $owner = $scenario['owner_email'] === null
                ? null
                : $owners->get($scenario['owner_email']);

            if ($scenario['owner_email'] !== null && $owner === null) {
                throw new RuntimeException("Responsável demo não encontrado: {$scenario['owner_email']}");
            }

            $lastInboundAt = null;
            $lastOutboundAt = null;

            foreach ($scenario['messages'] as $messageIndex => [$direction]) {
                $messageAt = $startedAt->copy()->addMinutes($messageIndex * 7);

                if ($direction === 'inbound') {
                    $lastInboundAt = $messageAt;
                } else {
                    $lastOutboundAt = $messageAt;
                }
            }

            $lastMessageAt = $startedAt->copy()->addMinutes((count($scenario['messages']) - 1) * 7);

            $lead = Lead::query()->updateOrCreate(
                ['company_id' => $company->id, 'phone_e164' => $leadData['phone']],
                [
                    'owner_user_id' => $owner?->id,
                    'name' => $leadData['name'],
                    'source' => $leadData['source'],
                    'source_method' => 'auto',
                    'source_updated_at' => $startedAt,
                    'creative_id' => $leadData['creative_id'],
                    'creative_url' => $leadData['creative_url'],
                    'campaign_name' => $leadData['campaign_name'],
                    'is_repeat_lead' => $scenario['is_repeat_lead'],
                    'first_inbound_at' => $startedAt,
                    'last_inbound_at' => $lastInboundAt,
                    'last_outbound_at' => $lastOutboundAt,
                    'first_response_seconds' => $scenario['first_response_seconds'],
                    'metadata' => [
                        'demo' => true,
                        'fixture' => 'oral-sin',
                        'scenario' => $scenarioIndex + 1,
                    ],
                ]
            );
            $lead->forceFill(['created_at' => $createdAt])->saveQuietly();

            LeadSourceHistory::query()->updateOrCreate(
                [
                    'company_id' => $company->id,
                    'lead_id' => $lead->id,
                    'change_type' => 'auto',
                    'reason' => 'Demo bootstrap: origem inicial.',
                ],
                [
                    'previous_source' => null,
                    'new_source' => $leadData['source'],
                    'changed_by_user_id' => null,
                    'changed_at' => $createdAt,
                ]
            );

            $conversation = Conversation::query()->updateOrCreate(
                ['company_id' => $company->id, 'lead_id' => $lead->id],
                [
                    'owner_user_id' => $owner?->id,
                    'status' => $scenario['status'],
                    'started_at' => $startedAt,
                    'last_message_at' => $lastMessageAt,
                    'closed_at' => $scenario['status'] === 'closed'
                        ? $lastMessageAt
                        : null,
                ]
            );
            $conversation->forceFill(['created_at' => $createdAt])->saveQuietly();

            $lastMessage = null;
            $fixtureMessageIds = collect(array_keys($scenario['messages']))
                ->map(fn (int $messageIndex): string => sprintf('demo-ci-%d-%d', $scenarioIndex + 1, $messageIndex + 1))
                ->all();

            Message::query()
                ->where('company_id', $company->id)
                ->where('provider', 'fake')
                ->where('external_message_id', 'like', sprintf('demo-ci-%d-%%', $scenarioIndex + 1))
                ->whereNotIn('external_message_id', $fixtureMessageIds)
                ->delete();

            foreach ($scenario['messages'] as $messageIndex => [$direction, $body]) {
                $messageAt = $startedAt->copy()->addMinutes($messageIndex * 7);
                $lastMessage = Message::query()->updateOrCreate(
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
                        'sent_at' => $messageAt,
                        'raw_payload' => ['demo' => true, 'fixture' => 'oral-sin'],
                        'is_rescue' => false,
                        'metadata' => [
                            'classification' => $scenario['is_repeat_lead'] ? 'lead_recorrente' : 'lead_novo',
                            'raw_source' => $leadData['source'],
                            'fixture' => 'oral-sin',
                        ],
                    ]
                );
                $lastMessage->forceFill(['created_at' => $messageAt])->saveQuietly();
            }

            $column = KanbanColumn::query()
                ->where('company_id', $company->id)
                ->where('pipeline_id', $pipeline->id)
                ->where('name', $scenario['stage'])
                ->firstOrFail();

            LeadStageHistory::query()->updateOrCreate(
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

            if (isset($scenario['analysis'])) {
                $analysis = $scenario['analysis'];
                $recommendedColumn = KanbanColumn::query()
                    ->where('company_id', $company->id)
                    ->where('pipeline_id', $pipeline->id)
                    ->where('name', $analysis['recommended_stage'])
                    ->firstOrFail();
                $commercialData = array_merge([
                    'temperature' => match (true) {
                        $analysis['score'] >= 80 => 'Quente',
                        $analysis['score'] >= 55 => 'Morna',
                        default => 'Fria',
                    },
                ], $analysis['commercial_data']);

                $this->syncDemoAnalysis($conversation, [
                    'company_id' => $company->id,
                    'lead_id' => $lead->id,
                    'owner_user_id' => $owner?->id,
                    'score' => $analysis['score'],
                    'summary' => $analysis['summary'],
                    'intent' => $analysis['intent'],
                    'objections' => $analysis['objections'],
                    'positive_points' => $analysis['positive_points'],
                    'errors' => $analysis['errors'],
                    'improvement_suggestion' => $analysis['next_step'],
                    'commercial_data' => $commercialData,
                    'criteria_scores' => [
                        'acolhimento' => min(100, $analysis['score'] + 4),
                        'descoberta_da_necessidade' => max(0, $analysis['score'] - 3),
                        'clareza' => $analysis['score'],
                        'personalizacao' => max(0, $analysis['score'] - 2),
                        'tratamento_de_objecao' => max(0, $analysis['score'] - 5),
                        'direcionamento_para_avaliacao' => min(100, $analysis['score'] + 2),
                        'proximo_passo' => max(0, $analysis['score'] - 4),
                        'risco_de_perda' => 100 - $analysis['score'],
                    ],
                    'recommended_kanban_column_id' => $recommendedColumn->id,
                    'classification_reason' => $analysis['classification_reason'],
                    'confidence' => $analysis['confidence'],
                    'prompt_version' => 'oral-sin-demo-v1',
                    'model_provider' => 'fake',
                    'model_name' => 'demo-fixture',
                    'source_last_message_id' => $lastMessage?->id,
                    'transcript_hash' => hash('sha256', json_encode($scenario['messages'], JSON_UNESCAPED_UNICODE)),
                    'analyzed_at' => $conversation->last_message_at->copy()->addMinutes(2),
                ]);
            } else {
                ConversationQualityScore::query()
                    ->where('conversation_id', $conversation->id)
                    ->where('model_name', 'demo-fixture')
                    ->delete();
            }
        }

        return Conversation::query()
            ->where('company_id', $company->id)
            ->whereHas('lead', static fn ($query) => $query->where('metadata->demo', true))
            ->count();
    }

    /**
     * Atualiza apenas snapshots criados pelo próprio fixture. Análises reais são imutáveis.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function syncDemoAnalysis(Conversation $conversation, array $attributes): void
    {
        $demoScore = ConversationQualityScore::query()
            ->where('conversation_id', $conversation->id)
            ->where('analysis_version', 1)
            ->where('model_name', 'demo-fixture')
            ->first();

        if ($demoScore !== null) {
            $demoScore->forceFill($attributes)->saveQuietly();

            return;
        }

        ConversationQualityScore::query()->firstOrCreate(
            [
                'conversation_id' => $conversation->id,
                'analysis_version' => 1,
            ],
            $attributes
        );
    }
}
