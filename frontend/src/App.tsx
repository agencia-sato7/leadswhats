import { useEffect, useMemo, useState } from 'react';
import {
  classifyLeadSource,
  getContacts,
  getDashboardSummary,
  getLeadStageHistory,
  getOverview,
  getPipelineKanban,
  getPipelines,
  getRecentLeads,
  getTasksChecklist,
  getUnknownLeads,
  login,
  moveLeadStage,
} from './api';
import type {
  AuthUser,
  ChecklistTaskItem,
  ContactItem,
  ContactsResponse,
  DashboardSummaryResponse,
  LeadSourceItem,
  LeadStageHistoryItem,
  OverviewResponse,
  PipelineKanban,
  PipelineListItem,
} from './types';

type Session = {
  token: string;
  user: AuthUser;
};

const STORAGE_KEY = 'leadswhats_session';
const QUICK_SOURCES = ['instagram', 'google', 'facebook', 'indicacao', 'outro'];
const CHECKLIST_DEFAULT_MESSAGE = 'Olá! Passando para saber se posso te ajudar com mais alguma informação.';

function formatSeconds(seconds: number): string {
  const mins = Math.floor(seconds / 60);
  const h = Math.floor(mins / 60);
  const m = mins % 60;
  if (h > 0) return `${h}h ${m}m`;
  return `${m}m`;
}

function formatDateTime(value: string | null | undefined): string {
  if (!value) return 'Sem registro';
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) return value;
  return date.toLocaleString('pt-BR');
}

