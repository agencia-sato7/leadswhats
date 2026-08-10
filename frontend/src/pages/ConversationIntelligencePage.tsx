import { useEffect, useMemo, useState } from 'react';
import {
  analyzeConversation,
  getConversationIntelligenceDetail,
  getConversationIntelligenceList,
  getConversationIntelligenceSummary,
} from '../api';
import { Alert, Badge, Button, Card, EmptyState, ErrorState, FormGroup, Input, LoadingState, MetricCard, Section, Select } from '../components/ui';
import type {
  ConversationIntelligenceDetail,
  ConversationIntelligenceListItem,
  ConversationIntelligenceSummaryResponse,
  ConversationQualityAnalysis,
} from '../types';

type Props = {
  token: string;
  initialConversationId?: number | null;
};

function formatDateTime(value: string | null | undefined): string {
  if (!value) return 'Sem registro';
  const date = new Date(value);
  return Number.isNaN(date.getTime()) ? value : date.toLocaleString('pt-BR');
}

const commercialLabels: Record<string, string> = {
  need: 'Necessidade identificada',
  budget: 'Orçamento',
  timeline: 'Prazo de decisão',
  decision_makers: 'Pessoas envolvidas na decisão',
  next_step: 'Próximo passo combinado',
  competitors: 'Concorrentes mencionados',
  other_facts: 'Outros sinais comerciais',
  temperature: 'Temperatura da oportunidade',
  decision_timeline: 'Horizonte de decisão',
  budget_identified: 'Orçamento mapeado',
  competitor_mentioned: 'Concorrência mencionada',
  next_step_defined: 'Próximo passo definido',
  timeline_identified: 'Prazo mapeado',
  decision_maker_identified: 'Decisor mapeado',
  decision_status: 'Situação da decisão',
  dependency: 'Dependência para avançar',
  treatment_interest: 'Tratamento de interesse',
  main_need: 'Principal necessidade',
  urgency: 'Urgência percebida',
  objection: 'Objeção principal',
  availability: 'Disponibilidade',
  appointment_status: 'Situação da avaliação',
  appointment_date: 'Data da avaliação',
  payment_concern: 'Questão sobre pagamento',
};

const criteriaLabels: Record<string, string> = {
  discovery: 'Descoberta da necessidade',
  clarity: 'Clareza comercial',
  empathy: 'Escuta e empatia',
  objection_handling: 'Tratamento de objeções',
  acolhimento: 'Acolhimento',
  descoberta_da_necessidade: 'Descoberta da necessidade',
  personalizacao: 'Personalização',
  tratamento_de_objecao: 'Tratamento de objeções',
  direcionamento_para_avaliacao: 'Direcionamento para avaliação',
  proximo_passo: 'Definição do próximo passo',
  risco_de_perda: 'Risco de perda do lead',
};

function commercialLabel(key: string): string {
  return commercialLabels[key] ?? 'Informação comercial';
}

function criteriaLabel(key: string): string {
  return criteriaLabels[key] ?? 'Qualidade comercial';
}

function formatCommercialValue(value: unknown): string {
  if (typeof value === 'boolean') return value ? 'Sim' : 'Não';
  if (typeof value === 'string') {
    const translatedValues: Record<string, string> = {
      hot: 'Alta',
      warm: 'Média',
      cold: 'Baixa',
      confirmed: 'Confirmada',
      onboarding: 'Início da implantação',
      scheduled: 'Agendada',
      pending: 'Pendente',
      completed: 'Realizada',
      cancelled: 'Cancelada',
    };
    return translatedValues[value.toLowerCase()] ?? value;
  }
  if (Array.isArray(value)) return value.length > 0 ? value.map(formatCommercialValue).join(', ') : 'Não identificado';
  if (value && typeof value === 'object') {
    const nestedValues = Object.values(value)
      .map(formatCommercialValue)
      .filter((item) => item !== 'Não identificado');
    return nestedValues.length > 0 ? nestedValues.join(' · ') : 'Não identificado';
  }
  return String(value ?? 'Não identificado');
}

function sourceLabel(source: string | null): string {
  if (!source) return 'Não identificada';
  const labels: Record<string, string> = {
    instagram: 'Instagram',
    facebook: 'Facebook',
    google: 'Google',
    site: 'Site',
    indicacao: 'Indicação',
    desconhecido: 'Não identificada',
  };
  return labels[source.toLocaleLowerCase('pt-BR')] ?? source;
}

function scoreBadge(score: number): 'success' | 'warning' | 'danger' {
  if (score >= 80) return 'success';
  if (score >= 60) return 'warning';
  return 'danger';
}

