import { useEffect, useMemo, useState } from 'react';
import {
  applyKanbanRecommendation,
  getLeadStageHistory,
  keepCurrentKanbanStage,
  moveLeadStage,
  updateKanbanColumn,
} from '../api';
import { Alert, Badge, Button, Card, EmptyState, FormGroup, LoadingState, Modal, Select, Textarea } from '../components/ui';
import type { KanbanCard, KanbanColumn, LeadStageHistoryItem, PipelineKanban, PipelineListItem } from '../types';

type AttentionFilter = 'all' | 'ai' | 'stale' | 'unassigned';

type Props = {
  token: string;
  pipelines: PipelineListItem[];
  selectedPipelineId: number | null;
  kanban: PipelineKanban | null;
  loading: boolean;
  error: string | null;
  canManage: boolean;
  onPipelineChange: (pipelineId: number) => void;
  onRefresh: () => Promise<void>;
  onOpenConversation: (conversationId: number) => void;
  onOpenIntelligence: (conversationId: number) => void;
};

const commercialLabels: Record<string, string> = {
  budget: 'Orçamento',
  orcamento: 'Orçamento',
  need: 'Necessidade',
  timeline: 'Prazo',
  decision_timeline: 'Horizonte de decisão',
  next_step: 'Próxima melhor ação',
  proximo_passo: 'Próxima melhor ação',
  decision_makers: 'Decisores',
  competitors: 'Concorrentes',
  temperature: 'Temperatura',
  temperatura: 'Temperatura',
  treatment_interest: 'Tratamento de interesse',
  main_need: 'Principal necessidade',
  urgency: 'Urgência percebida',
  objection: 'Objeção principal',
  availability: 'Disponibilidade',
  appointment_status: 'Situação da avaliação',
  appointment_date: 'Data da avaliação',
  payment_concern: 'Questão sobre pagamento',
};

function displayValue(value: unknown): string {
  if (Array.isArray(value)) return value.map(displayValue).filter(Boolean).join(', ');
  if (value && typeof value === 'object') return Object.values(value as Record<string, unknown>).map(displayValue).filter(Boolean).join(', ');
  if (value === null || value === undefined || value === '') return 'Não identificado';
  if (typeof value === 'boolean') return value ? 'Sim' : 'Não';
  if (typeof value === 'string') {
    const labels: Record<string, string> = {
      hot: 'Quente',
      warm: 'Morna',
      cold: 'Fria',
      scheduled: 'Agendada',
      pending: 'Pendente',
      completed: 'Realizada',
      confirmed: 'Confirmada',
      cancelled: 'Cancelada',
    };
    return labels[value.toLocaleLowerCase('pt-BR')] ?? value;
  }
  return String(value);
}

function sourceLabel(source: string | null): string {
  if (!source) return 'Origem não identificada';
  const labels: Record<string, string> = {
    instagram: 'Instagram',
    facebook: 'Facebook',
    google: 'Google',
    site: 'Site',
    indicacao: 'Indicação',
    desconhecido: 'Origem não identificada',
  };
  return labels[source.toLocaleLowerCase('pt-BR')] ?? source;
}

function commercialValue(card: KanbanCard, keys: string[]): string | null {
  const data = card.latest_analysis?.commercial_data ?? {};
  for (const key of keys) {
    if (data[key] !== null && data[key] !== undefined && data[key] !== '') return displayValue(data[key]);
  }
  return null;
}

function budgetNumber(card: KanbanCard): number | null {
  const raw = commercialValue(card, ['budget', 'orcamento']);
  if (!raw) return null;
  const lower = raw.toLocaleLowerCase('pt-BR');
  const multiplier = /\b(milhão|milhões|mi)\b/.test(lower) ? 1_000_000 : /\bmil\b/.test(lower) ? 1_000 : 1;
  const normalized = raw
    .replace(/[^0-9,.-]/g, '')
    .replace(/\.(?=\d{3}(?:\D|$))/g, '')
    .replace(',', '.');
  const parsed = Number(normalized);
  return Number.isFinite(parsed) && parsed > 0 ? parsed * multiplier : null;
}

