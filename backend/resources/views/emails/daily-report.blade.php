<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <title>Relatório diário</title>
</head>
<body style="margin:0;padding:24px;background:#f4f5f7;font-family:Arial,Helvetica,sans-serif;color:#1f2933;">
<div style="max-width:720px;margin:0 auto;background:#ffffff;border-radius:12px;padding:32px;">
    <h1 style="margin:0 0 4px;font-size:22px;">Relatório diário de performance</h1>
    <p style="margin:0 0 24px;color:#52606d;font-size:14px;">
        <strong>{{ $companyName }}</strong> · {{ $reportDate }}
    </p>

    @php
        $verdictLabels = [
            'good' => ['Bom', '#0f7b3a', '#e6f4ea'],
            'needs_improvement' => ['Atenção', '#a15c00', '#fff4e5'],
            'poor' => ['Crítico', '#a8071a', '#fdecec'],
        ];
        $verdict = $aiReport['overall_verdict'] ?? null;
        [$verdictLabel, $verdictColor, $verdictBackground] = $verdictLabels[$verdict] ?? ['Indefinido', '#52606d', '#eef1f5'];
    @endphp

    <p style="display:inline-block;margin:0 0 20px;padding:6px 14px;border-radius:999px;font-size:13px;font-weight:bold;color:{{ $verdictColor }};background:{{ $verdictBackground }};">
        Status do dia: {{ $verdictLabel }}
    </p>

    <h2 style="font-size:16px;margin:0 0 8px;">Resumo executivo</h2>
    <p style="margin:0 0 16px;font-size:14px;line-height:1.6;">
        {{ $aiReport['executive_summary'] ?? 'Resumo não disponível.' }}
    </p>

    @if (!empty($aiReport['volume_summary']))
        <p style="margin:0 0 8px;font-size:14px;line-height:1.6;"><strong>Volume:</strong> {{ $aiReport['volume_summary'] }}</p>
    @endif

    @if (!empty($aiReport['quality_summary']))
        <p style="margin:0 0 24px;font-size:14px;line-height:1.6;"><strong>Qualidade:</strong> {{ $aiReport['quality_summary'] }}</p>
    @endif

    <h2 style="font-size:16px;margin:0 0 8px;">Métricas do CRM</h2>
    <table style="width:100%;border-collapse:collapse;font-size:13px;margin-bottom:24px;">
        @foreach ([
            'Novos leads hoje' => $metrics['new_leads_today'] ?? null,
            'Leads repetidos hoje' => $metrics['repeat_leads_today'] ?? null,
            'Resgates hoje' => $metrics['rescues_today'] ?? null,
            'Conversas ativas' => $metrics['active_conversations'] ?? null,
            'Tempo médio de 1ª resposta (s)' => $metrics['avg_first_response_seconds'] ?? null,
            'Conversas ganhas hoje' => $metrics['successful_conversations_today'] ?? null,
            'Conversas perdidas hoje' => $metrics['lost_conversations_today'] ?? null,
            'Efetividade (%)' => $metrics['effectiveness_percentage'] ?? null,
            'Leads sem resposta há 24h' => $metrics['vacuum_24h_open'] ?? null,
            'Tarefas abertas' => $metrics['open_tasks'] ?? null,
            'Leads sem responsável' => $metrics['unassigned_leads'] ?? null,
        ] as $label => $value)
            <tr style="border-bottom:1px solid #e4e7eb;">
                <td style="padding:8px 0;color:#52606d;">{{ $label }}</td>
                <td style="padding:8px 0;text-align:right;font-weight:bold;">{{ $value ?? '—' }}</td>
            </tr>
        @endforeach
    </table>

    <h2 style="font-size:16px;margin:0 0 8px;">Pontuações da IA nas conversas</h2>
    @php
        $criteriaAverages = $quality['criteria_averages'] ?? [];
        $criteriaLabels = [
            'acolhimento' => 'Acolhimento',
            'descoberta_da_necessidade' => 'Descoberta da necessidade',
            'clareza' => 'Clareza',
            'personalizacao' => 'Personalização',
            'tratamento_de_objecao' => 'Tratamento de objeção',
            'direcionamento_para_avaliacao' => 'Direcionamento para avaliação',
            'proximo_passo' => 'Próximo passo',
            'risco_de_perda' => 'Risco de perda',
            'discovery' => 'Descoberta (discovery)',
            'clarity' => 'Clareza (clarity)',
            'empathy' => 'Empatia',
            'objection_handling' => 'Tratamento de objeções',
        ];
    @endphp

    @if (($quality['analyzed_conversations'] ?? 0) > 0)
        <table style="width:100%;border-collapse:collapse;font-size:13px;margin-bottom:24px;">
            <tr style="border-bottom:1px solid #e4e7eb;">
                <td style="padding:8px 0;color:#52606d;">Conversas avaliadas</td>
                <td style="padding:8px 0;text-align:right;font-weight:bold;">{{ $quality['analyzed_conversations'] }}</td>
            </tr>
            <tr style="border-bottom:1px solid #e4e7eb;">
                <td style="padding:8px 0;color:#52606d;">Nota média geral</td>
                <td style="padding:8px 0;text-align:right;font-weight:bold;">{{ $quality['average_score'] ?? '—' }}</td>
            </tr>
            @foreach ($criteriaAverages as $criterion => $average)
                <tr style="border-bottom:1px solid #e4e7eb;">
                    <td style="padding:8px 0;color:#52606d;">{{ $criteriaLabels[$criterion] ?? ucfirst(str_replace('_', ' ', $criterion)) }}</td>
                    <td style="padding:8px 0;text-align:right;font-weight:bold;">{{ $average ?? '—' }}</td>
                </tr>
            @endforeach
            <tr>
                <td style="padding:8px 0;color:#52606d;">Conversas abaixo de 60</td>
                <td style="padding:8px 0;text-align:right;font-weight:bold;">{{ $quality['low_quality_conversations'] ?? 0 }}</td>
            </tr>
        </table>
    @else
        <p style="margin:0 0 24px;font-size:14px;color:#52606d;">
            Nenhuma conversa foi avaliada pela IA nesta data, então não há pontuações para exibir.
        </p>
    @endif

    @if (!empty($aiReport['opportunities']))
        <h2 style="font-size:16px;margin:0 0 8px;">Oportunidades de melhoria</h2>
        <ul style="margin:0 0 24px;padding-left:20px;font-size:14px;line-height:1.6;">
            @foreach ($aiReport['opportunities'] as $opportunity)
                <li>{{ $opportunity }}</li>
            @endforeach
        </ul>
    @endif

    @if (!empty($aiReport['priorities']))
        <h2 style="font-size:16px;margin:0 0 8px;">Prioridades para amanhã</h2>
        <ul style="margin:0 0 8px;padding-left:20px;font-size:14px;line-height:1.6;">
            @foreach ($aiReport['priorities'] as $priority)
                <li>{{ $priority }}</li>
            @endforeach
        </ul>
    @endif

    <p style="margin:24px 0 0;color:#7b8794;font-size:12px;line-height:1.6;">
        Relatório gerado automaticamente pela IA do LEADSWHATS com base nas métricas do CRM e nas pontuações de qualidade das conversas.
    </p>
</div>
</body>
</html>