function scoreLabel(score: number): string {
  if (score >= 80) return 'Conversa de alta qualidade';
  if (score >= 60) return 'Conversa com potencial';
  return 'Conversa que exige atenção';
}

function confidenceLabel(confidence: number): string {
  if (confidence >= 0.8) return 'Alta confiança';
  if (confidence >= 0.6) return 'Confiança moderada';
  return 'Baixa confiança';
}

function confidenceBadge(confidence: number): 'success' | 'info' | 'warning' {
  if (confidence >= 0.8) return 'success';
  if (confidence >= 0.6) return 'info';
  return 'warning';
}

function criterionColor(value: number): string {
  if (value >= 80) return 'var(--lw-color-success)';
  if (value >= 60) return 'var(--lw-color-info)';
  return 'var(--lw-color-warning)';
}

function AnalysisList({ title, items, emptyText }: { title: string; items: string[]; emptyText: string }) {
  return (
    <Card className="lw-ci-insight-card">
      <h4>{title}</h4>
      {items.length > 0 ? (
        <ul>{items.map((item) => <li key={item}>{item}</li>)}</ul>
      ) : <p className="lw-text-sm-soft">{emptyText}</p>}
    </Card>
  );
}

function AnalysisPanel({ analysis, currentStage }: { analysis: ConversationQualityAnalysis; currentStage: string | null }) {
  const commercialEntries = Object.entries(analysis.commercial_data);
  const criteriaEntries = Object.entries(analysis.criteria_scores);
  const mainObjection = analysis.objections[0] ?? 'Nenhuma objeção explícita identificada.';
  const nextBestAction = analysis.improvement_suggestion || 'Manter o acompanhamento e confirmar o próximo compromisso com o lead.';
  const recommendedStage = analysis.recommended_kanban_column_name || 'Manter na etapa atual';

  return (
    <div className="lw-ci-analysis" aria-label={`Análise versão ${analysis.analysis_version}`}>
      <Card className="lw-ci-analysis-hero">
        <div
          className={`lw-ci-score lw-ci-score--${scoreBadge(analysis.score)}`}
          style={{ width: 'clamp(112px, 14vw, 148px)', minWidth: '112px', height: 'auto', aspectRatio: '1' }}
          aria-label={`Pontuação da conversa: ${analysis.score} de 100`}
        >
          <strong style={{ fontSize: 'clamp(2.75rem, 6vw, 4rem)', letterSpacing: '-0.06em' }}>{analysis.score}</strong>
          <span>de 100 pontos</span>
        </div>
        <div style={{ minWidth: 0, flex: 1 }}>
          <div className="lw-flex-wrap-gap">
            <Badge variant={scoreBadge(analysis.score)}>{scoreLabel(analysis.score)}</Badge>
            {analysis.confidence !== null ? (
              <Badge variant={confidenceBadge(analysis.confidence)}>
                {confidenceLabel(analysis.confidence)} · {Math.round(analysis.confidence * 100)}%
              </Badge>
            ) : null}
          </div>
          <p className="lw-ci-eyebrow" style={{ marginTop: 'var(--lw-space-4)' }}>Resumo executivo</p>
          <p className="lw-ci-summary">{analysis.summary}</p>
          <small className="lw-text-xs-muted">Leitura atualizada em {formatDateTime(analysis.analyzed_at)}</small>
        </div>
      </Card>

      <div className="lw-ci-insights-grid">
        <Card className="lw-ci-insight-card">
          <p className="lw-ci-eyebrow">Intenção comercial</p>
          <h4>{analysis.intent || 'Intenção ainda não identificada'}</h4>
          <p className="lw-text-sm-soft">O que o lead demonstra querer neste momento.</p>
        </Card>
        <Card className="lw-ci-insight-card">
          <p className="lw-ci-eyebrow">Objeção principal</p>
          <h4>{mainObjection}</h4>
          <p className="lw-text-sm-soft">Principal barreira percebida para o avanço.</p>
        </Card>
        <Card className="lw-ci-insight-card" style={{ borderColor: 'var(--lw-color-info)', background: 'var(--lw-color-info-bg)' }}>
          <p className="lw-ci-eyebrow">Próxima melhor ação</p>
          <h4>{nextBestAction}</h4>
          <p className="lw-text-sm-soft">Ação sugerida para aumentar a chance de avanço.</p>
        </Card>
      </div>

      <div className="lw-ci-data-grid" aria-label="Direcionamento de etapa">
        <Card>
          <p className="lw-ci-eyebrow">Etapa atual</p>
          <h4>{currentStage || 'Etapa não informada'}</h4>
          <p className="lw-text-sm-soft">Posição atual da oportunidade no processo comercial.</p>
        </Card>
        <Card className="lw-ci-recommendation">
          <div>
            <p className="lw-ci-eyebrow">Etapa recomendada pela IA</p>
            <h4>{recommendedStage}</h4>
            <small className="lw-text-xs-muted">Motivo da recomendação</small>
            <p>{analysis.classification_reason || 'A conversa ainda não oferece sinais suficientes para recomendar uma mudança.'}</p>
          </div>
        </Card>
      </div>

      <div className="lw-ci-data-grid">
        <AnalysisList title="Pontos fortes do atendimento" items={analysis.positive_points} emptyText="Nenhum destaque positivo foi identificado nesta conversa." />
        <AnalysisList title="Oportunidades de evolução" items={analysis.errors} emptyText="Nenhuma oportunidade crítica foi identificada." />
      </div>

      <div className="lw-ci-data-grid">
        <Card>
          <p className="lw-ci-eyebrow">Contexto da oportunidade</p>
          <h4>Dados comerciais extraídos</h4>
          {commercialEntries.length > 0 ? (
            <dl className="lw-ci-definition-list">
              {commercialEntries.map(([key, value]) => (
                <div key={key}><dt>{commercialLabel(key)}</dt><dd>{formatCommercialValue(value)}</dd></div>
              ))}
            </dl>
          ) : <p className="lw-text-sm-soft">Nenhum dado estruturado identificado.</p>}
        </Card>
        <Card>
          <p className="lw-ci-eyebrow">Qualidade do atendimento</p>
          <h4>Critérios comerciais</h4>
          {criteriaEntries.length > 0 ? (
            <div className="lw-ci-criteria">
              {criteriaEntries.map(([key, value]) => (
                <div key={key}>
                  <span>{criteriaLabel(key)}</span>
                  <strong>{Math.round(value)}/100</strong>
                  <div className="lw-ci-progress" aria-label={`${criteriaLabel(key)}: ${value} de 100`}>
                    <span style={{ width: `${Math.max(0, Math.min(value, 100))}%`, background: criterionColor(value) }} />
                  </div>
                </div>
              ))}
            </div>
          ) : <p className="lw-text-sm-soft">Sem critérios detalhados.</p>}
        </Card>
      </div>
    </div>
  );
}

