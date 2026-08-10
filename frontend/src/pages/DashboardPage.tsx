import { Badge, Button, Section } from '../components/ui';
import type { DashboardSummaryResponse, OverviewResponse } from '../types';

type DashboardDestination = 'checklist' | 'kanban' | 'contacts' | 'intelligence';

type Props = {
  overview: OverviewResponse;
  dashboard: DashboardSummaryResponse;
  canViewIntelligence: boolean;
  onNavigate: (destination: DashboardDestination) => void;
};

type Priority = {
  key: string;
  count: number;
  label: string;
  severity: 'high' | 'medium' | 'info';
  destination?: DashboardDestination;
};

function formatSeconds(seconds: number | null): string {
  if (seconds === null) return 'Sem dados';
  const minutes = Math.floor(seconds / 60);
  const hours = Math.floor(minutes / 60);
  const remainingMinutes = minutes % 60;
  if (hours > 0) return `${hours}h ${remainingMinutes}min`;
  return `${minutes}min`;
}

function formatDashboardDate(value: string): string {
  const date = new Date(`${value}T12:00:00`);
  if (Number.isNaN(date.getTime())) return value;
  return date.toLocaleDateString('pt-BR', { day: '2-digit', month: 'long' });
}

function teamStatus(score: number | null): { label: string; variant: 'success' | 'info' | 'warning' | 'neutral' } {
  if (score === null) return { label: 'Acompanhar', variant: 'neutral' };
  if (score >= 80) return { label: 'Excelente', variant: 'success' };
  if (score >= 60) return { label: 'Bom', variant: 'info' };
  return { label: 'Atenção', variant: 'warning' };
}

function HealthMetric({ label, value, hint }: { label: string; value: string | number; hint: string }) {
  return (
    <article className="lw-overview-health-card">
      <span>{label}</span>
      <strong>{value}</strong>
      <small>{hint}</small>
    </article>
  );
}

