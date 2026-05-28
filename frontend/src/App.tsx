import { useEffect, useMemo, useState } from 'react';
import {
  createAdminCompany,
  classifyLeadSource,
  exportContactsCsv,
  getAdminCompanies,
  getAssignableUsers,
  getContacts,
  getDashboardSummary,
  getInboxConversationDetail,
  getInboxConversationEvents,
  getInboxConversations,
  getLeadStageHistory,
  getOverview,
  getPipelineKanban,
  getPipelines,
  getRecentLeads,
  getTasksChecklist,
  getUnknownLeads,
  getWhatsAppSettings,
  login,
  moveLeadStage,
  sendInboxMessage,
  updateWhatsAppSettings,
  updateLeadOwner,
} from './api';
import { AppShell, PageHeader, Sidebar, Topbar } from './components/layout';
import {
  Alert,
  Table,
  Badge,
  Button,
  Card,
  EmptyState,
  ErrorState,
  FormGroup,
  Input,
  LoadingState,
  MetricCard,
  Select,
  Section,
} from './components/ui';
import type {
  AdminCompanyCreateRequest,
  AdminCompanyListItem,
  AuthUser,
  AssignableUser,
  ChecklistTaskItem,
  ContactItem,
  ContactsResponse,
  DashboardSummaryResponse,
  InboxConversationDetail,
  InboxConversationEvent,
  InboxConversationListItem,
  InboxConversationsResponse,
  LeadSourceItem,
  LeadStageHistoryItem,
  OverviewResponse,
  PipelineKanban,
  PipelineListItem,
  WhatsAppSettings,
  WhatsAppSettingsUpdateRequest,
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

function getFriendlyAuditEventType(eventType: InboxConversationEvent['event_type']): string {
  if (eventType === 'conversation_opened') return 'Conversa aberta';
  if (eventType === 'message_sent') return 'Mensagem enviada';
  if (eventType === 'stage_changed') return 'Etapa alterada';
  if (eventType === 'owner_assigned') return 'Responsável atribuído';
  if (eventType === 'owner_changed') return 'Responsável alterado';
  if (eventType === 'owner_removed') return 'Responsável removido';
  return eventType;
}

export function App() {
  type ActiveView = 'dashboard' | 'inbox' | 'checklist' | 'kanban' | 'contacts' | 'adminSaas' | 'whatsappSettings';
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
  const [contactsMeta, setContactsMeta] = useState<ContactsResponse['meta']>({ page: 1, per_page: 10, total: 0, last_page: 1 });
  const [contactsPage, setContactsPage] = useState(1);
  const [contactsLoading, setContactsLoading] = useState(false);
  const [contactsError, setContactsError] = useState<string | null>(null);
  const [contactSearch, setContactSearch] = useState('');
  const [contactSearchInput, setContactSearchInput] = useState('');
  const [contactSourceFilter, setContactSourceFilter] = useState('');
  const [contactClassificationFilter, setContactClassificationFilter] = useState<'' | 'lead_novo' | 'lead_repetido'>('');
  const [contactStageFilter, setContactStageFilter] = useState<number | ''>('');
  const [contactsExportLoading, setContactsExportLoading] = useState(false);
  const [contactsExportError, setContactsExportError] = useState<string | null>(null);
  const [contactsExportSuccess, setContactsExportSuccess] = useState<string | null>(null);

  const [inboxConversations, setInboxConversations] = useState<InboxConversationListItem[]>([]);
  const [inboxMeta, setInboxMeta] = useState<InboxConversationsResponse['meta']>({ page: 1, per_page: 20, total: 0, last_page: 1 });
  const [inboxPage, setInboxPage] = useState(1);
  const [inboxLoading, setInboxLoading] = useState(false);
  const [inboxError, setInboxError] = useState<string | null>(null);
  const [selectedConversationId, setSelectedConversationId] = useState<number | null>(null);
  const [inboxDetail, setInboxDetail] = useState<InboxConversationDetail | null>(null);
  const [inboxDetailLoading, setInboxDetailLoading] = useState(false);
  const [inboxDetailError, setInboxDetailError] = useState<string | null>(null);
  const [inboxSearch, setInboxSearch] = useState('');
  const [inboxSearchInput, setInboxSearchInput] = useState('');
  const [inboxOwnerFilter, setInboxOwnerFilter] = useState<number | ''>('');
  const [inboxSourceFilter, setInboxSourceFilter] = useState('');
  const [inboxStageFilter, setInboxStageFilter] = useState<number | ''>('');
  const [inboxServiceWindowFilter, setInboxServiceWindowFilter] = useState<'' | 'true' | 'false'>('');
  const [inboxReplyBody, setInboxReplyBody] = useState('');
  const [inboxSendLoading, setInboxSendLoading] = useState(false);
  const [inboxSendError, setInboxSendError] = useState<string | null>(null);
  const [inboxSendSuccess, setInboxSendSuccess] = useState<string | null>(null);
  const [inboxDetailTab, setInboxDetailTab] = useState<'messages' | 'audit'>('messages');
  const [inboxEvents, setInboxEvents] = useState<InboxConversationEvent[]>([]);
  const [inboxEventsLoading, setInboxEventsLoading] = useState(false);
  const [inboxEventsError, setInboxEventsError] = useState<string | null>(null);
  const [inboxOwnerUpdateLoading, setInboxOwnerUpdateLoading] = useState(false);
  const [inboxOwnerUpdateError, setInboxOwnerUpdateError] = useState<string | null>(null);
  const [inboxOwnerUpdateSuccess, setInboxOwnerUpdateSuccess] = useState<string | null>(null);
  const [inboxOwnerSelection, setInboxOwnerSelection] = useState<number | ''>('');
  const [assignableUsers, setAssignableUsers] = useState<AssignableUser[]>([]);
  const [assignableUsersError, setAssignableUsersError] = useState<string | null>(null);

  const [pipelines, setPipelines] = useState<PipelineListItem[]>([]);
  const [selectedPipelineId, setSelectedPipelineId] = useState<number | null>(null);
  const [kanban, setKanban] = useState<PipelineKanban | null>(null);
  const [stageHistoryByLead, setStageHistoryByLead] = useState<Record<number, LeadStageHistoryItem[]>>({});
  const [historyLoadingLeadId, setHistoryLoadingLeadId] = useState<number | null>(null);
  const [draggingCard, setDraggingCard] = useState<{ leadId: number; fromColumnId: number } | null>(null);
  const [dragOverColumnId, setDragOverColumnId] = useState<number | null>(null);
  const [pressedCardId, setPressedCardId] = useState<number | null>(null);
  const [activeView, setActiveView] = useState<ActiveView>('dashboard');
  const [adminCompanies, setAdminCompanies] = useState<AdminCompanyListItem[]>([]);
  const [adminCompaniesLoading, setAdminCompaniesLoading] = useState(false);
  const [adminCompaniesError, setAdminCompaniesError] = useState<string | null>(null);
  const [adminCompanyCreateLoading, setAdminCompanyCreateLoading] = useState(false);
  const [adminCompanyCreateError, setAdminCompanyCreateError] = useState<string | null>(null);
  const [adminCompanyCreateSuccess, setAdminCompanyCreateSuccess] = useState<string | null>(null);
  const [adminCompanyTokenInfo, setAdminCompanyTokenInfo] = useState<{ configured: boolean; masked: string | null } | null>(null);
  const [whatsAppSettings, setWhatsAppSettings] = useState<WhatsAppSettings | null>(null);
  const [whatsAppLoading, setWhatsAppLoading] = useState(false);
  const [whatsAppSaving, setWhatsAppSaving] = useState(false);
  const [whatsAppError, setWhatsAppError] = useState<string | null>(null);
  const [whatsAppSuccess, setWhatsAppSuccess] = useState<string | null>(null);
  const [whatsAppForm, setWhatsAppForm] = useState<WhatsAppSettingsUpdateRequest>({
    provider: 'meta_cloud',
    phone_number: '',
    phone_number_id: '',
    business_account_id: '',
    access_token: '',
    webhook_verify_token: '',
  });
  const [adminForm, setAdminForm] = useState<AdminCompanyCreateRequest>({
    company: { name: '', slug: '' },
    admin_user: { name: '', email: '', password: '' },
    settings: {
      timezone: 'America/Sao_Paulo',
      workday_start_time: '08:00:00',
      workday_end_time: '18:00:00',
      lunch_start_time: '12:00:00',
      lunch_end_time: '13:00:00',
      working_days: [1, 2, 3, 4, 5],
      repeated_lead_window_days: 90,
      rescue_threshold_hours: 24,
      first_response_sla_minutes: 15,
      follow_up_sla_hours: 24,
      stale_conversation_hours: 48,
    },
  });

  const [loading, setLoading] = useState(false);
  const [kanbanLoading, setKanbanLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [kanbanError, setKanbanError] = useState<string | null>(null);
  const isPlatformAdmin = session?.user.role === 'platform_admin';

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
  const canManageWhatsAppSettings = canManageSource && !isPlatformAdmin;
  const canMoveStage = canManageSource;

  const columnNameById = useMemo(() => {
    const map: Record<number, string> = {};
    for (const column of kanban?.columns ?? []) map[column.id] = column.name;
    return map;
  }, [kanban]);

  const inboxOwnerOptions = useMemo(() => {
    const map = new Map<number, string>();
    if (canManageSource && assignableUsers.length > 0) {
      for (const user of assignableUsers) map.set(user.id, user.name);
    } else {
      for (const conv of inboxConversations) {
        if (conv.owner_user_id && conv.owner_name) map.set(conv.owner_user_id, conv.owner_name);
      }
    }
    return Array.from(map.entries())
      .map(([id, name]) => ({ id, name }))
      .sort((a, b) => a.name.localeCompare(b.name));
  }, [canManageSource, assignableUsers, inboxConversations]);

  const inboxAssignableOwnerOptions = useMemo(() => {
    const map = new Map<number, string>();
    if (canManageSource && assignableUsers.length > 0) {
      for (const user of assignableUsers) map.set(user.id, user.name);
    } else {
      for (const owner of inboxOwnerOptions) map.set(owner.id, owner.name);
      if (session?.user?.id && session.user.name) map.set(session.user.id, session.user.name);
      if (inboxDetail?.owner.owner_user_id && inboxDetail?.owner.owner_name) {
        map.set(inboxDetail.owner.owner_user_id, inboxDetail.owner.owner_name);
      }
    }
    return Array.from(map.entries())
      .map(([id, name]) => ({ id, name }))
      .sort((a, b) => a.name.localeCompare(b.name));
  }, [canManageSource, assignableUsers, inboxOwnerOptions, session, inboxDetail]);

  async function fetchInboxEvents(token: string, conversationId: number) {
    const events = await getInboxConversationEvents(token, conversationId);
    const sorted = [...events].sort((a, b) => {
      const occurredCompare = String(a.occurred_at).localeCompare(String(b.occurred_at));
      if (occurredCompare !== 0) return occurredCompare;
      return a.event_id - b.event_id;
    });
    setInboxEvents(sorted);
  }

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
      const [unknownData, recentData] = await Promise.all([getUnknownLeads(token), getRecentLeads(token)]);
      setUnknownLeads(unknownData);
      setRecentLeads(recentData);
    } else {
      setUnknownLeads([]);
      setRecentLeads([]);
    }
  }

  async function refreshKanban(token: string, pipelineId: number, silent = false) {
    if (!silent) {
      setKanbanLoading(true);
    }
    setKanbanError(null);
    try {
      const kanbanData = await getPipelineKanban(token, pipelineId);
      setKanban(kanbanData);
      setStageHistoryByLead({});
      setDraggingCard(null);
      setDragOverColumnId(null);
    } catch (err) {
      setKanban(null);
      setKanbanError(parseApiErrorMessage(err, 'Não foi possível carregar o Kanban.'));
      console.error(err);
    } finally {
      setKanbanLoading(false);
    }
  }

  async function refreshAdminCompanies(token: string) {
    setAdminCompaniesLoading(true);
    setAdminCompaniesError(null);
    try {
      const companies = await getAdminCompanies(token);
      setAdminCompanies(companies);
    } catch (err) {
      setAdminCompanies([]);
      setAdminCompaniesError(parseApiErrorMessage(err, 'Não foi possível carregar empresas SaaS.'));
    } finally {
      setAdminCompaniesLoading(false);
    }
  }

  async function refreshWhatsAppSettings(token: string) {
    setWhatsAppLoading(true);
    setWhatsAppError(null);
    try {
      const data = await getWhatsAppSettings(token);
      setWhatsAppSettings(data);
      setWhatsAppForm({
        provider: data.provider,
        phone_number: data.phone_number ?? '',
        phone_number_id: data.phone_number_id ?? '',
        business_account_id: data.business_account_id ?? '',
        access_token: '',
        webhook_verify_token: data.webhook_verify_token ?? '',
      });
    } catch (err) {
      setWhatsAppSettings(null);
      setWhatsAppError(parseApiErrorMessage(err, 'Não foi possível carregar a configuração do WhatsApp.'));
    } finally {
      setWhatsAppLoading(false);
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
      setContactsError(parseApiErrorMessage(err, 'Não foi possível carregar os contatos.'));
      console.error(err);
    } finally {
      setContactsLoading(false);
    }
  }

  async function refreshInboxConversations(token: string, page = 1, silent = false) {
    if (!silent) {
      setInboxLoading(true);
    }
    setInboxError(null);
    try {
      const response = await getInboxConversations(token, {
        page,
        per_page: inboxMeta.per_page,
        search: inboxSearch || undefined,
        owner_user_id: inboxOwnerFilter,
        source: inboxSourceFilter || undefined,
        stage_id: inboxStageFilter,
        service_window_open: inboxServiceWindowFilter || '',
      });

      setInboxConversations(response.data);
      setInboxMeta(response.meta);

      if (response.data.length === 0) {
        setSelectedConversationId(null);
        setInboxDetail(null);
        return;
      }

      const selectedStillExists = selectedConversationId
        ? response.data.some((item) => item.conversation_id === selectedConversationId)
        : false;

      if (!selectedStillExists) {
        setSelectedConversationId(response.data[0].conversation_id);
      }
    } catch (err) {
      setInboxConversations([]);
      setInboxError(parseApiErrorMessage(err, 'Não foi possível carregar a Inbox.'));
      console.error(err);
    } finally {
      setInboxLoading(false);
    }
  }

  async function refreshInboxDetail(token: string, conversationId: number, silent = false) {
    if (!silent) {
      setInboxDetailLoading(true);
    }
    setInboxDetailError(null);
    try {
      const detail = await getInboxConversationDetail(token, conversationId);
      setInboxDetail(detail);
    } catch (err) {
      setInboxDetail(null);
      setInboxDetailError(parseApiErrorMessage(err, 'Não foi possível carregar o histórico da conversa.'));
      console.error(err);
    } finally {
      setInboxDetailLoading(false);
    }
  }

  function parseApiErrorMessage(error: unknown, fallback: string): string {
    if (!(error instanceof Error) || !error.message) return fallback;

    const rawMessage = error.message.toLowerCase();
    if (rawMessage.includes('failed to fetch') || rawMessage.includes('networkerror') || rawMessage.includes('network error')) {
      return 'Não foi possível conectar à API. Verifique se o backend está em execução.';
    }

    let apiMessage: string | null = null;
    let status: number | null = null;

    try {
      const parsed = JSON.parse(error.message) as { message?: string; errors?: Record<string, string[]>; status?: number };
      if (parsed?.message && typeof parsed.message === 'string') apiMessage = parsed.message;
      if (parsed?.status && Number.isFinite(parsed.status)) status = Number(parsed.status);
    } catch {
      const statusMatch = error.message.match(/(?:Erro HTTP|HTTP)\s*(\d{3})/i);
      if (statusMatch) status = Number(statusMatch[1]);
    }

    if (status === 401) return 'Sessão expirada ou inválida. Faça login novamente.';
    if (status === 403) return 'Você não tem permissão para acessar esta área ou executar esta ação.';
    if (status === 404) return 'Recurso não encontrado ou indisponível para sua empresa.';
    if (status === 422) return apiMessage || 'Dados inválidos. Revise as informações e tente novamente.';
    if (status === 500) return 'Erro interno da API. Tente novamente ou acione o suporte.';

    return apiMessage || fallback;
  }

  useEffect(() => {
    if (!session) return;
    if (isPlatformAdmin) return;

    setLoading(true);
    setError(null);
    setChecklistLoading(true);
    setChecklistError(null);

    refreshData(session.token)
      .catch((err) => {
        setError(parseApiErrorMessage(err, 'Falha ao carregar dados da API.'));
        setChecklistError(parseApiErrorMessage(err, 'Não foi possível carregar o checklist.'));
        console.error(err);
      })
      .finally(() => {
        setLoading(false);
        setChecklistLoading(false);
      });
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [session, isPlatformAdmin]);

  useEffect(() => {
    if (!session || !canManageWhatsAppSettings || activeView !== 'whatsappSettings') return;
    refreshWhatsAppSettings(session.token).catch((err) => console.error(err));
  }, [session, canManageWhatsAppSettings, activeView]);

  useEffect(() => {
    if (!session || !selectedPipelineId || isPlatformAdmin) return;
    refreshKanban(session.token, selectedPipelineId).catch((err) => console.error(err));
  }, [session, selectedPipelineId, isPlatformAdmin]);

  useEffect(() => {
    if (!session || isPlatformAdmin) return;
    refreshContacts(session.token, contactsPage).catch((err) => console.error(err));
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [session, contactsPage, contactSearch, contactSourceFilter, contactClassificationFilter, contactStageFilter, isPlatformAdmin]);

  useEffect(() => {
    if (!session || isPlatformAdmin) return;
    refreshInboxConversations(session.token, inboxPage).catch((err) => console.error(err));
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [session, inboxPage, inboxSearch, inboxOwnerFilter, inboxSourceFilter, inboxStageFilter, inboxServiceWindowFilter, isPlatformAdmin]);

  useEffect(() => {
    if (!session || !canManageSource || isPlatformAdmin) {
      setAssignableUsers([]);
      setAssignableUsersError(null);
      return;
    }

    setAssignableUsersError(null);
    getAssignableUsers(session.token)
      .then((response) => {
        setAssignableUsers(response.data);
      })
      .catch((err: unknown) => {
        setAssignableUsers([]);
        setAssignableUsersError(parseApiErrorMessage(err, 'Não foi possível carregar usuários atribuíveis.'));
      });
  }, [session, canManageSource, isPlatformAdmin]);

  useEffect(() => {
    if (!session || !selectedConversationId || isPlatformAdmin) return;
    setInboxSendError(null);
    setInboxSendSuccess(null);
    setInboxOwnerUpdateError(null);
    setInboxOwnerUpdateSuccess(null);
    setInboxOwnerSelection('');
    setInboxDetailTab('messages');
    setInboxEvents([]);
    setInboxEventsError(null);
    setInboxEventsLoading(false);
    refreshInboxDetail(session.token, selectedConversationId).catch((err) => console.error(err));
  }, [session, selectedConversationId, isPlatformAdmin]);

  useEffect(() => {
    if (!session || isPlatformAdmin) return;

    const interval = setInterval(() => {
      if (activeView === 'inbox') {
        void refreshInboxConversations(session.token, inboxPage, true).catch((err) => console.error(err));
        if (selectedConversationId) {
          void refreshInboxDetail(session.token, selectedConversationId, true).catch((err) => console.error(err));
        }
      } else if (activeView === 'kanban' && selectedPipelineId) {
        void refreshKanban(session.token, selectedPipelineId, true).catch((err) => console.error(err));
      } else if (activeView === 'dashboard') {
        void refreshData(session.token).catch((err) => console.error(err));
      }
    }, 5000);

    return () => clearInterval(interval);
  }, [session, activeView, inboxPage, selectedConversationId, selectedPipelineId, isPlatformAdmin]);

  useEffect(() => {
    if (!session || !selectedConversationId || inboxDetailTab !== 'audit' || isPlatformAdmin) return;
    if (inboxEventsLoading || inboxEvents.length > 0 || inboxEventsError) return;

    setInboxEventsLoading(true);
    setInboxEventsError(null);

    fetchInboxEvents(session.token, selectedConversationId)
      .catch((err: unknown) => {
        const message = parseApiErrorMessage(err, 'Não foi possível carregar a auditoria da conversa.');
        const statusMatch = err instanceof Error ? err.message.match(/Erro HTTP (\d{3})/) : null;
        const status = statusMatch ? Number(statusMatch[1]) : null;
        if (status === 403) {
          setInboxEventsError('Você não tem permissão para visualizar a auditoria desta conversa.');
          return;
        }
        if (status === 404) {
          setInboxEventsError('Conversa não encontrada ou indisponível para o seu perfil.');
          return;
        }
        setInboxEventsError(message);
      })
      .finally(() => {
        setInboxEventsLoading(false);
      });
  }, [session, selectedConversationId, inboxDetailTab, inboxEventsLoading, inboxEvents.length, inboxEventsError, isPlatformAdmin]);

  useEffect(() => {
    if (!session) return;
    setActiveView(session.user.role === 'platform_admin' ? 'adminSaas' : 'dashboard');
  }, [session]);

  useEffect(() => {
    if (!session || !isPlatformAdmin) return;
    refreshAdminCompanies(session.token).catch((err) => console.error(err));
  }, [session, isPlatformAdmin]);

  useEffect(() => {
    const allowedViews: ActiveView[] = isPlatformAdmin
      ? ['adminSaas']
      : [
        'dashboard',
        'inbox',
        'checklist',
        'kanban',
        'contacts',
        ...(canManageWhatsAppSettings ? (['whatsappSettings'] as ActiveView[]) : []),
      ];

    if (!allowedViews.includes(activeView)) {
      setActiveView(allowedViews[0]);
    }
  }, [activeView, canManageWhatsAppSettings, isPlatformAdmin]);

  useEffect(() => {
    if (!inboxDetail) {
      setInboxOwnerSelection('');
      return;
    }
    setInboxOwnerSelection(inboxDetail.owner.owner_user_id ?? '');
  }, [inboxDetail]);

  async function handleLogin(e: React.FormEvent) {
    e.preventDefault();
    setLoading(true);
    setError(null);

    try {
      const data = await login(email, password);
      const newSession: Session = { token: data.token, user: data.user };
      setSession(newSession);
      localStorage.setItem(STORAGE_KEY, JSON.stringify(newSession));
    } catch (err) {
      setError(parseApiErrorMessage(err, 'Credenciais inválidas ou API indisponível.'));
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
    setInboxConversations([]);
    setInboxDetail(null);
    setSelectedConversationId(null);
    setInboxReplyBody('');
    setInboxSendLoading(false);
    setInboxSendError(null);
    setInboxSendSuccess(null);
    setInboxDetailTab('messages');
    setInboxEvents([]);
    setInboxEventsLoading(false);
    setInboxEventsError(null);
    setInboxOwnerUpdateLoading(false);
    setInboxOwnerUpdateError(null);
    setInboxOwnerUpdateSuccess(null);
    setInboxOwnerSelection('');
    setAssignableUsers([]);
    setAssignableUsersError(null);
    setStageHistoryByLead({});
    setDraggingCard(null);
    setDragOverColumnId(null);
    setAdminCompanies([]);
    setAdminCompaniesLoading(false);
    setAdminCompaniesError(null);
    setAdminCompanyCreateLoading(false);
    setAdminCompanyCreateError(null);
    setAdminCompanyCreateSuccess(null);
    setAdminCompanyTokenInfo(null);
    setWhatsAppSettings(null);
    setWhatsAppLoading(false);
    setWhatsAppSaving(false);
    setWhatsAppError(null);
    setWhatsAppSuccess(null);
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
      setKanbanError(parseApiErrorMessage(err, 'Não foi possível mover o card.'));
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
      setKanbanError(parseApiErrorMessage(err, 'Não foi possível carregar o histórico da etapa.'));
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

  async function applyInboxFilters(event: React.FormEvent) {
    event.preventDefault();
    setInboxSearch(inboxSearchInput.trim());
    setInboxPage(1);
  }

  function changeInboxPage(nextPage: number) {
    if (nextPage < 1 || nextPage > inboxMeta.last_page) return;
    setInboxPage(nextPage);
  }

  async function handleInboxSendMessage(event: React.FormEvent) {
    event.preventDefault();
    if (!session || !selectedConversationId || !inboxDetail || inboxSendLoading) return;

    setInboxSendError(null);
    setInboxSendSuccess(null);

    if (!inboxDetail.service_window_open) {
      setInboxSendError('Janela de atendimento fechada. Aguarde nova mensagem inbound para enviar.');
      return;
    }

    const body = inboxReplyBody.trim();
    if (!body) {
      setInboxSendError('Digite uma mensagem antes de enviar.');
      return;
    }

    setInboxSendLoading(true);
    try {
      await sendInboxMessage(session.token, selectedConversationId, body);
      setInboxReplyBody('');
      setInboxSendSuccess('Mensagem enviada com sucesso.');
      await refreshInboxDetail(session.token, selectedConversationId);
      await refreshInboxConversations(session.token, inboxPage);
    } catch (err) {
      setInboxSendError(parseApiErrorMessage(err, 'Não foi possível enviar a mensagem.'));
      console.error(err);
    } finally {
      setInboxSendLoading(false);
    }
  }

  async function handleUpdateInboxOwner(event: React.FormEvent) {
    event.preventDefault();
    if (!session || !inboxDetail || inboxOwnerUpdateLoading) return;

    setInboxOwnerUpdateError(null);
    setInboxOwnerUpdateSuccess(null);
    setInboxOwnerUpdateLoading(true);

    try {
      const ownerUserId = inboxOwnerSelection === '' ? null : inboxOwnerSelection;
      await updateLeadOwner(session.token, inboxDetail.lead.lead_id, ownerUserId, 'Alterado pela Inbox');
      setInboxOwnerUpdateSuccess('Responsável atualizado com sucesso.');
      await refreshInboxDetail(session.token, inboxDetail.conversation_id);
      await refreshInboxConversations(session.token, inboxPage);

      if (inboxDetailTab === 'audit') {
        setInboxEvents([]);
        setInboxEventsError(null);
        setInboxEventsLoading(true);
        try {
          await fetchInboxEvents(session.token, inboxDetail.conversation_id);
        } catch (err: unknown) {
          setInboxEventsError(parseApiErrorMessage(err, 'Não foi possível recarregar a auditoria da conversa.'));
        } finally {
          setInboxEventsLoading(false);
        }
      }
    } catch (err) {
      setInboxOwnerUpdateError(parseApiErrorMessage(err, 'Não foi possível atualizar o responsável do lead.'));
      console.error(err);
    } finally {
      setInboxOwnerUpdateLoading(false);
    }
  }

  async function handleExportContactsCsv() {
    if (!session || !canManageSource) return;
    setContactsExportLoading(true);
    setContactsExportError(null);
    setContactsExportSuccess(null);

    try {
      const csvBlob = await exportContactsCsv(session.token, {
        search: contactSearch || undefined,
        source: contactSourceFilter || undefined,
        classification: contactClassificationFilter || '',
        stage_id: contactStageFilter,
      });

      const fileUrl = URL.createObjectURL(csvBlob);
      const link = document.createElement('a');
      link.href = fileUrl;
      link.download = 'contacts-export.csv';
      document.body.appendChild(link);
      link.click();
      link.remove();
      URL.revokeObjectURL(fileUrl);

      setContactsExportSuccess('Download iniciado.');
    } catch (err) {
      console.error(err);
      setContactsExportError(parseApiErrorMessage(err, 'Não foi possível exportar os contatos em CSV.'));
    } finally {
      setContactsExportLoading(false);
    }
  }

  function updateAdminForm(path: string, value: string | number) {
    setAdminForm((prev) => {
      const next: AdminCompanyCreateRequest = {
        company: { ...prev.company },
        admin_user: { ...prev.admin_user },
        settings: { ...prev.settings },
      };
      const [group, field] = path.split('.') as ['company' | 'admin_user' | 'settings', string];
      (next[group] as Record<string, string | number>)[field] = value;
      return next;
    });
  }

  function getAdminFormValidationError(): string | null {
    if (!adminForm.company.name.trim()) return 'Informe o nome da empresa.';
    if (!adminForm.company.slug.trim()) return 'Informe o slug da empresa.';
    if (!adminForm.admin_user.name.trim()) return 'Informe o nome do admin inicial.';
    if (!adminForm.admin_user.email.trim()) return 'Informe o email do admin inicial.';
    if (!adminForm.admin_user.password.trim()) return 'Informe a senha do admin inicial.';
    if (adminForm.settings.repeated_lead_window_days < 1) return 'Janela de lead repetido deve ser maior que zero.';
    if (adminForm.settings.rescue_threshold_hours < 1) return 'Threshold de resgate deve ser maior que zero.';
    if (adminForm.settings.first_response_sla_minutes < 1) return 'SLA de primeira resposta deve ser maior que zero.';
    if (adminForm.settings.follow_up_sla_hours < 1) return 'SLA de follow-up deve ser maior que zero.';
    if (adminForm.settings.stale_conversation_hours < 1) return 'Conversa estagnada deve ser maior que zero.';
    return null;
  }

  async function handleCreateAdminCompany(event: React.FormEvent) {
    event.preventDefault();
    if (!session || !isPlatformAdmin) return;

    const validationError = getAdminFormValidationError();
    if (validationError) {
      setAdminCompanyCreateError(validationError);
      return;
    }

    setAdminCompanyCreateLoading(true);
    setAdminCompanyCreateError(null);
    setAdminCompanyCreateSuccess(null);
    setAdminCompanyTokenInfo(null);

    try {
      const payload: AdminCompanyCreateRequest = {
        ...adminForm,
        company: {
          name: adminForm.company.name.trim(),
          slug: adminForm.company.slug.trim(),
        },
        admin_user: {
          name: adminForm.admin_user.name.trim(),
          email: adminForm.admin_user.email.trim(),
          password: adminForm.admin_user.password,
        },
      };
      const response = await createAdminCompany(session.token, payload);
      setAdminCompanyCreateSuccess(response.message || 'Empresa criada com sucesso.');

      const configured = response.data?.webhook_token_configured ?? response.webhook_token_configured ?? false;
      const masked = response.data?.masked_webhook_token ?? response.masked_webhook_token ?? null;
      setAdminCompanyTokenInfo({ configured, masked });

      setAdminForm((prev) => ({
        ...prev,
        company: { name: '', slug: '' },
        admin_user: { name: '', email: '', password: '' },
      }));

      await refreshAdminCompanies(session.token);
    } catch (err) {
      setAdminCompanyCreateError(parseApiErrorMessage(err, 'Não foi possível criar a empresa.'));
    } finally {
      setAdminCompanyCreateLoading(false);
    }
  }

  function updateWhatsAppForm(path: keyof WhatsAppSettingsUpdateRequest, value: string) {
    setWhatsAppForm((prev) => ({ ...prev, [path]: value }));
  }

  async function handleSaveWhatsAppSettings(event: React.FormEvent) {
    event.preventDefault();
    if (!session || !canManageWhatsAppSettings) return;
    if (!whatsAppForm.provider) {
      setWhatsAppError('Provider é obrigatório.');
      return;
    }

    setWhatsAppSaving(true);
    setWhatsAppError(null);
    setWhatsAppSuccess(null);
    try {
      const payload: WhatsAppSettingsUpdateRequest = {
        provider: 'meta_cloud',
        phone_number: whatsAppForm.phone_number?.trim() || null,
        phone_number_id: whatsAppForm.phone_number_id?.trim() || null,
        business_account_id: whatsAppForm.business_account_id?.trim() || null,
      };

      if ((whatsAppForm.access_token ?? '').trim()) {
        payload.access_token = (whatsAppForm.access_token ?? '').trim();
      }
      if ((whatsAppForm.webhook_verify_token ?? '').trim()) {
        payload.webhook_verify_token = (whatsAppForm.webhook_verify_token ?? '').trim();
      }

      await updateWhatsAppSettings(session.token, payload);
      await refreshWhatsAppSettings(session.token);
      await refreshData(session.token);
      setWhatsAppSuccess('Configuração do WhatsApp salva com sucesso.');
    } catch (err) {
      setWhatsAppError(parseApiErrorMessage(err, 'Não foi possível salvar a configuração do WhatsApp.'));
    } finally {
      setWhatsAppSaving(false);
    }
  }

  if (!session) {
    return (
      <main className="lw-login-shell">
        <Card className="lw-login-card">
          <PageHeader title="LEADSWHATS" subtitle="Login para acessar o dashboard inicial." />
          <form onSubmit={handleLogin} style={{ display: 'grid', gap: 12 }}>
            <FormGroup label="Email">
              <Input value={email} onChange={(e) => setEmail(e.target.value)} placeholder="Email" />
            </FormGroup>
            <FormGroup label="Senha">
              <Input type="password" value={password} onChange={(e) => setPassword(e.target.value)} placeholder="Senha" />
            </FormGroup>
            <Button type="submit" disabled={loading}>{loading ? 'Entrando...' : 'Entrar'}</Button>
          </form>
          {error ? <ErrorState message={error} /> : null}
        </Card>
      </main>
    );
  }

  const dashboardMetrics = dashboard ? [
    { label: 'Leads novos hoje', value: dashboard.metrics.new_leads_today },
    { label: 'Leads repetidos hoje', value: dashboard.metrics.repeat_leads_today },
    { label: 'Tempo médio 1ª resposta', value: formatSeconds(dashboard.metrics.avg_first_response_seconds) },
    { label: 'Leads em vácuo (+24h)', value: dashboard.metrics.vacuum_24h_open },
    { label: 'Resgates hoje', value: dashboard.metrics.rescues_today },
    { label: 'Conversas ativas', value: dashboard.metrics.active_conversations },
    { label: 'Origem desconhecida', value: dashboard.metrics.unknown_source_leads },
    { label: 'Classificações hoje', value: dashboard.metrics.manual_classifications_today },
    { label: 'Tarefas abertas', value: dashboard.metrics.open_tasks },
    { label: 'Follow-up em vácuo', value: dashboard.metrics.vacuum_follow_up_tasks },
    { label: '1º atendimento atrasado', value: dashboard.metrics.waiting_first_response_tasks },
    { label: 'Follow-up atrasado', value: dashboard.metrics.overdue_follow_up_tasks },
    { label: 'Leads sem responsável', value: dashboard.metrics.unassigned_leads },
    { label: 'Maior atraso (h)', value: dashboard.metrics.oldest_pending_task_hours },
  ] : [];
  const riskMetricLabels = new Set([
    'Tarefas abertas',
    '1º atendimento atrasado',
    'Follow-up atrasado',
    'Leads sem responsável',
  ]);
  const navSections: Array<{ id: ActiveView; label: string; subtitle: string }> = isPlatformAdmin
    ? [{ id: 'adminSaas', label: 'Admin SaaS', subtitle: 'Gerencie empresas clientes e acessos iniciais' }]
    : [
      { id: 'dashboard', label: 'Dashboard', subtitle: 'Visão geral e métricas' },
      { id: 'inbox', label: 'Inbox', subtitle: 'Atendimento e auditoria' },
      { id: 'checklist', label: 'Checklist', subtitle: 'Tarefas operacionais' },
      { id: 'kanban', label: 'Kanban', subtitle: 'Pipeline e movimentação' },
      { id: 'contacts', label: 'Contatos', subtitle: 'Busca e exportação' },
      ...(canManageWhatsAppSettings ? [{ id: 'whatsappSettings' as ActiveView, label: 'WhatsApp', subtitle: 'Configuração da integração da empresa' }] : []),
    ];
  const activeNav = navSections.find((item) => item.id === activeView) ?? navSections[0];
  const adminSummary = {
    total: adminCompanies.length,
    withSettings: adminCompanies.filter((item) => item.has_business_settings).length,
    withPipelines: adminCompanies.filter((item) => item.pipelines_count > 0).length,
  };

  return (
    <AppShell>
      <div className="lw-shell">
        <Sidebar>
          <div className="lw-side-brand">
            <p className="lw-side-title">LEADSWHATS</p>
            <small className="lw-side-subtitle">Revenue Intelligence</small>
          </div>
          <nav className="lw-side-nav" aria-label="Navegação local">
            {navSections.map((item) => (
              <button
                key={item.id}
                type="button"
                className={`lw-side-link ${activeView === item.id ? 'lw-side-link--active' : ''}`}
                onClick={() => setActiveView(item.id)}
              >
                {item.label}
              </button>
            ))}
          </nav>
          <div className="lw-side-user">
            <small>{session.user.name}</small>
            <Badge variant="info">{session.user.role}</Badge>
          </div>
        </Sidebar>

        <div className={`lw-shell-main ${activeView === 'kanban' ? 'lw-shell-main--kanban' : ''}`}>
          <Topbar
            left={(
              <PageHeader
                title={activeNav.label}
                subtitle={overview ? `${activeNav.subtitle} · ${overview.company.name} (${overview.company.slug})` : activeNav.subtitle}
              />
            )}
            right={<Button onClick={logout}>Sair</Button>}
          />

          {loading ? <LoadingState message="Carregando dados..." /> : null}
          {error ? <ErrorState message={error} /> : null}

          {!isPlatformAdmin && overview && overview.whatsapp_status !== 'configured' && activeView !== 'whatsappSettings' ? (
            <Section style={{ display: 'flex', justifyContent: 'center', alignItems: 'center', minHeight: '60vh', padding: 24 }}>
              <Card style={{ maxWidth: 500, textAlign: 'center', padding: 32, border: '1px solid #fecaca', background: '#fef2f2', borderRadius: 8, boxShadow: '0 4px 6px -1px rgba(0, 0, 0, 0.05)' }}>
                <div style={{ color: '#dc2626', fontSize: 48, marginBottom: 16 }}>⚠️</div>
                <h3 style={{ color: '#dc2626', marginTop: 0, fontSize: 20 }}>WhatsApp Desconectado</h3>
                <p style={{ color: '#7f1d1d', margin: '12px 0 24px', fontSize: 15, lineHeight: 1.6 }}>
                  O sistema de atendimento, Kanban e relatórios do LeadsWhats está bloqueado porque o número de WhatsApp da empresa não está conectado e ativado.
                </p>
                {canManageWhatsAppSettings ? (
                  <div>
                    <Button onClick={() => setActiveView('whatsappSettings')} style={{ background: '#dc2626', borderColor: '#dc2626', color: '#fff', fontWeight: 'bold' }}>
                      Ir para Configurações do WhatsApp
                    </Button>
                  </div>
                ) : (
                  <p style={{ color: '#991b1b', fontSize: 13, fontWeight: 'bold', margin: 0 }}>
                    Por favor, entre em contato com seu gestor ou administrador para ativar a integração do WhatsApp da empresa.
                  </p>
                )}
              </Card>
            </Section>
          ) : (
            <>
              {activeView === 'adminSaas' ? (
            <>
              <Section>
                <PageHeader title="Admin SaaS" subtitle="Gerencie empresas clientes e acessos iniciais" />
                <div className="lw-metrics-grid" style={{ marginTop: 12 }}>
                  <MetricCard label="Total de empresas" value={adminSummary.total} />
                  <MetricCard label="Empresas com settings" value={adminSummary.withSettings} />
                  <MetricCard label="Empresas com pipeline" value={adminSummary.withPipelines} />
                </div>
              </Section>

              <Section>
                <h2 style={{ marginTop: 0 }}>Criar empresa cliente</h2>
                <form onSubmit={(event) => void handleCreateAdminCompany(event)} className="lw-admin-form">
                  <Card>
                    <h3 style={{ marginTop: 0 }}>Empresa</h3>
                    <div className="lw-admin-grid">
                      <FormGroup label="Nome da empresa">
                        <Input value={adminForm.company.name} onChange={(e) => updateAdminForm('company.name', e.target.value)} placeholder="Empresa Exemplo Ltda" />
                      </FormGroup>
                      <FormGroup label="Slug">
                        <Input value={adminForm.company.slug} onChange={(e) => updateAdminForm('company.slug', e.target.value)} placeholder="empresa-exemplo" />
                      </FormGroup>
                    </div>
                  </Card>

                  <Card>
                    <h3 style={{ marginTop: 0 }}>Admin inicial</h3>
                    <div className="lw-admin-grid">
                      <FormGroup label="Nome">
                        <Input value={adminForm.admin_user.name} onChange={(e) => updateAdminForm('admin_user.name', e.target.value)} placeholder="Nome do admin" />
                      </FormGroup>
                      <FormGroup label="Email">
                        <Input type="email" value={adminForm.admin_user.email} onChange={(e) => updateAdminForm('admin_user.email', e.target.value)} placeholder="admin@empresa.com" />
                      </FormGroup>
                      <FormGroup label="Senha">
                        <Input type="password" value={adminForm.admin_user.password} onChange={(e) => updateAdminForm('admin_user.password', e.target.value)} placeholder="Senha temporária" />
                      </FormGroup>
                    </div>
                  </Card>

                  <Card>
                    <h3 style={{ marginTop: 0 }}>Configurações operacionais</h3>
                    <div className="lw-admin-grid">
                      <FormGroup label="Timezone">
                        <Input value={adminForm.settings.timezone} onChange={(e) => updateAdminForm('settings.timezone', e.target.value)} />
                      </FormGroup>
                      <FormGroup label="Início expediente">
                        <Input value={adminForm.settings.workday_start_time} onChange={(e) => updateAdminForm('settings.workday_start_time', e.target.value)} />
                      </FormGroup>
                      <FormGroup label="Fim expediente">
                        <Input value={adminForm.settings.workday_end_time} onChange={(e) => updateAdminForm('settings.workday_end_time', e.target.value)} />
                      </FormGroup>
                      <FormGroup label="Início almoço">
                        <Input value={adminForm.settings.lunch_start_time} onChange={(e) => updateAdminForm('settings.lunch_start_time', e.target.value)} />
                      </FormGroup>
                      <FormGroup label="Fim almoço">
                        <Input value={adminForm.settings.lunch_end_time} onChange={(e) => updateAdminForm('settings.lunch_end_time', e.target.value)} />
                      </FormGroup>
                      <FormGroup label="Dias úteis padrão">
                        <Input value="Segunda a sexta (1,2,3,4,5)" disabled />
                      </FormGroup>
                      <FormGroup label="Janela lead repetido (dias)">
                        <Input type="number" min={1} value={adminForm.settings.repeated_lead_window_days} onChange={(e) => updateAdminForm('settings.repeated_lead_window_days', Number(e.target.value || 0))} />
                      </FormGroup>
                      <FormGroup label="Threshold resgate (h)">
                        <Input type="number" min={1} value={adminForm.settings.rescue_threshold_hours} onChange={(e) => updateAdminForm('settings.rescue_threshold_hours', Number(e.target.value || 0))} />
                      </FormGroup>
                      <FormGroup label="SLA primeira resposta (min)">
                        <Input type="number" min={1} value={adminForm.settings.first_response_sla_minutes} onChange={(e) => updateAdminForm('settings.first_response_sla_minutes', Number(e.target.value || 0))} />
                      </FormGroup>
                      <FormGroup label="SLA follow-up (h)">
                        <Input type="number" min={1} value={adminForm.settings.follow_up_sla_hours} onChange={(e) => updateAdminForm('settings.follow_up_sla_hours', Number(e.target.value || 0))} />
                      </FormGroup>
                      <FormGroup label="Conversa estagnada (h)">
                        <Input type="number" min={1} value={adminForm.settings.stale_conversation_hours} onChange={(e) => updateAdminForm('settings.stale_conversation_hours', Number(e.target.value || 0))} />
                      </FormGroup>
                    </div>
                  </Card>

                  {adminCompanyCreateError ? <ErrorState message={adminCompanyCreateError} /> : null}
                  {adminCompanyCreateSuccess ? <Alert variant="success">{adminCompanyCreateSuccess}</Alert> : null}
                  {adminCompanyTokenInfo ? (
                    <Alert variant="info">
                      webhook_token_configured: {String(adminCompanyTokenInfo.configured)} · masked_webhook_token: {adminCompanyTokenInfo.masked || 'n/d'}
                    </Alert>
                  ) : null}
                  <div>
                    <Button type="submit" disabled={adminCompanyCreateLoading}>
                      {adminCompanyCreateLoading ? 'Criando empresa...' : 'Criar empresa'}
                    </Button>
                  </div>
                </form>
              </Section>

              <Section>
                <h2 style={{ marginTop: 0 }}>Empresas clientes</h2>
                {adminCompaniesLoading ? <LoadingState message="Carregando empresas..." /> : null}
                {adminCompaniesError ? <ErrorState message={adminCompaniesError} /> : null}
                {!adminCompaniesLoading && !adminCompaniesError && adminCompanies.length === 0 ? (
                  <EmptyState title="Nenhuma empresa cadastrada." description="Crie a primeira empresa usando o formulário acima." />
                ) : null}
                {!adminCompaniesLoading && !adminCompaniesError && adminCompanies.length > 0 ? (
                  <Table>
                    <thead>
                      <tr>
                        <th>ID</th>
                        <th>Name</th>
                        <th>Slug</th>
                        <th>Users</th>
                        <th>Pipelines</th>
                        <th>Business Settings</th>
                        <th>Criada em</th>
                      </tr>
                    </thead>
                    <tbody>
                      {adminCompanies.map((company) => (
                        <tr key={company.id}>
                          <td>{company.id}</td>
                          <td>{company.name}</td>
                          <td>{company.slug}</td>
                          <td>{company.users_count}</td>
                          <td>{company.pipelines_count}</td>
                          <td><Badge variant={company.has_business_settings ? 'success' : 'warning'}>{company.has_business_settings ? 'Sim' : 'Não'}</Badge></td>
                          <td>{formatDateTime(company.created_at)}</td>
                        </tr>
                      ))}
                    </tbody>
                  </Table>
                ) : null}
              </Section>
            </>
          ) : null}

          {activeView === 'whatsappSettings' && canManageWhatsAppSettings ? (
            <>
              <Section>
                <PageHeader title="Configuração WhatsApp" subtitle="Configure o canal de atendimento da empresa" />
                <div className="lw-metrics-grid" style={{ marginTop: 12 }}>
                  <MetricCard
                    label="Status da integração"
                    value={whatsAppSettings?.status ?? 'not_configured'}
                    variant={whatsAppSettings?.status === 'configured' ? 'default' : 'risk'}
                  />
                  <MetricCard label="Provider" value={whatsAppSettings?.provider ?? 'meta_cloud'} />
                  <MetricCard
                    label="Token de acesso configurado"
                    value={whatsAppSettings?.access_token_configured ? 'Sim' : 'Não'}
                    variant={whatsAppSettings?.access_token_configured ? 'default' : 'risk'}
                  />
                  <MetricCard
                    label="Verify token configurado"
                    value={whatsAppSettings?.webhook_verify_token_configured ? 'Sim' : 'Não'}
                    variant={whatsAppSettings?.webhook_verify_token_configured ? 'default' : 'risk'}
                  />
                </div>
              </Section>

              <Section>
                {whatsAppLoading ? <LoadingState message="Carregando configurações do WhatsApp..." /> : null}
                {whatsAppError ? <ErrorState message={whatsAppError} /> : null}
                {whatsAppSuccess ? <Alert variant="success">{whatsAppSuccess}</Alert> : null}
                {!whatsAppLoading && whatsAppSettings?.status === 'not_configured' ? (
                  <Alert variant="warning">Configuração incompleta. Preencha os campos necessários para ativar a integração.</Alert>
                ) : null}
                {!whatsAppLoading && whatsAppSettings?.status === 'configured' ? (
                  <Alert variant="success">Integração configurada com sucesso para esta empresa.</Alert>
                ) : null}
                {!whatsAppLoading && whatsAppSettings?.status === 'error' ? (
                  <Alert variant="danger">Erro de integração: {whatsAppSettings.last_error || 'sem detalhes fornecidos.'}</Alert>
                ) : null}

                {!whatsAppLoading && whatsAppSettings ? (
                  <Card style={{ marginBottom: 20, border: '1px dashed #4f46e5', background: '#f5f3ff', padding: 16 }}>
                    <h3 style={{ marginTop: 0, color: '#4f46e5' }}>Configurações do Webhook na Meta</h3>
                    <p style={{ margin: '4px 0', fontSize: 14, color: '#555' }}>
                      Para receber as mensagens do WhatsApp em tempo real, configure estes dados no painel de desenvolvedor da Meta (WhatsApp &gt; Configuração &gt; Webhook):
                    </p>
                    <div style={{ display: 'grid', gap: 12, marginTop: 12 }}>
                      <div>
                        <strong style={{ fontSize: 13, display: 'block', marginBottom: 4 }}>URL de Retorno (Callback URL):</strong>
                        <div style={{ display: 'flex', gap: 8 }}>
                          <input
                            readOnly
                            value={`${window.location.origin}/api/v1/webhooks/whatsapp/meta`}
                            style={{ flex: 1, padding: '8px 12px', fontSize: 13, background: '#fff', border: '1px solid #ddd', borderRadius: 4 }}
                          />
                          <button
                            type="button"
                            onClick={() => {
                              navigator.clipboard.writeText(`${window.location.origin}/api/v1/webhooks/whatsapp/meta`);
                              alert('URL do Webhook copiada!');
                            }}
                            style={{ padding: '8px 16px', fontSize: 13, cursor: 'pointer', background: '#4f46e5', color: '#fff', border: 'none', borderRadius: 4, fontWeight: 'bold' }}
                          >
                            Copiar URL
                          </button>
                        </div>
                      </div>
                      <div>
                        <strong style={{ fontSize: 13, display: 'block', marginBottom: 4 }}>Token de Verificação (Verify Token):</strong>
                        <div style={{ display: 'flex', gap: 8 }}>
                          <input
                            readOnly
                            value={whatsAppSettings?.webhook_verify_token || 'Ainda não gerado (salve as credenciais primeiro)'}
                            style={{ flex: 1, padding: '8px 12px', fontSize: 13, background: '#fff', border: '1px solid #ddd', borderRadius: 4 }}
                          />
                          <button
                            type="button"
                            disabled={!whatsAppSettings?.webhook_verify_token}
                            onClick={() => {
                              if (whatsAppSettings?.webhook_verify_token) {
                                navigator.clipboard.writeText(whatsAppSettings.webhook_verify_token);
                                alert('Token de Verificação copiado!');
                              }
                            }}
                            style={{ padding: '8px 16px', fontSize: 13, cursor: 'pointer', background: '#4f46e5', color: '#fff', border: 'none', borderRadius: 4, fontWeight: 'bold' }}
                          >
                            Copiar Token
                          </button>
                        </div>
                      </div>
                    </div>
                  </Card>
                ) : null}

                {!whatsAppLoading ? (
                  <form onSubmit={(event) => void handleSaveWhatsAppSettings(event)} className="lw-admin-form">
                    <Card>
                      <div className="lw-admin-grid">
                        <FormGroup label="Provider">
                          <Select value={whatsAppForm.provider} onChange={(event) => updateWhatsAppForm('provider', event.target.value)}>
                            <option value="meta_cloud">meta_cloud</option>
                          </Select>
                        </FormGroup>
                        <FormGroup label="Phone number">
                          <Input value={whatsAppForm.phone_number ?? ''} onChange={(event) => updateWhatsAppForm('phone_number', event.target.value)} placeholder="+5511999999999" />
                        </FormGroup>
                        <FormGroup label="Phone number ID">
                          <Input value={whatsAppForm.phone_number_id ?? ''} onChange={(event) => updateWhatsAppForm('phone_number_id', event.target.value)} placeholder="123456" />
                        </FormGroup>
                        <FormGroup label="Business account ID">
                          <Input value={whatsAppForm.business_account_id ?? ''} onChange={(event) => updateWhatsAppForm('business_account_id', event.target.value)} placeholder="789" />
                        </FormGroup>
                        <FormGroup label="Access token">
                          <Input type="password" value={whatsAppForm.access_token ?? ''} onChange={(event) => updateWhatsAppForm('access_token', event.target.value)} placeholder="Preencha apenas para atualizar" />
                        </FormGroup>
                        <FormGroup label="Webhook verify token">
                          <Input type="password" value={whatsAppForm.webhook_verify_token ?? ''} onChange={(event) => updateWhatsAppForm('webhook_verify_token', event.target.value)} placeholder="Preencha apenas para atualizar" />
                        </FormGroup>
                      </div>
                      <Alert variant="info">Preencha o token apenas se quiser atualizar a credencial.</Alert>
                      <div className="lw-whatsapp-badges">
                        <Badge variant={whatsAppSettings?.access_token_configured ? 'success' : 'neutral'}>
                          {whatsAppSettings?.access_token_configured ? 'Token configurado' : 'Token não configurado'}
                        </Badge>
                        <Badge variant={whatsAppSettings?.webhook_verify_token_configured ? 'success' : 'neutral'}>
                          {whatsAppSettings?.webhook_verify_token_configured ? 'Verify token configurado' : 'Verify token não configurado'}
                        </Badge>
                        {whatsAppSettings?.connected_at ? (
                          <Badge variant="info">Conectado em {formatDateTime(whatsAppSettings.connected_at)}</Badge>
                        ) : null}
                      </div>
                    </Card>

                    <div>
                      <Button type="submit" disabled={whatsAppSaving}>
                        {whatsAppSaving ? 'Salvando...' : 'Salvar configuração'}
                      </Button>
                    </div>
                  </form>
                ) : null}

                {!whatsAppLoading && !whatsAppSettings ? (
                  <EmptyState title="Configuração indisponível no momento." description="Tente recarregar a página e salvar novamente." />
                ) : null}
              </Section>
            </>
          ) : null}

          {activeView === 'dashboard' && overview ? (
        <Section>
          <h2>Empresa</h2>
          <p><strong>{overview.company.name}</strong> ({overview.company.slug})</p>
          <p>Horário: {overview.company.work_start} às {overview.company.work_end}</p>
        </Section>
      ) : null}

      {activeView === 'dashboard' && dashboard ? (
        <Section className="lw-dashboard-section">
          <div style={{ display: 'flex', alignItems: 'center', gap: 8, marginBottom: 12 }}>
            <h2 style={{ margin: 0 }}>Dashboard Diário</h2>
            <Badge variant="neutral">{dashboard.date}</Badge>
          </div>
          <div className="lw-metrics-grid">
            {dashboardMetrics.map((metric) => (
              <MetricCard
                key={metric.label}
                label={metric.label}
                value={metric.value}
                variant={riskMetricLabels.has(metric.label) ? 'risk' : 'default'}
              />
            ))}
          </div>
        </Section>
      ) : null}

      {activeView === 'inbox' ? (
      <Section>
        <h2 style={{ marginTop: 0 }}>Inbox / Atendimento</h2>

        <form onSubmit={(event) => void applyInboxFilters(event)} style={{ display: 'grid', gap: 8, marginBottom: 12 }}>
          <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>
            <input
              value={inboxSearchInput}
              onChange={(event) => setInboxSearchInput(event.target.value)}
              placeholder="Buscar conversa por nome ou telefone"
              style={{ minWidth: 260 }}
            />
            <select value={inboxOwnerFilter} onChange={(event) => setInboxOwnerFilter(event.target.value ? Number(event.target.value) : '')}>
              <option value="">Todos os responsáveis</option>
              {inboxOwnerOptions.map((owner) => (
                <option key={owner.id} value={owner.id}>{owner.name}</option>
              ))}
            </select>
            <select value={inboxSourceFilter} onChange={(event) => setInboxSourceFilter(event.target.value)}>
              <option value="">Todas as origens</option>
              <option value="instagram">instagram</option>
              <option value="google">google</option>
              <option value="facebook">facebook</option>
              <option value="indicacao">indicacao</option>
              <option value="desconhecido">desconhecido</option>
            </select>
            <select value={inboxStageFilter} onChange={(event) => setInboxStageFilter(event.target.value ? Number(event.target.value) : '')}>
              <option value="">Todas as etapas</option>
              {(kanban?.columns ?? []).map((column) => (
                <option key={column.id} value={column.id}>{column.name}</option>
              ))}
            </select>
            <select value={inboxServiceWindowFilter} onChange={(event) => setInboxServiceWindowFilter(event.target.value as '' | 'true' | 'false')}>
              <option value="">Janela: todas</option>
              <option value="true">Janela aberta</option>
              <option value="false">Janela fechada</option>
            </select>
            <button type="submit">Aplicar filtros</button>
          </div>
        </form>

        {inboxLoading ? <p>Carregando inbox...</p> : null}
        {inboxError ? <p style={{ color: 'crimson' }}>{inboxError}</p> : null}

        {!inboxLoading && !inboxError && inboxConversations.length === 0 ? (
          <EmptyState
            title="Nenhuma conversa encontrada."
            description="Novas conversas aparecerão após a chegada de mensagens pelo webhook."
          />
        ) : null}

        {!inboxLoading && !inboxError && inboxConversations.length > 0 ? (
          <>
            <div className="lw-inbox-split" style={{ gap: 12, alignItems: 'start' }}>
              <aside style={{ border: '1px solid #eee', borderRadius: 8, maxHeight: 520, overflow: 'auto' }}>
                {inboxConversations.map((conversation) => {
                  const isSelected = selectedConversationId === conversation.conversation_id;
                  return (
                    <button
                      key={conversation.conversation_id}
                      type="button"
                      onClick={() => setSelectedConversationId(conversation.conversation_id)}
                      style={{
                        width: '100%',
                        textAlign: 'left',
                        border: 'none',
                        borderBottom: '1px solid #eee',
                        background: isSelected ? '#eef5ff' : '#fff',
                        padding: 10,
                        cursor: 'pointer',
                      }}
                    >
                      <p style={{ margin: 0 }}><strong>{conversation.lead_name || conversation.phone}</strong></p>
                      <small style={{ display: 'block' }}>{conversation.phone}</small>
                      <small style={{ display: 'block' }}>Origem: {conversation.source}</small>
                      <small style={{ display: 'block' }}>
                        Última: {conversation.last_message_direction || 'sem direção'} · {formatDateTime(conversation.last_message_at)}
                      </small>
                    </button>
                  );
                })}
              </aside>

              <div style={{ border: '1px solid #eee', borderRadius: 8, padding: 12, minHeight: 300 }}>
                {inboxDetailLoading ? <p>Carregando conversa...</p> : null}
                {inboxDetailError ? <p style={{ color: 'crimson' }}>{inboxDetailError}</p> : null}

                {!inboxDetailLoading && !inboxDetailError && !inboxDetail ? <p>Selecione uma conversa para ver o histórico.</p> : null}

                {!inboxDetailLoading && !inboxDetailError && inboxDetail ? (
                  <>
                    <h3 style={{ marginTop: 0 }}>Conversa #{inboxDetail.conversation_id}</h3>
                    <small style={{ display: 'block' }}><strong>Lead:</strong> {inboxDetail.lead.lead_name || 'Sem nome'}</small>
                    <small style={{ display: 'block' }}><strong>Telefone:</strong> {inboxDetail.lead.phone}</small>
                    <small style={{ display: 'block' }}><strong>Origem:</strong> {inboxDetail.lead.source}</small>
                    <small style={{ display: 'block' }}><strong>Etapa:</strong> {inboxDetail.lead.current_stage || 'Sem etapa'}</small>
                    <small style={{ display: 'block' }}><strong>Responsável:</strong> {inboxDetail.owner.owner_name || 'Sem responsável'}</small>
                    {canManageSource ? (
                      <form onSubmit={(event) => void handleUpdateInboxOwner(event)} style={{ marginTop: 8, marginBottom: 8, display: 'grid', gap: 6 }}>
                        <div style={{ display: 'flex', gap: 8, alignItems: 'center', flexWrap: 'wrap' }}>
                          <label>
                            Alterar responsável:
                            <select
                              value={inboxOwnerSelection}
                              onChange={(event) => setInboxOwnerSelection(event.target.value ? Number(event.target.value) : '')}
                              disabled={inboxOwnerUpdateLoading}
                              style={{ marginLeft: 8 }}
                            >
                              <option value="">Sem responsável</option>
                              {inboxAssignableOwnerOptions.map((owner) => (
                                <option key={owner.id} value={owner.id}>{owner.name}</option>
                              ))}
                            </select>
                          </label>
                          <button type="submit" disabled={inboxOwnerUpdateLoading}>
                            {inboxOwnerUpdateLoading ? 'Atualizando...' : 'Salvar responsável'}
                          </button>
                        </div>
                        <small style={{ color: '#555' }}>
                          Motivo aplicado: "Alterado pela Inbox".
                        </small>
                        {assignableUsersError ? (
                          <small style={{ color: '#8a5a00' }}>
                            {assignableUsersError}
                          </small>
                        ) : null}
                        {!assignableUsersError && canManageSource && inboxAssignableOwnerOptions.length === 0 ? (
                          <small style={{ color: '#8a5a00' }}>
                            Nenhum usuário atribuível encontrado. Verifique usuários ativos da empresa.
                          </small>
                        ) : null}
                        {inboxOwnerUpdateError ? <small style={{ color: 'crimson' }}>{inboxOwnerUpdateError}</small> : null}
                        {inboxOwnerUpdateSuccess ? <small style={{ color: '#1b7f3b' }}>{inboxOwnerUpdateSuccess}</small> : null}
                      </form>
                    ) : null}
                    <small style={{ display: 'block', marginBottom: 10 }}>
                      <strong>Janela:</strong> {inboxDetail.service_window_open ? 'Aberta' : 'Fechada'}
                      {inboxDetail.service_window_expires_at ? ` · expira em ${formatDateTime(inboxDetail.service_window_expires_at)}` : ''}
                    </small>

                    <div style={{ display: 'flex', gap: 8, marginBottom: 10 }}>
                      <button
                        type="button"
                        onClick={() => setInboxDetailTab('messages')}
                        style={{
                          border: '1px solid #ddd',
                          background: inboxDetailTab === 'messages' ? '#eef5ff' : '#fff',
                        }}
                      >
                        Mensagens
                      </button>
                      <button
                        type="button"
                        onClick={() => setInboxDetailTab('audit')}
                        style={{
                          border: '1px solid #ddd',
                          background: inboxDetailTab === 'audit' ? '#eef5ff' : '#fff',
                        }}
                      >
                        Auditoria
                      </button>
                    </div>

                    {inboxDetailTab === 'messages' ? (
                      <>
                        <div style={{ display: 'grid', gap: 8, maxHeight: 360, overflow: 'auto', paddingRight: 4 }}>
                          {inboxDetail.messages.map((message) => (
                            <div
                              key={message.id}
                              style={{
                                border: '1px solid #ddd',
                                borderRadius: 8,
                                padding: 8,
                                background: message.direction === 'inbound' ? '#f4fff4' : '#f5f8ff',
                              }}
                            >
                              <small style={{ display: 'block' }}>
                                <strong>{message.direction === 'inbound' ? 'Cliente' : 'Time'}</strong> · {formatDateTime(message.sent_at)}
                              </small>
                              <p style={{ margin: '6px 0' }}>{message.body || 'Mensagem sem texto'}</p>
                              <small style={{ display: 'block' }}>provider: {message.provider || 'n/d'} · id externo: {message.external_message_id || 'n/d'}</small>
                            </div>
                          ))}
                        </div>

                        <form onSubmit={(event) => void handleInboxSendMessage(event)} style={{ marginTop: 12, display: 'grid', gap: 8 }}>
                          {!inboxDetail.service_window_open ? (
                            <small style={{ color: '#8a5a00' }}>
                              Janela de atendimento fechada. O envio será habilitado após nova mensagem inbound do cliente.
                            </small>
                          ) : null}
                          {inboxSendError ? <small style={{ color: 'crimson' }}>{inboxSendError}</small> : null}
                          {inboxSendSuccess ? <small style={{ color: '#1b7f3b' }}>{inboxSendSuccess}</small> : null}
                          <textarea
                            value={inboxReplyBody}
                            onChange={(event) => setInboxReplyBody(event.target.value)}
                            placeholder={inboxDetail.service_window_open ? 'Digite sua resposta...' : 'Envio indisponível com janela fechada'}
                            disabled={inboxSendLoading || !inboxDetail.service_window_open}
                            rows={3}
                            style={{ width: '100%', padding: 8, resize: 'vertical' }}
                          />
                          <div>
                            <button
                              type="submit"
                              disabled={inboxSendLoading || !inboxDetail.service_window_open || inboxReplyBody.trim().length === 0}
                            >
                              {inboxSendLoading ? 'Enviando...' : 'Enviar'}
                            </button>
                          </div>
                        </form>
                      </>
                    ) : null}

                    {inboxDetailTab === 'audit' ? (
                      <div style={{ display: 'grid', gap: 8, maxHeight: 420, overflow: 'auto', paddingRight: 4 }}>
                        {inboxEventsLoading ? <p>Carregando auditoria...</p> : null}
                        {inboxEventsError ? <p style={{ color: 'crimson' }}>{inboxEventsError}</p> : null}
                        {!inboxEventsLoading && !inboxEventsError && inboxEvents.length === 0 ? (
                          <p>Nenhum evento de auditoria encontrado para esta conversa.</p>
                        ) : null}
                        {!inboxEventsLoading && !inboxEventsError && inboxEvents.length > 0 ? (
                          inboxEvents.map((event) => (
                            <div key={`${event.event_type}-${event.event_id}`} style={{ border: '1px solid #ddd', borderRadius: 8, padding: 8 }}>
                              <small style={{ display: 'block' }}>
                                <strong>{getFriendlyAuditEventType(event.event_type)}</strong> · {formatDateTime(event.occurred_at)}
                              </small>
                              {event.event_type === 'conversation_opened' ? (
                                <p style={{ margin: '6px 0' }}>
                                  Conversa aberta por {event.user_name || 'usuário não identificado'}
                                </p>
                              ) : null}
                              {event.event_type === 'message_sent' ? (
                                <>
                                  <p style={{ margin: '6px 0' }}>
                                    Mensagem enviada por {event.user_name || 'usuário não identificado'}
                                  </p>
                                  {(event.metadata?.provider || event.metadata?.external_message_id) ? (
                                    <small style={{ color: '#555' }}>
                                      provider: {event.metadata?.provider || 'n/d'} · id externo: {event.metadata?.external_message_id || 'n/d'}
                                    </small>
                                  ) : null}
                                </>
                              ) : null}
                              {event.event_type === 'stage_changed' ? (
                                <>
                                  <p style={{ margin: '6px 0' }}>
                                    Etapa alterada por {event.user_name || 'usuário não identificado'}
                                  </p>
                                  <small style={{ display: 'block' }}>
                                    de {event.metadata?.from_column_name || 'Sem etapa'} para {event.metadata?.to_column_name || 'Sem etapa'}
                                  </small>
                                  <small style={{ display: 'block' }}>
                                    move_source: {event.metadata?.move_source || 'n/d'}
                                  </small>
                                  {event.metadata?.reason ? (
                                    <small style={{ display: 'block' }}>
                                      motivo: {event.metadata.reason}
                                    </small>
                                  ) : null}
                                </>
                              ) : null}
                              {(event.event_type === 'owner_assigned' || event.event_type === 'owner_changed' || event.event_type === 'owner_removed') ? (
                                <>
                                  <p style={{ margin: '6px 0' }}>
                                    {event.event_type === 'owner_assigned' ? 'Responsável atribuído' : event.event_type === 'owner_changed' ? 'Responsável alterado' : 'Responsável removido'} por {event.user_name || 'usuário não identificado'}
                                  </p>
                                  <small style={{ display: 'block' }}>
                                    anterior: {event.metadata?.previous_owner_name || 'Sem responsável'} · novo: {event.metadata?.new_owner_name || 'Sem responsável'}
                                  </small>
                                  {event.metadata?.reason ? (
                                    <small style={{ display: 'block' }}>
                                      motivo: {event.metadata.reason}
                                    </small>
                                  ) : null}
                                </>
                              ) : null}
                            </div>
                          ))
                        ) : null}
                      </div>
                    ) : null}
                  </>
                ) : null}
              </div>
            </div>

            <div style={{ display: 'flex', alignItems: 'center', gap: 8, marginTop: 12 }}>
              <button onClick={() => changeInboxPage(inboxMeta.page - 1)} disabled={inboxMeta.page <= 1 || inboxLoading}>Anterior</button>
              <small>Página {inboxMeta.page} de {Math.max(inboxMeta.last_page, 1)}</small>
              <button onClick={() => changeInboxPage(inboxMeta.page + 1)} disabled={inboxMeta.page >= inboxMeta.last_page || inboxLoading}>Próxima</button>
              <small style={{ marginLeft: 8 }}>Total: {inboxMeta.total}</small>
            </div>
          </>
        ) : null}
      </Section>
      ) : null}

      {activeView === 'checklist' ? (
      <Section>
        <h2 style={{ marginTop: 0 }}>Checklist do Dia</h2>
        {checklistLoading ? <p>Carregando checklist...</p> : null}
        {checklistError ? <p style={{ color: 'crimson' }}>{checklistError}</p> : null}
        {copyFeedback ? <p style={{ color: '#1b7f3b' }}>{copyFeedback}</p> : null}

        {!checklistLoading && !checklistError && checklistItems.length === 0 ? <p>Nenhuma tarefa operacional pendente no momento.</p> : null}

        {!checklistLoading && !checklistError && checklistItems.length > 0 ? (
          <div style={{ display: 'grid', gap: 10, gridTemplateColumns: 'repeat(auto-fit, minmax(300px, 1fr))' }}>
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
      </Section>
      ) : null}

      {activeView === 'kanban' ? (
      <Section className="lw-kanban-section">
        <div className="lw-kanban-header">
          <h2 style={{ marginTop: 0 }}>Kanban</h2>
          {selectedPipelineId ? <Badge variant="info">Pipeline #{selectedPipelineId}</Badge> : null}
        </div>
        {!canMoveStage ? <p style={{ marginTop: 0 }}>Você está em perfil <strong>SDR</strong>: pode visualizar o Kanban, mas não pode mover cards.</p> : null}

        {pipelines.length === 0 ? <p>Nenhum pipeline encontrado para esta empresa. Rode o bootstrap demo ou configure um pipeline.</p> : null}

        {pipelines.length > 1 ? (
          <label style={{ display: 'block', marginBottom: 12 }}>
            Pipeline:
            <select className="lw-select" style={{ marginLeft: 8, maxWidth: 280 }} value={selectedPipelineId ?? ''} onChange={(e) => setSelectedPipelineId(Number(e.target.value))}>
              {pipelines.map((pipeline) => <option key={pipeline.id} value={pipeline.id}>{pipeline.name}</option>)}
            </select>
          </label>
        ) : null}

        {pipelines.length === 1 && selectedPipelineId ? <p style={{ marginTop: 0 }}><strong>Pipeline:</strong> {pipelines[0].name}</p> : null}

        {kanbanLoading ? <p>Carregando Kanban...</p> : null}
        {kanbanError ? <p style={{ color: 'crimson' }}>{kanbanError}</p> : null}
        {!kanbanLoading && !kanbanError && kanban && kanban.columns.length === 0 ? <p>Este pipeline ainda não possui colunas.</p> : null}

        {!kanbanLoading && !kanbanError && kanban && kanban.columns.length > 0 ? (
          <div className="lw-kanban-columns">
            {kanban.columns.map((column) => (
              <div
                key={column.id}
                onDragOver={(event) => handleColumnDragOver(event, column.id)}
                onDrop={(event) => { void handleColumnDrop(event, column.id); }}
                onDragLeave={() => { if (dragOverColumnId === column.id) setDragOverColumnId(null); }}
                className={`lw-kanban-column ${dragOverColumnId === column.id ? 'lw-kanban-column--drag-over' : ''}`}
              >
                <div className="lw-kanban-column-header">
                  <h3 style={{ marginTop: 0, marginBottom: 8 }}>{column.name}</h3>
                  <Badge variant="neutral">{column.cards.length}</Badge>
                </div>
                <small style={{ display: 'block', marginBottom: 10 }}>Etapa atual: {column.name}</small>
                {column.rule ? <small style={{ display: 'block', marginBottom: 10, color: '#64748b' }}>Regra: {column.rule}</small> : null}

                {column.cards.length === 0 ? <EmptyState title="Sem leads nesta etapa." /> : null}

                {column.cards.map((card) => {
                  const history = stageHistoryByLead[card.lead_id];
                  return (
                    <div
                      key={card.lead_id}
                      draggable={canMoveStage}
                      onDragStart={() => handleCardDragStart(card.lead_id, column.id)}
                      onDragEnd={handleDragEnd}
                      onMouseDown={() => { if (canMoveStage) setPressedCardId(card.lead_id); }}
                      onMouseUp={() => setPressedCardId(null)}
                      onMouseLeave={() => setPressedCardId(null)}
                      className={`lw-kanban-card ${draggingCard?.leadId === card.lead_id || pressedCardId === card.lead_id ? 'lw-kanban-card--active' : ''}`}
                      style={{
                        cursor: canMoveStage
                          ? (draggingCard?.leadId === card.lead_id || pressedCardId === card.lead_id ? 'grabbing' : 'grab')
                          : 'default',
                      }}
                    >
                      <p style={{ margin: 0 }}><strong>{card.name || card.phone}</strong></p>
                      <small style={{ display: 'block' }}>Telefone: {card.phone}</small>
                      <div style={{ display: 'flex', gap: 6, flexWrap: 'wrap', marginTop: 6, marginBottom: 6 }}>
                        <Badge variant="info">{card.source}</Badge>
                        <Badge variant={card.classification === 'lead_novo' ? 'success' : 'warning'}>{card.classification}</Badge>
                      </div>
                      <small style={{ display: 'block' }}>Última mensagem: {card.last_message_at || 'Sem registro'}</small>
                      <small style={{ display: 'block' }}>Responsável: n/d</small>
                      <small style={{ display: 'block', marginBottom: 8 }}>Etapa atual: {column.name}</small>
                      {canMoveStage ? <small style={{ display: 'block', marginBottom: 8, color: '#555' }}>Arrastar para mover</small> : null}

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
      </Section>
      ) : null}

      {activeView === 'contacts' ? (
      <Section>
        <h2 style={{ marginTop: 0 }}>Contatos</h2>

        <form onSubmit={(event) => void applyContactsFilters(event)} style={{ display: 'grid', gap: 8, marginBottom: 12 }}>
          <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>
            <input value={contactSearchInput} onChange={(event) => setContactSearchInput(event.target.value)} placeholder="Buscar por nome ou telefone" style={{ minWidth: 260 }} />
            <select value={contactSourceFilter} onChange={(event) => setContactSourceFilter(event.target.value)}>
              <option value="">Todas as origens</option>
              <option value="instagram">instagram</option>
              <option value="google">google</option>
              <option value="facebook">facebook</option>
              <option value="indicacao">indicacao</option>
              <option value="desconhecido">desconhecido</option>
            </select>
            <select value={contactClassificationFilter} onChange={(event) => setContactClassificationFilter(event.target.value as '' | 'lead_novo' | 'lead_repetido')}>
              <option value="">Todas as classificações</option>
              <option value="lead_novo">lead_novo</option>
              <option value="lead_repetido">lead_repetido</option>
            </select>
            <select value={contactStageFilter} onChange={(event) => setContactStageFilter(event.target.value ? Number(event.target.value) : '')}>
              <option value="">Todas as etapas</option>
              {(kanban?.columns ?? []).map((column) => <option key={column.id} value={column.id}>{column.name}</option>)}
            </select>
            <button type="submit">Aplicar filtros</button>
            {canManageSource ? (
              <button type="button" onClick={() => void handleExportContactsCsv()} disabled={contactsExportLoading}>
                {contactsExportLoading ? 'Exportando...' : 'Exportar CSV'}
              </button>
            ) : null}
          </div>
        </form>

        {contactsLoading ? <p>Carregando contatos...</p> : null}
        {contactsError ? <p style={{ color: 'crimson' }}>{contactsError}</p> : null}
        {contactsExportError ? <p style={{ color: 'crimson' }}>{contactsExportError}</p> : null}
        {contactsExportSuccess ? <p style={{ color: '#1b7f3b' }}>{contactsExportSuccess}</p> : null}

        {!contactsLoading && !contactsError && contacts.length === 0 ? <p>Nenhum contato encontrado com os filtros atuais.</p> : null}

        {!contactsLoading && !contactsError && contacts.length > 0 ? (
          <>
            <div style={{ display: 'grid', gap: 8, gridTemplateColumns: 'repeat(auto-fit, minmax(320px, 1fr))' }}>
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
              <button onClick={() => changeContactsPage(contactsMeta.page - 1)} disabled={contactsMeta.page <= 1 || contactsLoading}>Anterior</button>
              <small>Página {contactsMeta.page} de {Math.max(contactsMeta.last_page, 1)}</small>
              <button onClick={() => changeContactsPage(contactsMeta.page + 1)} disabled={contactsMeta.page >= contactsMeta.last_page || contactsLoading}>Próxima</button>
              <small style={{ marginLeft: 8 }}>Total: {contactsMeta.total}</small>
            </div>
          </>
        ) : null}
      </Section>
      ) : null}

      {activeView === 'dashboard' && !canManageSource ? (
        <Section>
          <h2>Classificação de Origem</h2>
          <p>
            Apenas <strong>gestor</strong> ou <strong>admin</strong> podem classificar/reclassificar origem.
            Se necessário, o atendimento deve solicitar essa ação ao gestor.
          </p>
        </Section>
      ) : null}

      {activeView === 'dashboard' && canManageSource ? (
        <>
          <Section>
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
          </Section>

          <Section>
            <h2>Leads Recentes (Reclassificar)</h2>
            {recentLeads.length === 0 ? <p>Nenhum lead recente.</p> : null}
            {recentLeads.map((lead) => (
              <div key={lead.id} style={{ border: '1px solid #ddd', borderRadius: 8, padding: 12, marginBottom: 10 }}>
                <p style={{ margin: 0 }}><strong>{lead.name || 'Sem nome'}</strong> - {lead.phone_e164}</p>
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
          </Section>
        </>
      ) : null}
            </>
          )}
        </div>
      </div>
    </AppShell>
  );
}