export function ConversationIntelligencePage({ token, initialConversationId = null }: Props) {
  const [summary, setSummary] = useState<ConversationIntelligenceSummaryResponse['data'] | null>(null);
  const [conversations, setConversations] = useState<ConversationIntelligenceListItem[]>([]);
  const [detail, setDetail] = useState<ConversationIntelligenceDetail | null>(null);
  const [selectedConversationId, setSelectedConversationId] = useState<number | null>(null);
  const [selectedAnalysisId, setSelectedAnalysisId] = useState<number | null>(null);
  const [search, setSearch] = useState('');
  const [analysisStatus, setAnalysisStatus] = useState<'all' | 'pending' | 'analyzed'>('all');
  const [page, setPage] = useState(1);
  const [lastPage, setLastPage] = useState(1);
  const [loading, setLoading] = useState(true);
  const [detailLoading, setDetailLoading] = useState(false);
  const [analyzing, setAnalyzing] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [actionMessage, setActionMessage] = useState<string | null>(null);

  const selectedAnalysis = useMemo(() => {
    if (!detail) return null;
    return detail.analysis_history.find((item) => item.id === selectedAnalysisId)
      ?? detail.latest_analysis;
  }, [detail, selectedAnalysisId]);

  async function loadOverview(targetPage = page) {
    setLoading(true);
    setError(null);
    try {
      const [summaryResponse, listResponse] = await Promise.all([
        getConversationIntelligenceSummary(token),
        getConversationIntelligenceList(token, {
          search: search.trim() || undefined,
          analysis_status: analysisStatus === 'all' ? undefined : analysisStatus,
          page: targetPage,
        }),
      ]);
      setSummary(summaryResponse.data);
      setConversations(listResponse.data);
      setPage(listResponse.meta.page);
      setLastPage(listResponse.meta.last_page);
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Não foi possível carregar a Inteligência de Conversas.');
    } finally {
      setLoading(false);
    }
  }

  async function openConversation(conversationId: number) {
    setSelectedConversationId(conversationId);
    setDetailLoading(true);
    setError(null);
    setActionMessage(null);
    try {
      const response = await getConversationIntelligenceDetail(token, conversationId);
      setDetail(response.data);
      setSelectedAnalysisId(response.data.latest_analysis?.id ?? null);
    } catch (err) {
      setDetail(null);
      setError(err instanceof Error ? err.message : 'Não foi possível abrir a conversa.');
    } finally {
      setDetailLoading(false);
    }
  }

  async function handleAnalyze() {
    if (!selectedConversationId) return;
    setAnalyzing(true);
    setError(null);
    setActionMessage(null);
    try {
      const response = await analyzeConversation(token, selectedConversationId);
      setDetail(response.data);
      setSelectedAnalysisId(response.data.latest_analysis?.id ?? null);
      setActionMessage(response.message);
      await loadOverview(page);
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Não foi possível analisar a conversa.');
    } finally {
      setAnalyzing(false);
    }
  }

  useEffect(() => {
    void loadOverview(1);
    // Filtros são aplicados explicitamente pelo botão para evitar chamadas a cada tecla.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [token]);

  useEffect(() => {
    if (!initialConversationId) return;
    void openConversation(initialConversationId);
    // O identificador é controlado pelo Auto-CRM; a função usa o token atual.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [initialConversationId, token]);

  return (
    <div className="lw-ci-page">
      <Section>
        <div className="lw-ci-heading">
          <div>
            <p className="lw-ci-eyebrow">Inteligência Comercial</p>
            <h2>Inteligência de Conversas</h2>
            <p className="lw-text-sm-soft">Transforme cada atendimento em sinais claros de receita, risco e próxima ação comercial.</p>
          </div>
          <Badge variant="info">Análise passiva · somente leitura</Badge>
        </div>

        <div className="lw-metrics-grid lw-mt-3">
          <MetricCard label="Conversas monitoradas" value={summary?.total_conversations ?? '—'} hint="Base comercial disponível" />
          <MetricCard label="Conversas analisadas" value={summary?.analyzed_conversations ?? '—'} hint="Com leitura executiva" />
          <MetricCard label="Pontuação média" value={summary?.average_score !== null && summary?.average_score !== undefined ? `${summary.average_score}/100` : '—'} hint="Qualidade da análise mais recente" />
          <MetricCard label="Aguardando análise" value={summary?.pending_conversations ?? '—'} hint="Oportunidades sem leitura da IA" />
        </div>
      </Section>

      <Section>
        <form className="lw-ci-filters" onSubmit={(event) => { event.preventDefault(); void loadOverview(1); }}>
          <FormGroup label="Buscar conversa">
            <Input value={search} onChange={(event) => setSearch(event.target.value)} placeholder="Nome ou telefone" />
          </FormGroup>
          <FormGroup label="Situação da análise">
            <Select value={analysisStatus} onChange={(event) => setAnalysisStatus(event.target.value as typeof analysisStatus)}>
              <option value="all">Todas</option>
              <option value="pending">Ainda não analisada</option>
              <option value="analyzed">Analisada</option>
            </Select>
          </FormGroup>
          <Button type="submit" disabled={loading}>Aplicar filtros</Button>
        </form>
      </Section>

      {error ? <ErrorState message={error} /> : null}
      {actionMessage ? <Alert variant="success">{actionMessage}</Alert> : null}
      {loading && conversations.length === 0 ? <LoadingState message="Carregando conversas..." /> : null}

      {!loading && conversations.length === 0 ? (
        <EmptyState title="Nenhuma conversa encontrada." description="Ajuste os filtros ou carregue os dados de demonstração." />
      ) : null}

      {conversations.length > 0 ? (
        <div className="lw-ci-workspace">
          <Section className="lw-ci-list-section">
            <div className="lw-ci-list" aria-label="Conversas disponíveis para análise">
              {conversations.map((conversation) => (
                <button
                  type="button"
                  key={conversation.conversation_id}
                  className={`lw-ci-list-item ${selectedConversationId === conversation.conversation_id ? 'lw-ci-list-item--active' : ''}`}
                  onClick={() => void openConversation(conversation.conversation_id)}
                >
                  <div className="lw-ci-list-main">
                    <strong>{conversation.lead_name || conversation.phone || `Conversa ${conversation.conversation_id}`}</strong>
                    <span>{conversation.current_stage || 'Sem etapa'} · {conversation.owner_name || 'Sem responsável'}</span>
                    <small>{formatDateTime(conversation.last_message_at)}</small>
                  </div>
                  {conversation.latest_analysis ? (
                    <div className="lw-ci-list-score">
                      <Badge variant={scoreBadge(conversation.latest_analysis.score)}>{conversation.latest_analysis.score}/100</Badge>
                      <small>{conversation.analysis_count > 1 ? `${conversation.analysis_count} análises` : 'Analisada'}</small>
                    </div>
                  ) : (
                    <Badge variant="neutral">Ainda não analisada</Badge>
                  )}
                </button>
              ))}
            </div>
            <div className="lw-ci-pagination">
              <Button type="button" onClick={() => void loadOverview(page - 1)} disabled={page <= 1 || loading}>Anterior</Button>
              <span>Página {page} de {Math.max(lastPage, 1)}</span>
              <Button type="button" onClick={() => void loadOverview(page + 1)} disabled={page >= lastPage || loading}>Próxima</Button>
            </div>
          </Section>

          <Section className="lw-ci-detail-section">
            {detailLoading ? <LoadingState message="Abrindo conversa..." /> : null}
            {!detailLoading && !detail ? (
              <EmptyState title="Selecione uma conversa" description="A transcrição e a análise aparecerão aqui." />
            ) : null}
            {!detailLoading && detail ? (
              <div className="lw-ci-detail">
                <div className="lw-ci-detail-header">
                  <div>
                    <p className="lw-ci-eyebrow">Visão executiva · conversa #{detail.conversation_id}</p>
                    <h3>{detail.lead.name || detail.lead.phone}</h3>
                    <div className="lw-flex-wrap-gap" style={{ marginTop: 'var(--lw-space-2)' }}>
                      <Badge variant="info">Etapa: {detail.lead.current_stage || 'Não informada'}</Badge>
                      <Badge variant="neutral">Responsável: {detail.owner.name || 'Não definido'}</Badge>
                      <Badge variant="neutral">Origem: {sourceLabel(detail.lead.source)}</Badge>
                    </div>
                  </div>
                  <Button type="button" onClick={() => void handleAnalyze()} disabled={analyzing}>
                    {analyzing ? 'Analisando conversa...' : detail.latest_analysis ? 'Reanalisar' : 'Analisar com IA'}
                  </Button>
                </div>

                {selectedAnalysis ? (
                  <AnalysisPanel analysis={selectedAnalysis} currentStage={detail.lead.current_stage} />
                ) : (
                  <Alert variant="info">Esta conversa ainda não possui diagnóstico. Use “Analisar com IA” para gerar a primeira leitura executiva.</Alert>
                )}

                {detail.analysis_history.length > 1 ? (
                  <details className="lw-card">
                    <summary style={{ cursor: 'pointer', color: 'var(--lw-color-text-soft)', fontWeight: 600 }}>
                      Histórico de análises · {detail.analysis_history.length} leituras preservadas
                    </summary>
                    <div style={{ marginTop: 'var(--lw-space-3)' }}>
                      <FormGroup label="Leitura exibida">
                        <Select value={selectedAnalysis?.id ?? ''} onChange={(event) => setSelectedAnalysisId(Number(event.target.value))}>
                          {detail.analysis_history.map((analysis) => (
                            <option key={analysis.id} value={analysis.id}>Análise {analysis.analysis_version} · {analysis.score}/100 · {formatDateTime(analysis.analyzed_at)}</option>
                          ))}
                        </Select>
                      </FormGroup>
                    </div>
                  </details>
                ) : null}

                <details className="lw-card">
                  <summary style={{ cursor: 'pointer', color: 'var(--lw-color-text)', fontWeight: 700 }}>
                    Conversa completa · {detail.messages.length} {detail.messages.length === 1 ? 'mensagem' : 'mensagens'} · somente leitura
                  </summary>
                  <div className="lw-ci-transcript" style={{ marginTop: 'var(--lw-space-4)' }}>
                    <div>
                      <p className="lw-ci-eyebrow">Transcrição completa</p>
                      <h3>Contexto que sustenta a análise</h3>
                    </div>
                    {detail.messages.map((message) => (
                      <article key={message.id} className={`lw-ci-message lw-ci-message--${message.direction}`}>
                        <div>
                          <strong>{message.direction === 'inbound' ? 'Cliente' : 'Atendente'}</strong>
                          <small>{formatDateTime(message.sent_at)}</small>
                        </div>
                        <p>{message.body || '[Mensagem sem texto]'}</p>
                        {message.audio_transcript ? <p className="lw-ci-audio-transcript">Transcrição de áudio: {message.audio_transcript}</p> : null}
                      </article>
                    ))}
                  </div>
                </details>
              </div>
            ) : null}
          </Section>
        </div>
      ) : null}
    </div>
  );
}