export function DashboardPage({ overview, dashboard, canViewIntelligence, onNavigate }: Props) {
  const { metrics } = dashboard;
  const receivedLeads = metrics.new_leads_today + metrics.repeat_leads_today;
  const allPriorities: Priority[] = [
    {
      key: 'waiting-first-response',
      count: metrics.waiting_first_response_tasks,
      label: metrics.waiting_first_response_tasks === 1
        ? 'lead aguarda o primeiro atendimento'
        : 'leads aguardam o primeiro atendimento',
      severity: 'high',
      destination: 'checklist',
    },
    {
      key: 'waiting-return',
      count: metrics.vacuum_24h_open,
      label: metrics.vacuum_24h_open === 1
        ? 'lead aguarda retorno há mais de 24h'
        : 'leads aguardam retorno há mais de 24h',
      severity: 'high',
      destination: 'checklist',
    },
    {
      key: 'low-quality',
      count: metrics.low_quality_conversations,
      label: metrics.low_quality_conversations === 1
        ? 'conversa teve qualidade abaixo do esperado'
        : 'conversas tiveram qualidade abaixo do esperado',
      severity: 'medium',
      destination: canViewIntelligence ? 'intelligence' : undefined,
    },
    {
      key: 'stage-mismatch',
      count: metrics.ai_stage_mismatch_opportunities,
      label: metrics.ai_stage_mismatch_opportunities === 1
        ? 'oportunidade pode estar na etapa errada segundo a IA'
        : 'oportunidades podem estar na etapa errada segundo a IA',
      severity: 'info',
      destination: 'kanban',
    },
    {
      key: 'unassigned',
      count: metrics.unassigned_leads,
      label: metrics.unassigned_leads === 1
        ? 'lead ainda está sem responsável'
        : 'leads ainda estão sem responsável',
      severity: 'medium',
      destination: 'contacts',
    },
  ];
  const priorities = allPriorities.filter((priority) => priority.count > 0);
  const funnelTotal = dashboard.funnel.reduce((total, stage) => total + stage.count, 0);
  const largestStage = Math.max(...dashboard.funnel.map((stage) => stage.count), 1);

  return (
    <div className="lw-overview-page">
      <header className="lw-overview-toolbar">
        <div>
          <p className="lw-overview-context">{overview.company.name} · Hoje, {formatDashboardDate(dashboard.date)}</p>
          <h2>Sua operação em um olhar</h2>
          <p>Volume, atenção comercial, andamento do funil e desempenho da equipe.</p>
        </div>
        <div className="lw-overview-period" aria-label="Período da visão geral">
          <button type="button" className="is-active" aria-pressed="true">Hoje</button>
          {/* TODO: habilitar quando /dashboard/summary aceitar recorte temporal. */}
          <button type="button" disabled title="Disponível quando o histórico por período estiver implementado">Últimos 7 dias</button>
          <button type="button" disabled title="Disponível quando o histórico por período estiver implementado">Últimos 30 dias</button>
        </div>
      </header>

      <Section className="lw-overview-section">
        <div className="lw-overview-section-heading">
          <div>
            <p className="lw-overview-kicker">Agora</p>
            <h3>Saúde da operação</h3>
          </div>
          <span>Indicadores essenciais do dia</span>
        </div>
        <div className="lw-overview-health-grid">
          <HealthMetric label="Leads recebidos" value={receivedLeads} hint={`${metrics.repeat_leads_today} recorrentes`} />
          <HealthMetric label="Efetividade" value={`${metrics.effectiveness_percentage}%`} hint="Continuidade das conversas" />
          <HealthMetric label="Tempo médio de resposta" value={formatSeconds(metrics.avg_first_response_seconds)} hint="Primeiro retorno ao lead" />
          <HealthMetric
            label="Qualidade média"
            value={metrics.average_conversation_quality !== null ? `${metrics.average_conversation_quality}/100` : 'Sem análises'}
            hint="Última análise de cada conversa"
          />
          <HealthMetric label="Oportunidades ativas" value={metrics.active_conversations} hint="Conversas em andamento" />
        </div>
      </Section>

      <Section className="lw-overview-section lw-overview-attention">
        <div className="lw-overview-section-heading">
          <div>
            <p className="lw-overview-kicker">Prioridades</p>
            <h3>O que precisa da sua atenção</h3>
          </div>
          {priorities.length > 0 ? (
            <Button type="button" variant="secondary" onClick={() => onNavigate('checklist')}>Ver prioridades</Button>
          ) : null}
        </div>
        {priorities.length > 0 ? (
          <div className="lw-overview-priority-list">
            {priorities.map((priority) => {
              const content = (
                <>
                  <span className={`lw-overview-priority-count lw-overview-priority-count--${priority.severity}`}>{priority.count}</span>
                  <span>{priority.label}</span>
                  {priority.destination ? <small>Abrir área relacionada</small> : null}
                </>
              );

              return priority.destination ? (
                <button
                  type="button"
                  key={priority.key}
                  className="lw-overview-priority-row"
                  onClick={() => onNavigate(priority.destination as DashboardDestination)}
                >
                  {content}
                </button>
              ) : (
                <div key={priority.key} className="lw-overview-priority-row">{content}</div>
              );
            })}
          </div>
        ) : (
          <div className="lw-overview-clear-state">
            <strong>Nenhuma prioridade crítica agora</strong>
            <span>A operação não apresenta pendências relevantes nos dados disponíveis.</span>
          </div>
        )}
      </Section>

      <Section className="lw-overview-section">
        <div className="lw-overview-section-heading">
          <div>
            <p className="lw-overview-kicker">Funil atual</p>
            <h3>Funil comercial</h3>
          </div>
          <Button type="button" variant="secondary" onClick={() => onNavigate('kanban')}>Abrir Auto-CRM</Button>
        </div>
        {dashboard.funnel.length > 0 ? (
          <div className="lw-overview-funnel" aria-label="Distribuição das oportunidades por etapa">
            {dashboard.funnel.map((stage) => {
              const share = funnelTotal > 0 ? Math.round((stage.count / funnelTotal) * 100) : 0;
              const relativeWidth = stage.count > 0 ? Math.max(12, (stage.count / largestStage) * 100) : 0;
              return (
                <article key={`${stage.position}-${stage.stage_name}`} className="lw-overview-funnel-stage">
                  <div>
                    <span>{stage.stage_name}</span>
                    <strong>{stage.count}</strong>
                  </div>
                  <div className="lw-overview-funnel-track" aria-label={`${stage.stage_name}: ${stage.count} oportunidades`}>
                    <span style={{ width: `${relativeWidth}%` }} />
                  </div>
                  <small>{share}% das oportunidades</small>
                </article>
              );
            })}
          </div>
        ) : (
          <div className="lw-overview-clear-state">
            <strong>Funil ainda sem oportunidades</strong>
            <span>Os estágios serão preenchidos conforme os leads avançarem.</span>
          </div>
        )}
      </Section>

      <Section className="lw-overview-section">
        <div className="lw-overview-section-heading">
          <div>
            <p className="lw-overview-kicker">Acompanhamento gerencial</p>
            <h3>Desempenho da equipe</h3>
          </div>
          <span>Leitura sem ranking competitivo</span>
        </div>
        {dashboard.team_performance.length > 0 ? (
          <div className="lw-overview-team">
            <div className="lw-overview-team-header" aria-hidden="true">
              <span>Responsável</span>
              <span>Oportunidades</span>
              <span>Resposta média</span>
              <span>Qualidade</span>
              <span>Situação</span>
            </div>
            {dashboard.team_performance.map((member) => {
              const status = teamStatus(member.average_score);
              return (
                <article key={member.name} className="lw-overview-team-row">
                  <strong>{member.name}</strong>
                  <span data-label="Oportunidades">{member.active_opportunities} ativas</span>
                  <span data-label="Resposta média">{formatSeconds(member.avg_first_response_seconds)}</span>
                  <span data-label="Qualidade">{member.average_score !== null ? `${member.average_score}/100` : 'Sem análise'}</span>
                  <Badge variant={status.variant}>{status.label}</Badge>
                </article>
              );
            })}
          </div>
        ) : (
          <div className="lw-overview-clear-state">
            <strong>Sem responsáveis com oportunidades</strong>
            <span>A visão da equipe aparecerá quando houver leads atribuídos.</span>
          </div>
        )}
      </Section>
    </div>
  );
}