function aggregateBudget(cards: KanbanCard[]): string | null {
  const values = cards.map(budgetNumber).filter((value): value is number => value !== null);
  if (values.length === 0) return null;
  return new Intl.NumberFormat('pt-BR', {
    style: 'currency',
    currency: 'BRL',
    notation: 'compact',
    maximumFractionDigits: 1,
  }).format(values.reduce((total, value) => total + value, 0));
}

function relativeTime(value: string | null): string {
  if (!value) return 'Sem interação';
  const timestamp = new Date(value).getTime();
  if (Number.isNaN(timestamp)) return 'Sem data';
  const minutes = Math.max(0, Math.floor((Date.now() - timestamp) / 60_000));
  if (minutes < 1) return 'Agora';
  if (minutes < 60) return `${minutes} min`;
  const hours = Math.floor(minutes / 60);
  if (hours < 24) return `${hours}h`;
  const days = Math.floor(hours / 24);
  return `${days}d`;
}

function isStale(card: KanbanCard): boolean {
  if (!card.last_message_at) return true;
  const timestamp = new Date(card.last_message_at).getTime();
  return Number.isNaN(timestamp) || Date.now() - timestamp > 24 * 60 * 60 * 1000;
}

function hasPendingRecommendation(card: KanbanCard, currentColumnId: number): boolean {
  const analysis = card.latest_analysis;
  return Boolean(
    analysis?.recommended_kanban_column_id
    && analysis.recommended_kanban_column_id !== currentColumnId
    && !analysis.recommendation_decision,
  );
}

function formatDateTime(value: string | null | undefined): string {
  if (!value) return 'Sem registro';
  const date = new Date(value);
  return Number.isNaN(date.getTime()) ? value : date.toLocaleString('pt-BR');
}

function confidenceLabel(confidence: number | null): string {
  if (confidence === null) return 'Confiança não informada';
  return `Confiança ${Math.round(confidence * 100)}%`;
}

function scoreVariant(score: number): 'success' | 'warning' | 'danger' {
  if (score >= 80) return 'success';
  if (score >= 60) return 'warning';
  return 'danger';
}

function historySourceLabel(source: string): string {
  if (source === 'ai_recommendation_accepted') return 'Recomendação da IA aceita';
  if (source === 'manual') return 'Movimentação manual';
  return 'Atualização de etapa';
}

