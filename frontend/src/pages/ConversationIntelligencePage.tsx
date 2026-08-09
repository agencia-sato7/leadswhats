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
};

function formatDateTime(value: string | null | undefined): string {
  if (!value) return 'Sem registro';
  const date = new Date(value);
  return Number.isNaN(date.getTime()) ? value : date.toLocaleString('pt-BR');
}

function humanizeKey(value: string): string {
  return value.replaceAll('_', ' ').replace(/^./, (letter) => letter.toUpperCase());
}

function formatCommercialValue(value: unknown): string {
  if (typeof value === 'boolean') return value ? 'Sim' : 'Não';
  if (Array.isArray(value)) return value.join(', ');
  if (value && typeof value === 'object') return JSON.stringify(value);
  return String(value ?? 'Não identificado');
}

function scoreBadge(score: number): 'success' | 'warning' | 'danger' {
  if (score >= 80) return 'success';
  if (score >= 60) return 'warning';
  return 'danger';
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

function AnalysisPanel({ analysis }: { analysis: ConversationQualityAnalysis }) {
  const commercialEntries = Object.entries(analysis.commercial_data);
  const criteriaEntries = Object.entries(analysis.criteria_scores);

  return (
    <div className="lw-ci-analysis" aria-label={`Análise versão ${analysis.analysis_version}`}>
      <div className="lw-ci-analysis-hero">
        <div className={`lw-ci-score lw-ci-score--${scoreBadge(analysis.score)}`}>
          <strong>{analysis.score}</strong>
          <span>de 100</span>
        </div>
        <div>
          <div className="lw-flex-wrap-gap">
            <Badge variant="info">Versão {analysis.analysis_version}</Badge>
            <Badge variant={scoreBadge(analysis.score)}>{analysis.intent || 'Intenção não identificada'}</Badge>
          </div>
          <p className="lw-ci-summary">{analysis.summary}</p>
          <small className="lw-text-xs-muted">Analisada em {formatDateTime(analysis.analyzed_at)}</small>
        </div>
      </div>

      <div className="lw-ci-insights-grid">
        <AnalysisList title="Pontos positivos" items={analysis.positive_points} emptyText="Nenhum ponto destacado." />
        <AnalysisList title="Erros e oportunidades" items={analysis.errors} emptyText="Nenhum erro relevante identificado." />
        <AnalysisList title="Objeções" items={analysis.objections} emptyText="Nenhuma objeção explícita." />
      </div>

      <Card className="lw-ci-recommendation">
        <div>
          <p className="lw-ci-eyebrow">Próxima etapa recomendada</p>
          <h4>{analysis.recommended_kanban_column_name || 'Sem recomendação de etapa'}</h4>
          <p>{analysis.classification_reason || 'Sem motivo informado.'}</p>
        </div>
        {analysis.confidence !== null ? <Badge variant="info">Confiança {Math.round(analysis.confidence * 100)}%</Badge> : null}
      </Card>

      <Card className="lw-ci-improvement">
        <p className="lw-ci-eyebrow">Sugestão de melhoria</p>
        <p>{analysis.improvement_suggestion || 'Sem sugestão adicional.'}</p>
      </Card>

      <div className="lw-ci-data-grid">
        <Card>
          <h4>Dados comerciais extraídos</h4>
          {commercialEntries.length > 0 ? (
            <dl className="lw-ci-definition-list">
              {commercialEntries.map(([key, value]) => (
                <div key={key}><dt>{humanizeKey(key)}</dt><dd>{formatCommercialValue(value)}</dd></div>
              ))}
            </dl>
          ) : <p className="lw-text-sm-soft">Nenhum dado estruturado identificado.</p>}
        </Card>
        <Card>
          <h4>Critérios de qualidade</h4>
          {criteriaEntries.length > 0 ? (
            <div className="lw-ci-criteria">
              {criteriaEntries.map(([key, value]) => (
                <div key={key}>
                  <span>{humanizeKey(key)}</span>
                  <strong>{value}</strong>
                  <div className="lw-ci-progress" aria-label={`${humanizeKey(key)}: ${value} de 100`}>
                    <span style={{ width: `${Math.max(0, Math.min(value, 100))}%` }} />
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

export function ConversationIntelligencePage({ token }: Props) {
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
      setError(err instanceof Error ? err.message : 'Não foi possível carregar a Conversation Intelligence.');
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

  return (
    <div className="lw-ci-page">
      <Section>
        <div className="lw-ci-heading">
          <div>
            <p className="lw-ci-eyebrow">Intelligence Avenue</p>
            <h2>Qualidade das conversas comerciais</h2>
            <p className="lw-text-sm-soft">Analise atendimento, intenção, objeções e oportunidades sem responder mensagens pelo LEADSWHATS.</p>
          </div>
          <Badge variant="info">Somente leitura</Badge>
        </div>

        <div className="lw-metrics-grid lw-mt-3">
          <MetricCard label="Conversas" value={summary?.total_conversations ?? '—'} />
          <MetricCard label="Ainda não analisadas" value={summary?.pending_conversations ?? '—'} hint="Disponíveis para análise ao vivo" />
          <MetricCard label="Score médio" value={summary?.average_score ?? '—'} hint="Último snapshot de cada conversa" />
          <MetricCard label="Snapshots" value={summary?.total_snapshots ?? '—'} hint="Reanálises preservadas" />
        </div>
      </Section>

      <Section>
        <form className="lw-ci-filters" onSubmit={(event) => { event.preventDefault(); void loadOverview(1); }}>
          <FormGroup label="Buscar conversa">
            <Input value={search} onChange={(event) => setSearch(event.target.value)} placeholder="Nome ou telefone" />
          </FormGroup>
          <FormGroup label="Status da análise">
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
        <EmptyState title="Nenhuma conversa encontrada." description="Ajuste os filtros ou execute o bootstrap de demonstração." />
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
                    <p className="lw-ci-eyebrow">Conversa #{detail.conversation_id}</p>
                    <h3>{detail.lead.name || detail.lead.phone}</h3>
                    <p>{detail.lead.current_stage || 'Sem etapa'} · {detail.owner.name || 'Sem responsável'} · origem {detail.lead.source || 'desconhecida'}</p>
                  </div>
                  <Button type="button" onClick={() => void handleAnalyze()} disabled={analyzing}>
                    {analyzing ? 'Analisando conversa...' : detail.latest_analysis ? 'Reanalisar' : 'Analisar com IA'}
                  </Button>
                </div>

                {detail.analysis_history.length > 1 ? (
                  <FormGroup label="Snapshot exibido">
                    <Select value={selectedAnalysis?.id ?? ''} onChange={(event) => setSelectedAnalysisId(Number(event.target.value))}>
                      {detail.analysis_history.map((analysis) => (
                        <option key={analysis.id} value={analysis.id}>Versão {analysis.analysis_version} · {analysis.score}/100 · {formatDateTime(analysis.analyzed_at)}</option>
                      ))}
                    </Select>
                  </FormGroup>
                ) : null}

                {selectedAnalysis ? (
                  <AnalysisPanel analysis={selectedAnalysis} />
                ) : (
                  <Alert variant="info">Ainda não analisada. Use “Analisar com IA” para gerar o primeiro snapshot ao vivo.</Alert>
                )}

                <div className="lw-ci-transcript">
                  <div>
                    <p className="lw-ci-eyebrow">Transcrição completa</p>
                    <h3>Mensagens somente leitura</h3>
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
              </div>
            ) : null}
          </Section>
        </div>
      ) : null}
    </div>
  );
}
