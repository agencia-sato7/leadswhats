import { useEffect, useMemo, useState } from 'react';
import {
  createCampaignReport,
  getCampaignReport,
  getCampaignReportLeads,
  getCampaignReports,
  previewCampaignReport,
} from '../api';
import { Alert, Badge, Button, Card, EmptyState, ErrorState, FormGroup, Input, LoadingState, MetricCard, Section, Table } from '../components/ui';
import type { CampaignPreviewResponse, CampaignQualityRollup, CampaignReport, CampaignReportLead, CampaignVerdict } from '../types';

type Props = {
  token: string;
  tenantContext?: string;
  readOnly?: boolean;
  onOpenConversation: (conversationId: number) => void;
};

function isoDate(date: Date): string {
  const year = date.getFullYear();
  return `${year}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
}

function closedRange(days: number): { start: string; end: string } {
  const end = new Date();
  end.setDate(end.getDate() - 1);
  const start = new Date(end);
  start.setDate(start.getDate() - days + 1);
  return { start: isoDate(start), end: isoDate(end) };
}

function formatDate(date: string): string {
  return new Intl.DateTimeFormat('pt-BR', { timeZone: 'UTC' }).format(new Date(`${date}T12:00:00Z`));
}

function formatDateTime(value: string | null): string {
  if (!value) return '—';
  return new Date(value).toLocaleString('pt-BR');
}

function verdictLabel(verdict: CampaignVerdict | null): string {
  if (verdict === 'good') return 'Bom';
  if (verdict === 'poor') return 'Ruim';
  if (verdict === 'needs_improvement') return 'Precisa melhorar';
  return 'Sem dados';
}

function verdictVariant(verdict: CampaignVerdict | null): 'success' | 'warning' | 'danger' | 'neutral' {
  if (verdict === 'good') return 'success';
  if (verdict === 'poor') return 'danger';
  if (verdict === 'needs_improvement') return 'warning';
  return 'neutral';
}

function phaseLabel(phase: string): string {
  const labels: Record<string, string> = {
    queued: 'Na fila', collecting: 'Reunindo dados', analyzing_evidence: 'Analisando atendimentos',
    consolidating: 'Montando diagnóstico', completed: 'Concluído', failed: 'Falhou',
  };
  return labels[phase] ?? phase;
}

function QualityPanel({ title, data }: { title: string; data: CampaignQualityRollup }) {
  return (
    <Card className="lw-campaign-quality-card">
      <div className="lw-campaign-card-heading">
        <div><p className="lw-ci-eyebrow">{title}</p><h3>{data.score !== null ? `${data.score}/100` : 'Sem dados'}</h3></div>
        <Badge variant={verdictVariant(data.verdict)}>{verdictLabel(data.verdict)}</Badge>
      </div>
      <p>{data.summary || `${data.lead_count} lead(s) avaliados com o mesmo peso na nota.`}</p>
      {Object.keys(data.criteria_scores).length > 0 ? (
        <div className="lw-ci-criteria">
          {Object.entries(data.criteria_scores).map(([key, value]) => (
            <div key={key}><span>{key.replace('_', ' ')}</span><strong>{value}</strong><div className="lw-ci-progress"><span style={{ width: `${value}%` }} /></div></div>
          ))}
        </div>
      ) : null}
      <div className="lw-campaign-mini-grid">
        <div><strong>Pontos fortes</strong><ul>{data.strengths.map((item) => <li key={item}>{item}</li>)}{data.strengths.length === 0 ? <li>Nenhum destaque disponível.</li> : null}</ul></div>
        <div><strong>Melhorias</strong><ul>{data.improvements.map((item) => <li key={item}>{item}</li>)}{data.improvements.length === 0 ? <li>Nenhuma lacuna crítica consolidada.</li> : null}</ul></div>
      </div>
    </Card>
  );
}

export function CampaignIntelligencePage({ token, tenantContext, readOnly = false, onOpenConversation }: Props) {
  const initial = useMemo(() => closedRange(7), []);
  const [startDate, setStartDate] = useState(initial.start);
  const [endDate, setEndDate] = useState(initial.end);
  const [preview, setPreview] = useState<CampaignPreviewResponse['data'] | null>(null);
  const [reports, setReports] = useState<CampaignReport[]>([]);
  const [selectedReport, setSelectedReport] = useState<CampaignReport | null>(null);
  const [leads, setLeads] = useState<CampaignReportLead[]>([]);
  const [cohort, setCohort] = useState<'new' | 'rescued' | undefined>();
  const [previewLoading, setPreviewLoading] = useState(false);
  const [reportLoading, setReportLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function loadHistory() {
    const response = await getCampaignReports(token, 1, tenantContext);
    setReports(response.data);
  }

  async function loadPreview() {
    setPreviewLoading(true);
    setError(null);
    try {
      const response = await previewCampaignReport(token, startDate, endDate, tenantContext);
      setPreview(response.data);
      if (response.data.cached_report_id) await openReport(response.data.cached_report_id);
    } catch (err) {
      setPreview(null);
      setError(err instanceof Error ? err.message : 'Não foi possível preparar o período.');
    } finally {
      setPreviewLoading(false);
    }
  }

  async function openReport(reportId: number) {
    setReportLoading(true);
    setError(null);
    try {
      const response = await getCampaignReport(token, reportId, tenantContext);
      setSelectedReport(response.data);
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Não foi possível abrir o relatório.');
    } finally {
      setReportLoading(false);
    }
  }

  async function analyze() {
    setReportLoading(true);
    setError(null);
    try {
      if (preview?.cached_report_id) {
        await openReport(preview.cached_report_id);
        return;
      }
      const response = await createCampaignReport(token, startDate, endDate);
      setSelectedReport(response.data);
      await loadHistory();
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Não foi possível iniciar a análise.');
    } finally {
      setReportLoading(false);
    }
  }

  useEffect(() => { void loadHistory().catch((err) => setError(err instanceof Error ? err.message : 'Falha ao carregar histórico.')); }, [token, tenantContext]);

  useEffect(() => {
    if (!selectedReport || selectedReport.status === 'completed' || selectedReport.status === 'failed') return;
    const timer = window.setInterval(() => {
      void getCampaignReport(token, selectedReport.id, tenantContext).then((response) => {
        setSelectedReport(response.data);
        if (response.data.status === 'completed') void loadHistory();
      }).catch(() => undefined);
    }, 2500);
    return () => window.clearInterval(timer);
  }, [selectedReport?.id, selectedReport?.status, token, tenantContext]);

  useEffect(() => {
    if (!selectedReport || selectedReport.status !== 'completed') { setLeads([]); return; }
    void getCampaignReportLeads(token, selectedReport.id, { cohort }, tenantContext)
      .then((response) => setLeads(response.data))
      .catch((err) => setError(err instanceof Error ? err.message : 'Não foi possível carregar os leads.'));
  }, [selectedReport?.id, selectedReport?.status, cohort, token, tenantContext]);

  function choosePreset(days: number) {
    const range = closedRange(days);
    setStartDate(range.start);
    setEndDate(range.end);
    setPreview(null);
  }

  const result = selectedReport?.result;

  return (
    <Section className="lw-campaign-page">
      <div className="lw-ci-heading">
        <div><p className="lw-ci-eyebrow">Inteligência Comercial</p><h2>Inteligência da Campanha</h2><p>Compare volume e qualidade do atendimento em períodos fechados de até 90 dias.</p></div>
        {selectedReport ? <Badge variant={selectedReport.status === 'completed' ? 'success' : selectedReport.status === 'failed' ? 'danger' : 'info'}>{phaseLabel(selectedReport.progress_stage)}</Badge> : null}
      </div>

      <Card>
        <div className="lw-campaign-presets" aria-label="Períodos rápidos">
          {[7, 30, 90].map((days) => <Button key={days} type="button" variant="secondary" onClick={() => choosePreset(days)}>Últimos {days} dias</Button>)}
        </div>
        <div className="lw-campaign-filter-grid">
          <FormGroup label="Data inicial"><Input type="date" value={startDate} max={endDate} onChange={(event) => { setStartDate(event.target.value); setPreview(null); }} /></FormGroup>
          <FormGroup label="Data final"><Input type="date" value={endDate} max={closedRange(1).end} onChange={(event) => { setEndDate(event.target.value); setPreview(null); }} /></FormGroup>
          <Button type="button" onClick={() => void loadPreview()} disabled={previewLoading}>{previewLoading ? 'Preparando...' : 'Aplicar período'}</Button>
        </div>
      </Card>

      {error ? <ErrorState message={error} /> : null}
      {previewLoading ? <LoadingState message="Reunindo novos leads, resgates e período comparativo..." /> : null}

      {preview ? (
        <>
          <div className="lw-campaign-metrics">
            <MetricCard label="Novos no período" value={preview.metrics.new_leads} hint={`${preview.metrics.volume.absolute_change >= 0 ? '+' : ''}${preview.metrics.volume.absolute_change} versus ${preview.metrics.volume.previous_new_leads} no período anterior`} />
            <MetricCard label="Leads resgatados" value={preview.metrics.rescued_leads} hint={`${preview.metrics.rescue_attempts} tentativa(s) de resgate`} />
            <MetricCard label="Conversas consideradas" value={preview.metrics.conversations_considered} hint={`${preview.metrics.messages_considered} mensagens`} />
            <MetricCard label="Evidências reaproveitáveis" value={preview.reusable_evidences} hint={`de ${preview.estimated_evidences} estimadas`} />
          </div>
          <Alert variant={preview.cached_report_id ? 'success' : 'info'}>
            {preview.cached_report_id
              ? `Este período já foi analisado em ${formatDateTime(preview.cached_report_completed_at)}.`
              : `Comparação: ${formatDate(preview.range.comparison_start_date)} a ${formatDate(preview.range.comparison_end_date)}. Novas análises reutilizarão evidências compatíveis.`}
          </Alert>
          {!readOnly ? <Button type="button" onClick={() => void analyze()} disabled={!preview.has_analyzable_data || reportLoading}>{reportLoading ? 'Carregando...' : preview.cached_report_id ? 'Ver análise salva' : 'Analisar com IA'}</Button> : preview.cached_report_id ? <Button onClick={() => void openReport(preview.cached_report_id!)}>Ver análise salva</Button> : null}
          {!preview.has_analyzable_data ? <EmptyState title="Nenhum lead para analisar" description="O período não contém leads novos nem resgates com atividade." /> : null}
        </>
      ) : null}

      {selectedReport && selectedReport.status !== 'completed' && selectedReport.status !== 'failed' ? (
        <Card className="lw-campaign-processing" aria-live="polite">
          <div className="lw-campaign-card-heading"><div><p className="lw-ci-eyebrow">Processamento</p><h3>{phaseLabel(selectedReport.progress_stage)}</h3></div><strong>{selectedReport.progress_percentage}%</strong></div>
          <div className="lw-campaign-progress"><span style={{ width: `${selectedReport.progress_percentage}%` }} /></div>
          <p>{selectedReport.reused_evidence_count} evidência(s) reaproveitadas · {selectedReport.new_evidence_count} nova(s)</p>
        </Card>
      ) : null}
      {selectedReport?.status === 'failed' ? <Alert variant="danger">{selectedReport.failure_message || 'A análise não pôde ser concluída.'}</Alert> : null}

      {result ? (
        <div className="lw-campaign-result">
          <Card className="lw-campaign-hero">
            <div><p className="lw-ci-eyebrow">Síntese executiva</p><h3>{formatDate(selectedReport!.start_date)} a {formatDate(selectedReport!.end_date)}</h3><p>{result.executive_summary}</p></div>
            <Badge variant={verdictVariant(result.overall_verdict)}>{verdictLabel(result.overall_verdict)}</Badge>
          </Card>
          <div className="lw-campaign-axis-grid">
            <Card><p className="lw-ci-eyebrow">Volume</p><h3>{result.volume_assessment.current_new_leads} novos leads</h3><p>{result.volume_assessment.summary}</p><small>Período anterior: {result.volume_assessment.previous_new_leads} · Variação: {result.volume_assessment.percentage_change !== null ? `${result.volume_assessment.percentage_change}%` : 'sem base percentual'}</small></Card>
            <QualityPanel title="Qualidade do atendimento" data={result.service_quality} />
          </div>
          <div className="lw-campaign-axis-grid">
            <QualityPanel title="Novos leads" data={result.cohorts.new} />
            <QualityPanel title="Leads resgatados" data={result.cohorts.rescued} />
          </div>
          <Card><p className="lw-ci-eyebrow">Prioridades</p><ol className="lw-campaign-priorities">{result.priorities.map((priority) => <li key={priority}>{priority}</li>)}</ol></Card>
          <Card><div className="lw-campaign-card-heading"><div><p className="lw-ci-eyebrow">Equipe</p><h3>Desempenho por responsável</h3></div></div><Table><thead><tr><th>Responsável</th><th>Leads</th><th>Nota</th><th>Avaliação</th></tr></thead><tbody>{result.team.map((member) => <tr key={member.owner_user_id ?? 'none'}><td>{member.owner_name}</td><td>{member.lead_count}</td><td>{member.score ?? '—'}</td><td><Badge variant={verdictVariant(member.verdict)}>{verdictLabel(member.verdict)}</Badge></td></tr>)}</tbody></Table></Card>
          <Card>
            <div className="lw-campaign-card-heading"><div><p className="lw-ci-eyebrow">Evidências</p><h3>Leads que sustentam o diagnóstico</h3></div><div className="lw-campaign-presets"><Button variant={cohort === undefined ? 'primary' : 'secondary'} onClick={() => setCohort(undefined)}>Todos</Button><Button variant={cohort === 'new' ? 'primary' : 'secondary'} onClick={() => setCohort('new')}>Novos</Button><Button variant={cohort === 'rescued' ? 'primary' : 'secondary'} onClick={() => setCohort('rescued')}>Resgatados</Button></div></div>
            {leads.length > 0 ? <Table><thead><tr><th>Lead</th><th>Coorte</th><th>Responsável</th><th>Etapa</th><th>Nota</th><th /></tr></thead><tbody>{leads.map((lead) => <tr key={`${lead.cohort}-${lead.lead_id}`}><td>{lead.lead_name || `Lead ${lead.lead_id}`}</td><td>{lead.cohort === 'new' ? 'Novo' : 'Resgatado'}</td><td>{lead.owner_name || 'Sem responsável'}</td><td>{lead.stage_name || 'Sem etapa'}</td><td>{lead.average_score}</td><td>{lead.conversation_id ? <Button variant="secondary" onClick={() => onOpenConversation(lead.conversation_id!)}>Abrir conversa</Button> : '—'}</td></tr>)}</tbody></Table> : <EmptyState title="Nenhuma evidência neste filtro" />}
          </Card>
        </div>
      ) : null}

      <Card>
        <p className="lw-ci-eyebrow">Histórico</p><h3>Análises salvas</h3>
        {reports.length === 0 ? <EmptyState title="Nenhuma análise salva" description="Aplique um período fechado para iniciar." /> : <div className="lw-campaign-history">{reports.map((report) => <button key={report.id} type="button" className={`lw-campaign-history-item ${selectedReport?.id === report.id ? 'lw-campaign-history-item--active' : ''}`} onClick={() => void openReport(report.id)}><span><strong>{formatDate(report.start_date)} — {formatDate(report.end_date)}</strong><small>{report.requested_by || 'Usuário não disponível'} · {formatDateTime(report.created_at)}</small></span><Badge variant={report.status === 'completed' ? 'success' : report.status === 'failed' ? 'danger' : 'info'}>{phaseLabel(report.progress_stage)}</Badge></button>)}</div>}
      </Card>
    </Section>
  );
}