export function App() {
  const [email, setEmail] = useState('gestor@empresa.local');
  const [password, setPassword] = useState('12345678');
  const [session, setSession] = useState<Session | null>(null);
  const [overview, setOverview] = useState<OverviewResponse | null>(null);
  const [dashboard, setDashboard] = useState<DashboardSummaryResponse | null>(null);
  const [unknownLeads, setUnknownLeads] = useState<LeadSourceItem[]>([]);
  const [recentLeads, setRecentLeads] = useState<LeadSourceItem[]>([]);
  const [checklistItems, setChecklistItems] = useState<ChecklistTaskItem[]>([]);
  const [checklistLoading, setChecklistLoading] = useState(false);
  const [checklistError, setChecklistError] = useState<string | null>(null);
  const [copyFeedback, setCopyFeedback] = useState<string | null>(null);
  const [contacts, setContacts] = useState<ContactItem[]>([]);
  const [contactsMeta, setContactsMeta] = useState<ContactsResponse['meta']>({
    page: 1,
    per_page: 10,
    total: 0,
    last_page: 1,
  });
  const [contactsPage, setContactsPage] = useState(1);
  const [contactsLoading, setContactsLoading] = useState(false);
  const [contactsError, setContactsError] = useState<string | null>(null);
  const [contactSearch, setContactSearch] = useState('');
  const [contactSearchInput, setContactSearchInput] = useState('');
  const [contactSourceFilter, setContactSourceFilter] = useState('');
  const [contactClassificationFilter, setContactClassificationFilter] = useState<'' | 'lead_novo' | 'lead_repetido'>('');
  const [contactStageFilter, setContactStageFilter] = useState<number | ''>('');

  const [pipelines, setPipelines] = useState<PipelineListItem[]>([]);
  const [selectedPipelineId, setSelectedPipelineId] = useState<number | null>(null);
  const [kanban, setKanban] = useState<PipelineKanban | null>(null);
  const [stageHistoryByLead, setStageHistoryByLead] = useState<Record<number, LeadStageHistoryItem[]>>({});
  const [historyLoadingLeadId, setHistoryLoadingLeadId] = useState<number | null>(null);
  const [draggingCard, setDraggingCard] = useState<{ leadId: number; fromColumnId: number } | null>(null);
  const [dragOverColumnId, setDragOverColumnId] = useState<number | null>(null);
  const [pressedCardId, setPressedCardId] = useState<number | null>(null);

  const [loading, setLoading] = useState(false);
  const [kanbanLoading, setKanbanLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [kanbanError, setKanbanError] = useState<string | null>(null);

  useEffect(() => {
    const raw = localStorage.getItem(STORAGE_KEY);
    if (raw) {
      try {
        setSession(JSON.parse(raw) as Session);
      } catch {
        localStorage.removeItem(STORAGE_KEY);
      }
    }
  }, []);

  const canManageSource = session?.user.role === 'gestor' || session?.user.role === 'admin';
  const canMoveStage = canManageSource;

  const columnNameById = useMemo(() => {
    const map: Record<number, string> = {};
    for (const column of kanban?.columns ?? []) {
      map[column.id] = column.name;
    }
    return map;
  }, [kanban]);

  async function refreshData(token: string) {
    const [overviewData, dashboardData, pipelineData, checklistData] = await Promise.all([
      getOverview(token),
      getDashboardSummary(token),
      getPipelines(token),
      getTasksChecklist(token),
    ]);

    setOverview(overviewData);
    setDashboard(dashboardData);
    setPipelines(pipelineData);
    setChecklistItems(checklistData);

    if (pipelineData.length === 0) {
      setSelectedPipelineId(null);
      setKanban(null);
    } else if (!selectedPipelineId || !pipelineData.some((p) => p.id === selectedPipelineId)) {
      setSelectedPipelineId(pipelineData[0].id);
    }

    if (canManageSource) {
      const [unknownData, recentData] = await Promise.all([
        getUnknownLeads(token),
        getRecentLeads(token),
      ]);
      setUnknownLeads(unknownData);
      setRecentLeads(recentData);
    } else {
      setUnknownLeads([]);
      setRecentLeads([]);
    }
  }

  async function refreshKanban(token: string, pipelineId: number) {
    setKanbanLoading(true);
    setKanbanError(null);
    try {
      const kanbanData = await getPipelineKanban(token, pipelineId);
      setKanban(kanbanData);
      setStageHistoryByLead({});
      setDraggingCard(null);
      setDragOverColumnId(null);
    } catch (err) {
      setKanban(null);
      setKanbanError('Não foi possível carregar o Kanban.');
      console.error(err);
    } finally {
      setKanbanLoading(false);
    }
  }

  async function refreshContacts(token: string, page = 1) {
    setContactsLoading(true);
    setContactsError(null);
    try {
      const response = await getContacts(token, {
        page,
        per_page: contactsMeta.per_page,
        search: contactSearch || undefined,
        source: contactSourceFilter || undefined,
        classification: contactClassificationFilter || '',
        stage_id: contactStageFilter,
      });
      setContacts(response.data);
      setContactsMeta(response.meta);
    } catch (err) {
      setContacts([]);
      setContactsError('Não foi possível carregar os contatos.');
      console.error(err);
    } finally {
      setContactsLoading(false);
    }
  }

  useEffect(() => {
    if (!session) return;

    setLoading(true);
    setError(null);
    setChecklistLoading(true);
    setChecklistError(null);

    refreshData(session.token)
      .catch((err) => {
        setError('Falha ao carregar dados da API.');
        setChecklistError('Não foi possível carregar o checklist.');
        console.error(err);
      })
      .finally(() => {
        setLoading(false);
        setChecklistLoading(false);
      });
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [session]);

  useEffect(() => {
    if (!session || !selectedPipelineId) return;
    refreshKanban(session.token, selectedPipelineId).catch((err) => console.error(err));
  }, [session, selectedPipelineId]);

  useEffect(() => {
    if (!session) return;
    refreshContacts(session.token, contactsPage).catch((err) => console.error(err));
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [session, contactsPage, contactSearch, contactSourceFilter, contactClassificationFilter, contactStageFilter]);

  async function handleLogin(e: React.FormEvent) {
    e.preventDefault();
    setLoading(true);
    setError(null);

    try {
      const data = await login(email, password);
      const newSession: Session = { token: data.token, user: data.user };
      setSession(newSession);
      localStorage.setItem(STORAGE_KEY, JSON.stringify(newSession));
    } catch {
      setError('Credenciais inválidas ou API indisponível.');
    } finally {
      setLoading(false);
    }
  }

  function logout() {
    setSession(null);
    setOverview(null);
    setDashboard(null);
    setUnknownLeads([]);
    setRecentLeads([]);
    setChecklistItems([]);
    setPipelines([]);
    setSelectedPipelineId(null);
    setKanban(null);
    setContacts([]);
    setStageHistoryByLead({});
    setDraggingCard(null);
    setDragOverColumnId(null);
    localStorage.removeItem(STORAGE_KEY);
  }

  async function quickClassify(leadId: number, source: string) {
    if (!session || !canManageSource) return;
    setLoading(true);
    setError(null);
    try {
      await classifyLeadSource(session.token, leadId, source, 'Classificação rápida no painel de gestão');
      await refreshData(session.token);
    } catch {
      setError('Não foi possível classificar a origem do lead.');
    } finally {
      setLoading(false);
    }
  }

  async function copyText(text: string, successMessage: string) {
    try {
      await navigator.clipboard.writeText(text);
      setCopyFeedback(successMessage);
    } catch {
      setCopyFeedback('Não foi possível copiar agora.');
    } finally {
      setTimeout(() => setCopyFeedback(null), 1800);
    }
  }

  async function handleMoveLeadToColumn(leadId: number, fromColumnId: number, targetColumnId: number) {
    if (!session || !canMoveStage) return;
    if (!targetColumnId || targetColumnId === fromColumnId) return;

    setKanbanLoading(true);
    setKanbanError(null);
    try {
      await moveLeadStage(session.token, leadId, targetColumnId, 'Movido manualmente pelo operador');
      if (selectedPipelineId) {
        await refreshKanban(session.token, selectedPipelineId);
      }
    } catch (err) {
      setKanbanError('Não foi possível mover o card.');
      console.error(err);
    } finally {
      setKanbanLoading(false);
      setDraggingCard(null);
      setDragOverColumnId(null);
    }
  }

  function handleCardDragStart(leadId: number, fromColumnId: number) {
    if (!canMoveStage) return;
    setDraggingCard({ leadId, fromColumnId });
  }

  function handleColumnDragOver(event: React.DragEvent<HTMLDivElement>, columnId: number) {
    if (!canMoveStage || !draggingCard) return;
    event.preventDefault();
    if (draggingCard.fromColumnId !== columnId) {
      setDragOverColumnId(columnId);
    }
  }

  async function handleColumnDrop(event: React.DragEvent<HTMLDivElement>, targetColumnId: number) {
    event.preventDefault();
    if (!canMoveStage || !draggingCard) return;
    await handleMoveLeadToColumn(draggingCard.leadId, draggingCard.fromColumnId, targetColumnId);
  }

  function handleDragEnd() {
    setDraggingCard(null);
    setDragOverColumnId(null);
    setPressedCardId(null);
  }

  async function handleToggleHistory(leadId: number) {
    if (!session) return;

    if (stageHistoryByLead[leadId]) {
      setStageHistoryByLead((prev) => {
        const next = { ...prev };
        delete next[leadId];
        return next;
      });
      return;
    }

    setHistoryLoadingLeadId(leadId);
    try {
      const history = await getLeadStageHistory(session.token, leadId);
      setStageHistoryByLead((prev) => ({ ...prev, [leadId]: history }));
    } catch (err) {
      setKanbanError('Não foi possível carregar o histórico da etapa.');
      console.error(err);
    } finally {
      setHistoryLoadingLeadId(null);
    }
  }

  async function applyContactsFilters(event: React.FormEvent) {
    event.preventDefault();
    setContactSearch(contactSearchInput.trim());
    setContactsPage(1);
  }

  function changeContactsPage(nextPage: number) {
    if (nextPage < 1 || nextPage > contactsMeta.last_page) return;
    setContactsPage(nextPage);
  }

  if (!session) {
    return (
      <main style={{ maxWidth: 420, margin: '40px auto', fontFamily: 'system-ui', padding: 16 }}>
        <h1>LEADSWHATS</h1>
        <p>Login para acessar o dashboard inicial.</p>
        <form onSubmit={handleLogin} style={{ display: 'grid', gap: 12 }}>
          <input value={email} onChange={(e) => setEmail(e.target.value)} placeholder="Email" />
          <input type="password" value={password} onChange={(e) => setPassword(e.target.value)} placeholder="Senha" />
          <button type="submit" disabled={loading}>{loading ? 'Entrando...' : 'Entrar'}</button>
        </form>
        {error ? <p style={{ color: 'crimson' }}>{error}</p> : null}
      </main>
    );
  }

  return (
    <main style={{ maxWidth: 1200, margin: '20px auto', fontFamily: 'system-ui', padding: 16 }}>
      <header style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
        <div>
          <h1 style={{ marginBottom: 0 }}>LEADSWHATS</h1>
          <small>{session.user.name} ({session.user.role})</small>
        </div>
        <button onClick={logout}>Sair</button>
      </header>

      {loading ? <p>Carregando dados...</p> : null}
      {error ? <p style={{ color: 'crimson' }}>{error}</p> : null}

      {overview ? (
        <section>
          <h2>Empresa</h2>
          <p><strong>{overview.company.name}</strong> ({overview.company.slug})</p>
          <p>Horário: {overview.company.work_start} às {overview.company.work_end}</p>
        </section>
      ) : null}

      {dashboard ? (
        <section>
          <h2>Dashboard Diário ({dashboard.date})</h2>
          <ul>
            <li>Leads novos hoje: {dashboard.metrics.new_leads_today}</li>
            <li>Leads repetidos hoje: {dashboard.metrics.repeat_leads_today}</li>
            <li>Tempo médio primeira resposta: {formatSeconds(dashboard.metrics.avg_first_response_seconds)}</li>
            <li>Leads em vácuo (+24h): {dashboard.metrics.vacuum_24h_open}</li>
            <li>Resgates hoje: {dashboard.metrics.rescues_today}</li>
            <li>Conversas ativas: {dashboard.metrics.active_conversations}</li>
            <li>Origem desconhecida (aberto): {dashboard.metrics.unknown_source_leads}</li>
            <li>Classificações manuais hoje: {dashboard.metrics.manual_classifications_today}</li>
            <li>Tarefas operacionais abertas: {dashboard.metrics.open_tasks}</li>
            <li>Tarefas de follow-up em vácuo: {dashboard.metrics.vacuum_follow_up_tasks}</li>
          </ul>
        </section>
      ) : null}

      <section style={{ border: '1px solid #ddd', borderRadius: 8, padding: 12, marginTop: 16 }}>
        <h2 style={{ marginTop: 0 }}>Checklist do Dia</h2>
        {checklistLoading ? <p>Carregando checklist...</p> : null}
        {checklistError ? <p style={{ color: 'crimson' }}>{checklistError}</p> : null}
        {copyFeedback ? <p style={{ color: '#1b7f3b' }}>{copyFeedback}</p> : null}

        {!checklistLoading && !checklistError && checklistItems.length === 0 ? (
          <p>Nenhuma tarefa operacional pendente no momento.</p>
        ) : null}

        {!checklistLoading && !checklistError && checklistItems.length > 0 ? (
          <div style={{ display: 'grid', gap: 10 }}>
            {checklistItems.map((item) => (
              <div key={`${item.lead_id}-${item.conversation_id}-${item.task_type}`} style={{ border: '1px solid #ddd', borderRadius: 8, padding: 10 }}>
                <p style={{ margin: 0 }}><strong>{item.lead_name || item.phone}</strong></p>
                <small style={{ display: 'block' }}>Telefone: {item.phone}</small>
                <small style={{ display: 'block' }}>Source: {item.source}</small>
                <small style={{ display: 'block' }}>Etapa atual: {item.current_stage || 'Sem etapa'}</small>
                <small style={{ display: 'block' }}>Tarefa: {item.task_label}</small>
                <small style={{ display: 'block' }}>Horas desde última mensagem: {item.hours_since_last_message}</small>
                <small style={{ display: 'block', marginBottom: 8 }}>Prioridade: <strong>{item.priority}</strong></small>
                <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>
                  <button onClick={() => void copyText(item.phone, 'Telefone copiado!')}>Copiar telefone</button>
                  <button onClick={() => void copyText(CHECKLIST_DEFAULT_MESSAGE, 'Mensagem padrão copiada!')}>Copiar mensagem padrão</button>
                </div>
              </div>
            ))}
          </div>
        ) : null}
      </section>

      <section style={{ border: '1px solid #ddd', borderRadius: 8, padding: 12, marginTop: 16 }}>
        <h2 style={{ marginTop: 0 }}>Kanban</h2>
        {!canMoveStage ? (
          <p style={{ marginTop: 0 }}>
            Você está em perfil <strong>SDR</strong>: pode visualizar o Kanban, mas não pode mover cards.
          </p>
        ) : null}

        {pipelines.length === 0 ? <p>Nenhum pipeline disponível para esta empresa.</p> : null}

        {pipelines.length > 1 ? (
          <label style={{ display: 'block', marginBottom: 12 }}>
            Pipeline:
            <select
              style={{ marginLeft: 8 }}
              value={selectedPipelineId ?? ''}
              onChange={(e) => setSelectedPipelineId(Number(e.target.value))}
            >
              {pipelines.map((pipeline) => (
                <option key={pipeline.id} value={pipeline.id}>{pipeline.name}</option>
              ))}
            </select>
          </label>
        ) : null}

        {pipelines.length === 1 && selectedPipelineId ? (
          <p style={{ marginTop: 0 }}><strong>Pipeline:</strong> {pipelines[0].name}</p>
        ) : null}

        {kanbanLoading ? <p>Carregando Kanban...</p> : null}
        {kanbanError ? <p style={{ color: 'crimson' }}>{kanbanError}</p> : null}

        {!kanbanLoading && !kanbanError && kanban && kanban.columns.length === 0 ? (
          <p>Kanban vazio: este pipeline ainda não possui colunas.</p>
        ) : null}

        {!kanbanLoading && !kanbanError && kanban && kanban.columns.length > 0 ? (
          <div style={{ display: 'flex', gap: 12, overflowX: 'auto', alignItems: 'flex-start', paddingBottom: 8 }}>
            {kanban.columns.map((column) => (
              <div
                key={column.id}
                onDragOver={(event) => handleColumnDragOver(event, column.id)}
                onDrop={(event) => {
                  void handleColumnDrop(event, column.id);
                }}
                onDragLeave={() => {
                  if (dragOverColumnId === column.id) {
                    setDragOverColumnId(null);
                  }
                }}
                style={{
                  minWidth: 280,
                  maxWidth: 320,
                  border: dragOverColumnId === column.id ? '2px dashed #2f7cf6' : '1px solid #ddd',
                  borderRadius: 8,
                  padding: 10,
                  background: dragOverColumnId === column.id ? '#eef5ff' : '#fafafa',
                }}
              >
                <h3 style={{ marginTop: 0, marginBottom: 8 }}>{column.name}</h3>
                <small style={{ display: 'block', marginBottom: 10 }}>Etapa atual: {column.name}</small>

                {column.cards.length === 0 ? <p style={{ margin: 0 }}>Sem cards nesta coluna.</p> : null}

                {column.cards.map((card) => {
                  const history = stageHistoryByLead[card.lead_id];
                  return (
                    <div
                      key={card.lead_id}
                      draggable={canMoveStage}
                      onDragStart={() => handleCardDragStart(card.lead_id, column.id)}
                      onDragEnd={handleDragEnd}
                      onMouseDown={() => {
                        if (canMoveStage) setPressedCardId(card.lead_id);
                      }}
                      onMouseUp={() => setPressedCardId(null)}
                      onMouseLeave={() => setPressedCardId(null)}
                      style={{
                        border: '1px solid #ccc',
                        borderRadius: 8,
                        padding: 10,
                        marginBottom: 8,
                        background: '#fff',
                        cursor: canMoveStage
                          ? (draggingCard?.leadId === card.lead_id || pressedCardId === card.lead_id ? 'grabbing' : 'grab')
                          : 'default',
                        transform: draggingCard?.leadId === card.lead_id || pressedCardId === card.lead_id ? 'scale(0.99)' : 'scale(1)',
                        boxShadow: draggingCard?.leadId === card.lead_id || pressedCardId === card.lead_id
                          ? '0 2px 10px rgba(0,0,0,0.15)'
                          : '0 1px 4px rgba(0,0,0,0.06)',
                        transition: 'transform 120ms ease, box-shadow 120ms ease',
                      }}
                    >
                      <p style={{ margin: 0 }}><strong>{card.name || card.phone}</strong></p>
                      <small style={{ display: 'block' }}>Telefone: {card.phone}</small>
                      <small style={{ display: 'block' }}>Source: {card.source}</small>
                      <small style={{ display: 'block' }}>Classificação: {card.classification}</small>
                      <small style={{ display: 'block' }}>Última mensagem: {card.last_message_at || 'Sem registro'}</small>
                      <small style={{ display: 'block', marginBottom: 8 }}>Etapa atual: {column.name}</small>
                      {canMoveStage ? <small style={{ display: 'block', marginBottom: 8, color: '#555' }}>☰ Arrastar para mover</small> : null}

                      <button onClick={() => handleToggleHistory(card.lead_id)} disabled={historyLoadingLeadId === card.lead_id}>
                        {history ? 'Ocultar histórico' : 'Ver histórico'}
                      </button>

                      {historyLoadingLeadId === card.lead_id ? <p>Carregando histórico...</p> : null}

                      {history ? (
                        <div style={{ marginTop: 8 }}>
                          {history.length === 0 ? <small>Sem histórico de etapa.</small> : null}
                          {history.slice(0, 5).map((item) => (
                            <div key={item.id} style={{ borderTop: '1px solid #eee', marginTop: 6, paddingTop: 6 }}>
                              <small style={{ display: 'block' }}>
                                {item.from_column_name || 'Sem etapa'} → {item.to_column_name || columnNameById[item.to_column_id] || 'Etapa'}
                              </small>
                              <small style={{ display: 'block' }}>Quando: {item.moved_at}</small>
                              {item.reason ? <small style={{ display: 'block' }}>Motivo: {item.reason}</small> : null}
                            </div>
                          ))}
                        </div>
                      ) : null}
                    </div>
                  );
                })}
              </div>
            ))}
          </div>
        ) : null}
      </section>

      <section style={{ border: '1px solid #ddd', borderRadius: 8, padding: 12, marginTop: 16 }}>
        <h2 style={{ marginTop: 0 }}>Contatos</h2>

        <form onSubmit={(event) => void applyContactsFilters(event)} style={{ display: 'grid', gap: 8, marginBottom: 12 }}>
          <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>
            <input
              value={contactSearchInput}
              onChange={(event) => setContactSearchInput(event.target.value)}
              placeholder="Buscar por nome ou telefone"
              style={{ minWidth: 260 }}
            />
            <select value={contactSourceFilter} onChange={(event) => setContactSourceFilter(event.target.value)}>
              <option value="">Todas as origens</option>
              <option value="instagram">instagram</option>
              <option value="google">google</option>
              <option value="facebook">facebook</option>
              <option value="indicacao">indicacao</option>
              <option value="desconhecido">desconhecido</option>
            </select>
            <select
              value={contactClassificationFilter}
              onChange={(event) => setContactClassificationFilter(event.target.value as '' | 'lead_novo' | 'lead_repetido')}
            >
              <option value="">Todas as classificações</option>
              <option value="lead_novo">lead_novo</option>
              <option value="lead_repetido">lead_repetido</option>
            </select>
            <select
              value={contactStageFilter}
              onChange={(event) => setContactStageFilter(event.target.value ? Number(event.target.value) : '')}
            >
              <option value="">Todas as etapas</option>
              {(kanban?.columns ?? []).map((column) => (
                <option key={column.id} value={column.id}>
                  {column.name}
                </option>
              ))}
            </select>
            <button type="submit">Aplicar filtros</button>
          </div>
        </form>

        {contactsLoading ? <p>Carregando contatos...</p> : null}
        {contactsError ? <p style={{ color: 'crimson' }}>{contactsError}</p> : null}

        {!contactsLoading && !contactsError && contacts.length === 0 ? (
          <p>Nenhum contato encontrado com os filtros atuais.</p>
        ) : null}

        {!contactsLoading && !contactsError && contacts.length > 0 ? (
          <>
            <div style={{ display: 'grid', gap: 8 }}>
              {contacts.map((contact) => (
                <div key={contact.lead_id} style={{ border: '1px solid #ddd', borderRadius: 8, padding: 10 }}>
                  <p style={{ margin: 0 }}><strong>{contact.name || contact.phone}</strong></p>
                  <small style={{ display: 'block' }}>Telefone: {contact.phone}</small>
                  <small style={{ display: 'block' }}>Origem: {contact.source}</small>
                  <small style={{ display: 'block' }}>Classificação: {contact.classification}</small>
                  <small style={{ display: 'block' }}>Etapa atual: {contact.current_stage || 'Sem etapa'}</small>
                  <small style={{ display: 'block' }}>Última mensagem: {formatDateTime(contact.last_message_at)}</small>
                  <small style={{ display: 'block', marginBottom: 8 }}>Criado em: {formatDateTime(contact.created_at)}</small>
                  <button onClick={() => void copyText(contact.phone, 'Telefone copiado!')}>Copiar telefone</button>
                </div>
              ))}
            </div>

            <div style={{ display: 'flex', alignItems: 'center', gap: 8, marginTop: 12 }}>
              <button onClick={() => changeContactsPage(contactsMeta.page - 1)} disabled={contactsMeta.page <= 1 || contactsLoading}>
                Anterior
              </button>
              <small>Página {contactsMeta.page} de {Math.max(contactsMeta.last_page, 1)}</small>
              <button
                onClick={() => changeContactsPage(contactsMeta.page + 1)}
                disabled={contactsMeta.page >= contactsMeta.last_page || contactsLoading}
              >
                Próxima
              </button>
              <small style={{ marginLeft: 8 }}>Total: {contactsMeta.total}</small>
            </div>
          </>
        ) : null}
      </section>

      {!canManageSource ? (
        <section style={{ border: '1px solid #ddd', borderRadius: 8, padding: 12 }}>
          <h2>Classificação de Origem</h2>
          <p>
            Apenas <strong>gestor</strong> ou <strong>admin</strong> podem classificar/reclassificar origem.
            Se necessário, o atendimento deve solicitar essa ação ao gestor.
          </p>
        </section>
      ) : null}

      {canManageSource ? (
        <>
          <section>
            <h2>Origem Pendente (Desconhecido)</h2>
            {unknownLeads.length === 0 ? <p>Nenhum lead pendente de classificação.</p> : null}
            {unknownLeads.map((lead) => (
              <div key={lead.id} style={{ border: '1px solid #ddd', borderRadius: 8, padding: 12, marginBottom: 10 }}>
                <p style={{ margin: 0 }}><strong>{lead.name || 'Sem nome'}</strong> - {lead.phone_e164}</p>
                <small>Última mensagem: {lead.last_inbound_at || lead.created_at}</small>
                <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap', marginTop: 8 }}>
                  {QUICK_SOURCES.map((source) => (
                    <button key={source} onClick={() => quickClassify(lead.id, source)} disabled={loading}>{source}</button>
                  ))}
                </div>
              </div>
            ))}
          </section>

          <section>
            <h2>Leads Recentes (Reclassificar)</h2>
            {recentLeads.length === 0 ? <p>Nenhum lead recente.</p> : null}
            {recentLeads.map((lead) => (
              <div key={lead.id} style={{ border: '1px solid #ddd', borderRadius: 8, padding: 12, marginBottom: 10 }}>
                <p style={{ margin: 0 }}>
                  <strong>{lead.name || 'Sem nome'}</strong> - {lead.phone_e164}
                </p>
                <small>Origem atual: <strong>{lead.source}</strong> ({lead.source_method})</small>
                <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap', marginTop: 8 }}>
                  {QUICK_SOURCES.map((source) => (
                    <button key={source} onClick={() => quickClassify(lead.id, source)} disabled={loading || lead.source === source}>
                      Trocar para {source}
                    </button>
                  ))}
                </div>
              </div>
            ))}
          </section>
        </>
      ) : null}
    </main>
  );
}