export function AutoCrmPage({
  token,
  pipelines,
  selectedPipelineId,
  kanban,
  loading,
  error,
  canManage,
  onPipelineChange,
  onRefresh,
  onOpenConversation,
  onOpenIntelligence,
}: Props) {
  const [dragging, setDragging] = useState<{ leadId: number; columnId: number } | null>(null);
  const [dragOverColumnId, setDragOverColumnId] = useState<number | null>(null);
  const [selectedLeadId, setSelectedLeadId] = useState<number | null>(null);
  const [search, setSearch] = useState('');
  const [ownerFilter, setOwnerFilter] = useState('all');
  const [attentionFilter, setAttentionFilter] = useState<AttentionFilter>('all');
  const [history, setHistory] = useState<LeadStageHistoryItem[]>([]);
  const [historyVisible, setHistoryVisible] = useState(false);
  const [historyLoading, setHistoryLoading] = useState(false);
  const [actionLeadId, setActionLeadId] = useState<number | null>(null);
  const [actionError, setActionError] = useState<string | null>(null);
  const [actionMessage, setActionMessage] = useState<string | null>(null);
  const [configOpen, setConfigOpen] = useState(false);
  const [columnDrafts, setColumnDrafts] = useState<Record<number, { name: string; rule: string }>>({});
  const [savingColumnId, setSavingColumnId] = useState<number | null>(null);

  const allCards = useMemo(
    () => (kanban?.columns ?? []).flatMap((column) => column.cards.map((card) => ({ card, column }))),
    [kanban],
  );

  const owners = useMemo(
    () => Array.from(new Set(allCards.map(({ card }) => card.owner_name).filter((name): name is string => Boolean(name)))).sort((a, b) => a.localeCompare(b)),
    [allCards],
  );

  const indicators = useMemo(() => ({
    ai: allCards.filter(({ card, column }) => hasPendingRecommendation(card, column.id)).length,
    stale: allCards.filter(({ card }) => isStale(card)).length,
    unassigned: allCards.filter(({ card }) => !card.owner_name).length,
  }), [allCards]);

  const filteredColumns = useMemo(() => {
    const normalizedSearch = search.trim().toLocaleLowerCase('pt-BR');
    return (kanban?.columns ?? []).map((column) => ({
      ...column,
      cards: column.cards.filter((card) => {
        const matchesSearch = !normalizedSearch || [card.name, card.source, card.owner_name]
          .some((value) => value?.toLocaleLowerCase('pt-BR').includes(normalizedSearch));
        const matchesOwner = ownerFilter === 'all'
          || (ownerFilter === 'unassigned' ? !card.owner_name : card.owner_name === ownerFilter);
        const matchesAttention = attentionFilter === 'all'
          || (attentionFilter === 'ai' && hasPendingRecommendation(card, column.id))
          || (attentionFilter === 'stale' && isStale(card))
          || (attentionFilter === 'unassigned' && !card.owner_name);
        return matchesSearch && matchesOwner && matchesAttention;
      }),
    }));
  }, [attentionFilter, kanban, ownerFilter, search]);

  const visibleCards = filteredColumns.reduce((total, column) => total + column.cards.length, 0);
  const filtersActive = Boolean(search.trim() || ownerFilter !== 'all' || attentionFilter !== 'all');

  const selected = useMemo(() => {
    for (const column of kanban?.columns ?? []) {
      const card = column.cards.find((item) => item.lead_id === selectedLeadId);
      if (card) return { card, column };
    }
    return null;
  }, [kanban, selectedLeadId]);

  useEffect(() => {
    if (!configOpen || !kanban) return;
    setColumnDrafts(Object.fromEntries(kanban.columns.map((column) => [column.id, { name: column.name, rule: column.rule ?? '' }])));
  }, [configOpen, kanban]);

  function openCard(leadId: number) {
    setSelectedLeadId(leadId);
    setHistory([]);
    setHistoryVisible(false);
    setActionError(null);
    setActionMessage(null);
  }

  async function showHistory(leadId: number) {
    setHistoryVisible(true);
    if (history.length > 0 || historyLoading) return;
    setHistoryLoading(true);
    setActionError(null);
    try {
      setHistory(await getLeadStageHistory(token, leadId));
    } catch {
      setActionError('Não foi possível carregar o histórico de etapas.');
    } finally {
      setHistoryLoading(false);
    }
  }

  async function moveCard(targetColumnId: number) {
    if (!canManage || !dragging || dragging.columnId === targetColumnId) return;
    setActionLeadId(dragging.leadId);
    setActionError(null);
    setActionMessage(null);
    try {
      await moveLeadStage(token, dragging.leadId, targetColumnId, 'Movimentação manual no Auto-CRM');
      await onRefresh();
    } catch {
      setActionError('Não foi possível movimentar o lead.');
    } finally {
      setDragging(null);
      setDragOverColumnId(null);
      setActionLeadId(null);
    }
  }

  async function decideRecommendation(card: KanbanCard, decision: 'apply' | 'keep') {
    if (!card.latest_analysis) return;
    setActionLeadId(card.lead_id);
    setActionError(null);
    setActionMessage(null);
    try {
      if (decision === 'apply') {
        await applyKanbanRecommendation(token, card.lead_id, card.latest_analysis.id);
        setActionMessage('Recomendação aplicada. A oportunidade foi movida e o histórico foi atualizado.');
      } else {
        await keepCurrentKanbanStage(token, card.lead_id, card.latest_analysis.id);
        setActionMessage('Etapa atual mantida.');
      }
      await onRefresh();
      if (historyVisible) setHistory(await getLeadStageHistory(token, card.lead_id));
    } catch {
      setActionError(decision === 'apply' ? 'Não foi possível aplicar a recomendação.' : 'Não foi possível manter a etapa atual.');
    } finally {
      setActionLeadId(null);
    }
  }

  async function saveColumn(column: KanbanColumn) {
    const draft = columnDrafts[column.id];
    if (!draft?.name.trim()) return;
    setSavingColumnId(column.id);
    setActionError(null);
    try {
      await updateKanbanColumn(token, column.id, { name: draft.name.trim(), rule: draft.rule.trim() || null });
      await onRefresh();
    } catch {
      setActionError('Não foi possível salvar a configuração da etapa.');
    } finally {
      setSavingColumnId(null);
    }
  }

  return (
    <section className="lw-auto-crm" aria-label="Auto-CRM comercial">
      <header className="lw-auto-crm-header">
        <div className="lw-auto-crm-title-row">
          <div>
            <h2>Auto-CRM</h2>
            <span>{allCards.length} {allCards.length === 1 ? 'oportunidade' : 'oportunidades'}</span>
          </div>
          <div className="lw-auto-crm-header-actions">
            {canManage && kanban ? <Button variant="secondary" onClick={() => setConfigOpen(true)}>Configurar etapas</Button> : null}
          </div>
        </div>

        <div className="lw-auto-crm-attention" aria-label="Indicadores de atenção">
          <button type="button" className={attentionFilter === 'ai' ? 'is-active' : ''} onClick={() => setAttentionFilter(attentionFilter === 'ai' ? 'all' : 'ai')}>
            <strong>{indicators.ai}</strong><span>recomendações da IA</span>
          </button>
          <button type="button" className={attentionFilter === 'stale' ? 'is-active' : ''} onClick={() => setAttentionFilter(attentionFilter === 'stale' ? 'all' : 'stale')}>
            <strong>{indicators.stale}</strong><span>sem interação há 24h</span>
          </button>
          <button type="button" className={attentionFilter === 'unassigned' ? 'is-active' : ''} onClick={() => setAttentionFilter(attentionFilter === 'unassigned' ? 'all' : 'unassigned')}>
            <strong>{indicators.unassigned}</strong><span>sem responsável</span>
          </button>
        </div>

        <div className="lw-auto-crm-filters" aria-label="Filtros do Auto-CRM">
          <label className="lw-auto-crm-filter lw-auto-crm-filter--search">
            <span>Buscar</span>
            <input className="lw-input" value={search} onChange={(event) => setSearch(event.target.value)} placeholder="Nome, origem ou responsável" />
          </label>
          {pipelines.length > 1 ? (
            <label className="lw-auto-crm-filter">
              <span>Processo</span>
              <Select value={selectedPipelineId ?? ''} onChange={(event) => onPipelineChange(Number(event.target.value))}>
                {pipelines.map((pipeline) => <option key={pipeline.id} value={pipeline.id}>{pipeline.name}</option>)}
              </Select>
            </label>
          ) : null}
          <label className="lw-auto-crm-filter">
            <span>Responsável</span>
            <Select value={ownerFilter} onChange={(event) => setOwnerFilter(event.target.value)}>
              <option value="all">Todos</option>
              <option value="unassigned">Sem responsável</option>
              {owners.map((owner) => <option key={owner} value={owner}>{owner}</option>)}
            </Select>
          </label>
          <label className="lw-auto-crm-filter">
            <span>Atenção</span>
            <Select value={attentionFilter} onChange={(event) => setAttentionFilter(event.target.value as AttentionFilter)}>
              <option value="all">Todas</option>
              <option value="ai">Recomendação da IA</option>
              <option value="stale">Sem interação há 24h</option>
              <option value="unassigned">Sem responsável</option>
            </Select>
          </label>
          {filtersActive ? (
            <button type="button" className="lw-auto-crm-clear" onClick={() => { setSearch(''); setOwnerFilter('all'); setAttentionFilter('all'); }}>
              Limpar · {visibleCards} {visibleCards === 1 ? 'resultado' : 'resultados'}
            </button>
          ) : null}
        </div>
      </header>

      {!canManage ? <Alert variant="info">Seu perfil pode acompanhar o funil, mas decisões e movimentações são restritas à gestão.</Alert> : null}
      {actionError && !selected ? <Alert variant="danger">{actionError}</Alert> : null}
      {loading ? <LoadingState message="Atualizando oportunidades..." /> : null}
      {error ? <Alert variant="danger">{error}</Alert> : null}
      {!loading && !error && pipelines.length === 0 ? <EmptyState title="Nenhum processo comercial configurado." /> : null}

      {!loading && !error && kanban?.columns.length ? (
        <div className="lw-auto-crm-board">
          {filteredColumns.map((column) => {
            const columnBudget = aggregateBudget(column.cards);
            const originalCount = kanban.columns.find((item) => item.id === column.id)?.cards.length ?? 0;
            return (
              <div
                key={column.id}
                className={`lw-auto-crm-column ${dragOverColumnId === column.id ? 'lw-auto-crm-column--target' : ''}`}
                onDragOver={(event) => { if (canManage && dragging) { event.preventDefault(); setDragOverColumnId(column.id); } }}
                onDrop={(event) => { event.preventDefault(); void moveCard(column.id); }}
                onDragLeave={() => setDragOverColumnId(null)}
              >
                <header className="lw-auto-crm-column-header">
                  <div>
                    <h3>{column.name}</h3>
                    {columnBudget ? <span>{columnBudget} em oportunidades</span> : null}
                  </div>
                  <strong>{column.cards.length}{filtersActive && column.cards.length !== originalCount ? `/${originalCount}` : ''}</strong>
                </header>

                <div className="lw-auto-crm-card-list">
                  {column.cards.length === 0 ? <div className="lw-auto-crm-column-empty">Nenhuma oportunidade</div> : null}
                  {column.cards.map((card) => {
                    const analysis = card.latest_analysis;
                    const recommendationRelevant = hasPendingRecommendation(card, column.id);
                    const budget = commercialValue(card, ['budget', 'orcamento']);
                    const objection = analysis?.objections[0] ?? null;
                    const commercialSignal = objection || analysis?.intent || commercialValue(card, ['need']);

                    return (
                      <article
                        key={card.lead_id}
                        className={`lw-auto-crm-card ${actionLeadId === card.lead_id ? 'lw-auto-crm-card--busy' : ''}`}
                        draggable={canManage && actionLeadId !== card.lead_id}
                        onDragStart={() => setDragging({ leadId: card.lead_id, columnId: column.id })}
                        onDragEnd={() => { setDragging(null); setDragOverColumnId(null); }}
                        onClick={() => openCard(card.lead_id)}
                        onKeyDown={(event) => {
                          if (event.key === 'Enter' || event.key === ' ') {
                            event.preventDefault();
                            openCard(card.lead_id);
                          }
                        }}
                        role="button"
                        tabIndex={0}
                      >
                        <div className="lw-auto-crm-card-title">
                          <strong>{card.name || 'Lead sem nome'}</strong>
                          <span className={`lw-auto-crm-score ${analysis ? `lw-auto-crm-score--${scoreVariant(analysis.score)}` : ''}`}>
                            {analysis?.score ?? '—'}
                          </span>
                        </div>

                        <div className="lw-auto-crm-card-meta">
                          <span>{sourceLabel(card.source)}</span>
                          <span>{card.owner_name || 'Sem responsável'}</span>
                        </div>

                        {commercialSignal ? (
                          <p className="lw-auto-crm-card-signal">
                            <span>{objection ? 'Objeção' : 'Sinal comercial'}</span>
                            {commercialSignal}
                          </p>
                        ) : null}

                        <div className="lw-auto-crm-card-footer">
                          {budget ? <strong>{budget}</strong> : <span />}
                          <span className={isStale(card) ? 'is-stale' : ''}>{relativeTime(card.last_message_at)}</span>
                        </div>

                        {recommendationRelevant && analysis ? (
                          <div className="lw-auto-crm-recommendation">
                            <span>IA recomenda → <strong>{analysis.recommended_kanban_column_name || 'Nova etapa'}</strong></span>
                            <small>{confidenceLabel(analysis.confidence)}</small>
                          </div>
                        ) : null}
                      </article>
                    );
                  })}
                </div>
              </div>
            );
          })}
        </div>
      ) : null}

      <Modal open={Boolean(selected)} onClose={() => setSelectedLeadId(null)} title={selected?.card.name || 'Detalhe da oportunidade'}>
        {selected ? (() => {
          const analysis = selected.card.latest_analysis;
          const budget = commercialValue(selected.card, ['budget', 'orcamento']);
          const temperature = commercialValue(selected.card, ['temperature', 'temperatura']);
          const nextAction = commercialValue(selected.card, ['next_step', 'proximo_passo']);
          const recommendationRelevant = hasPendingRecommendation(selected.card, selected.column.id);
          const commercialEntries = Object.entries(analysis?.commercial_data ?? {})
            .filter(([key]) => !['next_step', 'proximo_passo'].includes(key));

          return (
            <div className="lw-auto-crm-detail">
              {actionError ? <Alert variant="danger">{actionError}</Alert> : null}
              {actionMessage ? <div aria-live="polite"><Alert variant="success">{actionMessage}</Alert></div> : null}

              <section className="lw-auto-crm-opportunity-summary">
                <div>
                  <span className="lw-auto-crm-detail-label">Resumo da oportunidade</span>
                  <p>{analysis?.summary || 'Esta oportunidade ainda não possui análise de conversa.'}</p>
                  <div className="lw-auto-crm-detail-meta">
                    <span>{sourceLabel(selected.card.source)}</span>
                    <span>{selected.card.owner_name || 'Sem responsável'}</span>
                    <span>Última interação {relativeTime(selected.card.last_message_at)}</span>
                  </div>
                </div>
                <div className="lw-auto-crm-detail-score">
                  <strong>{analysis?.score ?? '—'}</strong><span>pontuação</span>
                </div>
              </section>

              <div className="lw-auto-crm-detail-columns">
                <div className="lw-auto-crm-detail-main">
                  <section className="lw-auto-crm-detail-section">
                    <div className="lw-auto-crm-detail-section-heading">
                      <span className="lw-auto-crm-detail-label">Leitura comercial</span>
                      {analysis ? <Badge variant={scoreVariant(analysis.score)}>{temperature || analysis.intent || 'Intenção não identificada'}</Badge> : null}
                    </div>
                    <div className="lw-auto-crm-focus-grid">
                      <div><span>Intenção</span><strong>{analysis?.intent || 'Não identificada'}</strong></div>
                      <div><span>Objeção principal</span><strong>{analysis?.objections[0] || 'Nenhuma objeção explícita'}</strong></div>
                      <div className="lw-auto-crm-next-action"><span>Próxima melhor ação</span><strong>{nextAction || 'Ainda não definida na conversa'}</strong></div>
                    </div>
                  </section>

                  <section className="lw-auto-crm-detail-section">
                    <span className="lw-auto-crm-detail-label">Dados comerciais</span>
                    {commercialEntries.length ? (
                      <dl className="lw-auto-crm-commercial-grid">
                        {commercialEntries.map(([key, value]) => (
                          <div key={key}><dt>{commercialLabels[key] ?? 'Informação comercial'}</dt><dd>{displayValue(value)}</dd></div>
                        ))}
                      </dl>
                    ) : <p className="lw-auto-crm-empty-copy">Nenhum dado comercial estruturado identificado.</p>}
                    {budget ? <div className="lw-auto-crm-budget"><span>Orçamento identificado</span><strong>{budget}</strong></div> : null}
                  </section>
                </div>

                <aside className="lw-auto-crm-detail-side">
                  <section className="lw-auto-crm-stage-card">
                    <div><span>Etapa atual</span><strong>{selected.column.name}</strong></div>
                    <div><span>Recomendação da IA</span><strong>{analysis?.recommended_kanban_column_name || 'Manter etapa atual'}</strong></div>
                    <p>{analysis?.classification_reason || 'Sem motivo de recomendação disponível.'}</p>
                    {analysis?.recommended_kanban_column_id ? <small>{confidenceLabel(analysis.confidence)}</small> : null}
                    {recommendationRelevant && canManage ? (
                      <div className="lw-auto-crm-detail-actions">
                        <Button onClick={() => void decideRecommendation(selected.card, 'apply')} disabled={actionLeadId === selected.card.lead_id}>
                          {actionLeadId === selected.card.lead_id ? 'Aplicando...' : 'Aplicar recomendação'}
                        </Button>
                        <Button variant="secondary" onClick={() => void decideRecommendation(selected.card, 'keep')} disabled={actionLeadId === selected.card.lead_id}>
                          Manter etapa atual
                        </Button>
                      </div>
                    ) : null}
                    {analysis?.recommendation_decision === 'kept_current' ? <Badge variant="neutral">Etapa atual mantida pela gestão</Badge> : null}
                  </section>
                  <small className="lw-auto-crm-analysis-date">
                    {analysis ? `Análise atualizada em ${formatDateTime(analysis.analyzed_at)}` : 'Oportunidade ainda não analisada'}
                  </small>
                </aside>
              </div>

              <nav className="lw-auto-crm-detail-links" aria-label="Acessos da oportunidade">
                {analysis ? <Button variant="secondary" onClick={() => onOpenConversation(analysis.conversation_id)}>Ver conversa</Button> : null}
                {analysis ? <Button variant="secondary" onClick={() => onOpenIntelligence(analysis.conversation_id)}>Ver inteligência completa</Button> : null}
                <Button variant="secondary" onClick={() => { if (historyVisible) setHistoryVisible(false); else void showHistory(selected.card.lead_id); }}>
                  {historyVisible ? 'Ocultar histórico' : 'Ver histórico'}
                </Button>
              </nav>

              {historyVisible ? (
                <section className="lw-auto-crm-history-section">
                  <div className="lw-auto-crm-detail-section-heading">
                    <span className="lw-auto-crm-detail-label">Histórico de etapas</span>
                    <button type="button" className="lw-auto-crm-close-section" onClick={() => setHistoryVisible(false)}>Ocultar</button>
                  </div>
                  {historyLoading ? <LoadingState message="Carregando histórico..." /> : null}
                  {!historyLoading && history.length === 0 ? <p className="lw-auto-crm-empty-copy">Nenhuma movimentação registrada.</p> : null}
                  <div className="lw-auto-crm-history">
                    {history.map((item) => (
                      <div key={item.id}>
                        <strong>{item.from_column_name || 'Entrada'} → {item.to_column_name || 'Etapa'}</strong>
                        <span>{historySourceLabel(item.move_source)} · {formatDateTime(item.moved_at)}</span>
                        {item.reason ? <small>{item.reason}</small> : null}
                      </div>
                    ))}
                  </div>
                </section>
              ) : null}
            </div>
          );
        })() : null}
      </Modal>

      <Modal open={configOpen} onClose={() => setConfigOpen(false)} title="Configuração das etapas">
        {actionError ? <Alert variant="danger">{actionError}</Alert> : null}
        <p className="lw-text-sm-soft">As orientações abaixo apoiam a classificação da IA e ficam visíveis somente nesta configuração.</p>
        <div className="lw-auto-crm-column-config">
          {(kanban?.columns ?? []).map((column) => {
            const draft = columnDrafts[column.id] ?? { name: column.name, rule: column.rule ?? '' };
            return (
              <Card key={column.id}>
                <FormGroup label="Nome da etapa">
                  <input className="lw-input" value={draft.name} onChange={(event) => setColumnDrafts((current) => ({ ...current, [column.id]: { ...draft, name: event.target.value } }))} />
                </FormGroup>
                <FormGroup label="Critério comercial para a IA" hint="Descreva os sinais necessários para recomendar esta etapa.">
                  <Textarea rows={4} value={draft.rule} onChange={(event) => setColumnDrafts((current) => ({ ...current, [column.id]: { ...draft, rule: event.target.value } }))} />
                </FormGroup>
                <Button onClick={() => void saveColumn(column)} disabled={savingColumnId === column.id}>{savingColumnId === column.id ? 'Salvando...' : 'Salvar etapa'}</Button>
              </Card>
            );
          })}
        </div>
      </Modal>
    </section>
  );
}
