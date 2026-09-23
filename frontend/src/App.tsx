import { useEffect, useMemo, useRef, useState } from 'react';
import {
  disconnectWhatsApp,
  createAdminTenantViewContext,
  createAdminCompany,
  completeWhatsAppCoexistence,
  exportContactsCsv,
  getAdminCompanies,
  getAssignableUsers,
  getContacts,
  getDashboardSummary,
  getInboxConversationDetail,
  getInboxConversationEvents,
  getInboxConversations,
  getOverview,
  getPipelineKanban,
  getPipelines,
  getTasksChecklist,
  getWhatsAppSettings,
  login,
  requestWhatsAppCoexistenceSync,
  revokeAdminTenantViewContext,
  updateAdminCompany,
  updateLeadOwner,
} from './api';
import { createSingleFlight, settleRefreshes, startPolling } from './refresh';
import { CoexistenceFlowError, WhatsAppCoexistenceFlow } from './meta/coexistenceSignup';
import { AppShell, PageHeader, Sidebar, Topbar } from './components/layout';
import { ConversationIntelligencePage } from './pages/ConversationIntelligencePage';
import { CampaignIntelligencePage } from './pages/CampaignIntelligencePage';
import { AutoCrmPage } from './pages/AutoCrmPage';
import { DashboardPage } from './pages/DashboardPage';
import { AttendancePage } from './pages/AttendancePage';
import { AdminUsersPage } from './pages/AdminUsersPage';
import { AdminAccessPage } from './pages/AdminAccessPage';
import { PasswordChangePage } from './pages/PasswordChangePage';
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
  Modal,
  Select,
  Section,
} from './components/ui';
import type {
  AdminCompanyCreateRequest,
  AdminCompanyListItem,
  AdminCompanyUpdateRequest,
  AdminTenantViewContext,
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
  OverviewResponse,
  PipelineKanban,
  PipelineListItem,
  WhatsAppSettings,
} from './types';

const EMAIL_PATTERN = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
const SLUG_PATTERN = /^[a-z0-9_-]+$/;

type Session = {
  token: string;
  user: AuthUser;
};

const STORAGE_KEY = 'leadswhats_session';
const CHECKLIST_DEFAULT_MESSAGE = 'Olá! Passando para saber se posso te ajudar com mais alguma informação.';

function formatDateTime(value: string | null | undefined): string {
  if (!value) return 'Sem registro';
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) return value;
  return date.toLocaleString('pt-BR');
}

function formatPhoneDisplay(phone: string | null | undefined): string {
  if (!phone) return 'Aguardando identificação do WhatsApp';
  return phone;
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

function formatRoleLabel(role: AuthUser['role']): string {
  const labels: Record<AuthUser['role'], string> = {
    admin: 'Administrador',
    gestor: 'Gestor',
    sdr: 'Atendente',
    platform_admin: 'Administrador da plataforma',
    custom: 'Perfil personalizado',
  };
  return labels[role];
}

function formatSourceLabel(source: string | null | undefined): string {
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

function formatClassificationLabel(classification: string | null | undefined): string {
  if (classification === 'lead_novo') return 'Lead novo';
  if (classification === 'lead_repetido') return 'Lead recorrente';
  return classification || 'Não classificado';
}

function formatDirectionLabel(direction: string | null | undefined): string {
  if (direction === 'inbound') return 'recebida';
  if (direction === 'outbound') return 'enviada';
  return 'sem direção identificada';
}

function formatPriorityLabel(priority: string): string {
  const labels: Record<string, string> = {
    high: 'Alta',
    medium: 'Média',
    low: 'Baixa',
    urgent: 'Urgente',
  };
  return labels[priority.toLocaleLowerCase('pt-BR')] ?? priority;
}

function formatWhatsAppStatus(status: WhatsAppSettings['status'] | undefined): string {
  if (status === 'configured') return 'Configurado';
  if (status === 'error') return 'Com erro';
  return 'Não configurado';
}

function formatWhatsAppSyncStatus(status: WhatsAppSettings['history_sync_status'], label: string): string {
  if (status === 'completed') return `${label} sincronizado`;
  if (status === 'error') return `${label} com erro`;
  if (status === 'requested') return `${label} em andamento`;
  return `${label} não solicitado`;
}

function formatProviderLabel(provider: string | null | undefined): string {
  if (provider === 'meta_cloud') return 'Meta Cloud API';
  if (provider === 'fake') return 'Demonstração';
  return provider || 'Não informado';
}

function formatMovementSource(source: unknown): string {
  if (source === 'ai_recommendation_accepted') return 'Recomendação da IA aceita';
  if (source === 'manual') return 'Movimentação manual';
  if (source === 'system') return 'Atualização do sistema';
  return 'Não informada';
}

function formatAuditReason(reason: unknown): string {
  if (reason === 'Alterado pela Inbox') return 'Alterado pela área de Conversas';
  return typeof reason === 'string' && reason ? reason : 'Não informado';
}

export function App() {
  type ActiveView = 'dashboard' | 'attendance' | 'inbox' | 'checklist' | 'kanban' | 'contacts' | 'intelligence' | 'campaignIntelligence' | 'adminSaas' | 'adminUsers' | 'adminAccess' | 'whatsappSettings';
  const [theme, setTheme] = useState<'light' | 'dark'>(() => (localStorage.getItem('leadswhats_theme') as 'light' | 'dark') || 'dark');
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [session, setSession] = useState<Session | null>(null);

  useEffect(() => {
    document.documentElement.setAttribute('data-theme', theme);
    localStorage.setItem('leadswhats_theme', theme);
  }, [theme]);
  const [overview, setOverview] = useState<OverviewResponse | null>(null);
  const [dashboard, setDashboard] = useState<DashboardSummaryResponse | null>(null);
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
  const [intelligenceConversationId, setIntelligenceConversationId] = useState<number | null>(null);
  const [activeView, setActiveView] = useState<ActiveView>('dashboard');
  const [adminCompanies, setAdminCompanies] = useState<AdminCompanyListItem[]>([]);
  const [adminCompaniesLoading, setAdminCompaniesLoading] = useState(false);
  const [adminCompaniesError, setAdminCompaniesError] = useState<string | null>(null);
  const [adminViewContext, setAdminViewContext] = useState<AdminTenantViewContext | null>(null);
  const [adminViewContextLoadingCompanyId, setAdminViewContextLoadingCompanyId] = useState<number | null>(null);
  const [adminViewContextExitLoading, setAdminViewContextExitLoading] = useState(false);
  const [adminCompanyCreateLoading, setAdminCompanyCreateLoading] = useState(false);
  const [adminCompanyCreateError, setAdminCompanyCreateError] = useState<string | null>(null);
  const [adminCompanyCreateSuccess, setAdminCompanyCreateSuccess] = useState<string | null>(null);
  const [adminCompanyTokenInfo, setAdminCompanyTokenInfo] = useState<{ configured: boolean; masked: string | null } | null>(null);
  const [adminCompanyEdit, setAdminCompanyEdit] = useState<AdminCompanyListItem | null>(null);
  const [adminEditName, setAdminEditName] = useState('');
  const [adminEditSlug, setAdminEditSlug] = useState('');
  const [adminEditActive, setAdminEditActive] = useState(true);
  const [adminRecipientValue, setAdminRecipientValue] = useState('');
  const [adminCompanyEditLoading, setAdminCompanyEditLoading] = useState(false);
  const [adminCompanyEditError, setAdminCompanyEditError] = useState<string | null>(null);
  const [whatsAppSettings, setWhatsAppSettings] = useState<WhatsAppSettings | null>(null);
  const [whatsAppLoading, setWhatsAppLoading] = useState(false);
  const [whatsAppConnecting, setWhatsAppConnecting] = useState(false);
  const [whatsAppDisconnecting, setWhatsAppDisconnecting] = useState(false);
  const [confirmWhatsAppDisconnect, setConfirmWhatsAppDisconnect] = useState(false);
  const [whatsAppError, setWhatsAppError] = useState<string | null>(null);
  const [whatsAppSuccess, setWhatsAppSuccess] = useState<string | null>(null);
  const [whatsAppSyncLoading, setWhatsAppSyncLoading] = useState(false);
  const whatsAppCoexistenceFlowRef = useRef<WhatsAppCoexistenceFlow<WhatsAppSettings> | null>(null);
  const [adminForm, setAdminForm] = useState<AdminCompanyCreateRequest>({
    company: { name: '', slug: '' },
    admin_user: { name: '', email: '', password: '' },
    settings: {
      timezone: 'America/Sao_Paulo',
      daily_report_recipient: '',
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
  const tenantDataEpochRef = useRef(0);
  const pollingInFlightRef = useRef(false);
  const dashboardRefreshRef = useRef(createSingleFlight());
  const isPlatformAdmin = session?.user.role === 'platform_admin';
  const isAgencyViewing = Boolean(isPlatformAdmin && adminViewContext);
  const tenantContextToken = adminViewContext?.context_token;

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

  const legacyPermissions = session?.user.role === 'admin' || session?.user.role === 'gestor'
    ? ['dashboard.view','attendance.view','attendance.respond','conversations.view','conversations.respond','followups.view','crm.view','crm.manage','contacts.view','contacts.export','contacts.classify','conversation_intelligence.view','conversation_intelligence.analyze','campaign_intelligence.view','campaign_intelligence.analyze','whatsapp_settings.view','whatsapp_settings.manage','leads.assignable']
    : session?.user.role === 'sdr' ? ['dashboard.view','attendance.view','attendance.respond','conversations.view','conversations.respond','followups.view','crm.view','contacts.view','leads.assignable'] : [];
  const effectivePermissions = session?.user.permissions ?? legacyPermissions;
  const hasPermission = (permission: string) => Boolean(isPlatformAdmin || effectivePermissions.includes(permission));
  const canManageCrm = hasPermission('crm.manage');
  const canExportContacts = hasPermission('contacts.export');
  const canManageWhatsAppSettings = hasPermission('whatsapp_settings.manage') && !isPlatformAdmin;
  const canViewIntelligence = hasPermission('conversation_intelligence.view') || isAgencyViewing;
  const canViewWhatsAppSettings = hasPermission('whatsapp_settings.view') || isAgencyViewing;
  const inboxOwnerOptions = useMemo(() => {
    const map = new Map<number, string>();
    if (canManageCrm && assignableUsers.length > 0) {
      for (const user of assignableUsers) map.set(user.id, user.name);
    } else {
      for (const conv of inboxConversations) {
        if (conv.owner_user_id && conv.owner_name) map.set(conv.owner_user_id, conv.owner_name);
      }
    }
    return Array.from(map.entries())
      .map(([id, name]) => ({ id, name }))
      .sort((a, b) => a.name.localeCompare(b.name));
  }, [canManageCrm, assignableUsers, inboxConversations]);

  const inboxAssignableOwnerOptions = useMemo(() => {
    const map = new Map<number, string>();
    if (canManageCrm && assignableUsers.length > 0) {
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
  }, [canManageCrm, assignableUsers, inboxOwnerOptions, session, inboxDetail]);

  async function fetchInboxEvents(token: string, conversationId: number, contextToken?: string) {
    const requestEpoch = tenantDataEpochRef.current;
    const events = await getInboxConversationEvents(token, conversationId, contextToken);
    if (requestEpoch !== tenantDataEpochRef.current) return;
    const sorted = [...events].sort((a, b) => {
      const occurredCompare = String(a.occurred_at).localeCompare(String(b.occurred_at));
      if (occurredCompare !== 0) return occurredCompare;
      return a.event_id - b.event_id;
    });
    setInboxEvents(sorted);
  }

  function refreshData(token: string, contextToken?: string): Promise<void> {
    const requestEpoch = tenantDataEpochRef.current;
    const key = JSON.stringify([token, contextToken, requestEpoch]);
    return dashboardRefreshRef.current(key, async () => {
      const current = () => requestEpoch === tenantDataEpochRef.current;
      const refreshes: Promise<unknown>[] = [];
      if (isAgencyViewing || hasPermission('dashboard.view')) {
        refreshes.push(getOverview(token, contextToken).then((data) => { if (current()) setOverview(data); }));
        refreshes.push(getDashboardSummary(token, contextToken).then((data) => { if (current()) setDashboard(data); }));
      }
      if (isAgencyViewing || hasPermission('crm.view')) refreshes.push(getPipelines(token, contextToken).then((data) => {
          if (!current()) return;
          setPipelines(data);
          setSelectedPipelineId((selected) => data.some((pipeline) => pipeline.id === selected)
            ? selected : data[0]?.id ?? null);
          if (data.length === 0) setKanban(null);
        }));
      if (isAgencyViewing || hasPermission('followups.view')) refreshes.push(getTasksChecklist(token, contextToken).then((data) => {
          if (!current()) return;
          setChecklistItems(data);
          setChecklistError(null);
        }).catch((err: unknown) => {
          if (current()) setChecklistError(parseApiErrorMessage(err, 'Não foi possível carregar o acompanhamento.'));
          throw err;
        }));
      await settleRefreshes(refreshes);
      if (current()) setError(null);
    });
  }

  async function refreshKanban(token: string, pipelineId: number, silent = false, contextToken?: string) {
    const requestEpoch = tenantDataEpochRef.current;
    if (!silent) {
      setKanbanLoading(true);
    }
    setKanbanError(null);
    try {
      const kanbanData = await getPipelineKanban(token, pipelineId, contextToken);
      if (requestEpoch !== tenantDataEpochRef.current) return;
      setKanban(kanbanData);
    } catch (err) {
      if (requestEpoch === tenantDataEpochRef.current) {
        setKanban(null);
        setKanbanError(parseApiErrorMessage(err, 'Não foi possível carregar o Kanban.'));
      }
      console.error(err);
    } finally {
      if (requestEpoch === tenantDataEpochRef.current) setKanbanLoading(false);
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

  async function refreshWhatsAppSettings(token: string, contextToken?: string) {
    const requestEpoch = tenantDataEpochRef.current;
    setWhatsAppLoading(true);
    setWhatsAppError(null);
    try {
      const data = await getWhatsAppSettings(token, contextToken);
      if (requestEpoch !== tenantDataEpochRef.current) return;
      setWhatsAppSettings(data);
    } catch (err) {
      if (requestEpoch === tenantDataEpochRef.current) {
        setWhatsAppSettings(null);
        setWhatsAppError(parseApiErrorMessage(err, 'Não foi possível carregar a configuração do WhatsApp.'));
      }
    } finally {
      if (requestEpoch === tenantDataEpochRef.current) setWhatsAppLoading(false);
    }
  }

  async function refreshContacts(token: string, page = 1, contextToken?: string) {
    const requestEpoch = tenantDataEpochRef.current;
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
      }, contextToken);
      if (requestEpoch !== tenantDataEpochRef.current) return;
      setContacts(response.data);
      setContactsMeta(response.meta);
    } catch (err) {
      if (requestEpoch === tenantDataEpochRef.current) {
        setContacts([]);
        setContactsError(parseApiErrorMessage(err, 'Não foi possível carregar os contatos.'));
      }
      console.error(err);
    } finally {
      if (requestEpoch === tenantDataEpochRef.current) setContactsLoading(false);
    }
  }

  async function refreshInboxConversations(token: string, page = 1, silent = false, contextToken?: string) {
    const requestEpoch = tenantDataEpochRef.current;
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
      }, contextToken);
      if (requestEpoch !== tenantDataEpochRef.current) return;

      setInboxConversations(response.data);
      setInboxMeta(response.meta);

      if (response.data.length === 0) {
        setSelectedConversationId(null);
        setInboxDetail(null);
        return;
      }

      // Só fechamos o modal se a conversa selecionada tiver sumido da lista (ex: filtro mudou).
      // Nunca selecionamos uma conversa sozinhos — o modal só abre por clique explícito do usuário.
      if (selectedConversationId) {
        const selectedStillExists = response.data.some((item) => item.conversation_id === selectedConversationId);
        if (!selectedStillExists) {
          setSelectedConversationId(null);
        }
      }
    } catch (err) {
      if (requestEpoch === tenantDataEpochRef.current) {
        setInboxConversations([]);
        setInboxError(parseApiErrorMessage(err, 'Não foi possível carregar as conversas.'));
      }
      console.error(err);
    } finally {
      if (requestEpoch === tenantDataEpochRef.current) setInboxLoading(false);
    }
  }

  async function refreshInboxDetail(token: string, conversationId: number, silent = false, contextToken?: string) {
    const requestEpoch = tenantDataEpochRef.current;
    if (!silent) {
      setInboxDetailLoading(true);
    }
    setInboxDetailError(null);
    try {
      const detail = await getInboxConversationDetail(token, conversationId, contextToken);
      if (requestEpoch !== tenantDataEpochRef.current) return;
      setInboxDetail(detail);
    } catch (err) {
      if (requestEpoch === tenantDataEpochRef.current) {
        setInboxDetail(null);
        setInboxDetailError(parseApiErrorMessage(err, 'Não foi possível carregar o histórico da conversa.'));
      }
      console.error(err);
    } finally {
      if (requestEpoch === tenantDataEpochRef.current) setInboxDetailLoading(false);
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
    if (isPlatformAdmin && !isAgencyViewing) return;
    if (!isAgencyViewing && !['dashboard.view', 'crm.view', 'followups.view'].some(hasPermission)) return;

    setLoading(true);
    setError(null);
    setChecklistLoading(true);
    setChecklistError(null);

    const requestEpoch = tenantDataEpochRef.current;
    let disposed = false;
    refreshData(session.token, tenantContextToken)
      .catch((err) => {
        if (disposed || requestEpoch !== tenantDataEpochRef.current) return;
        setError(parseApiErrorMessage(err, 'Falha ao carregar dados da API.'));
        console.error(err);
      })
      .finally(() => {
        if (disposed || requestEpoch !== tenantDataEpochRef.current) return;
        setLoading(false);
        setChecklistLoading(false);
      });
    return () => { disposed = true; };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [session, isPlatformAdmin, isAgencyViewing, tenantContextToken]);

  useEffect(() => {
    if (!session || !canViewWhatsAppSettings || activeView !== 'whatsappSettings') return;
    refreshWhatsAppSettings(session.token, tenantContextToken).catch((err) => console.error(err));
  }, [session, canViewWhatsAppSettings, activeView, tenantContextToken]);

  useEffect(() => {
    if (!session || !canManageWhatsAppSettings || activeView !== 'whatsappSettings') return;

    const flow = new WhatsAppCoexistenceFlow<WhatsAppSettings>({
      // Identificadores públicos vêm do backend (GET /settings/whatsapp) e,
      // como fallback, das variáveis VITE_ definidas no build.
      appId: whatsAppSettings?.coexistence_app_id || import.meta.env.VITE_META_APP_ID || '',
      configId: whatsAppSettings?.coexistence_config_id || import.meta.env.VITE_META_COEXISTENCE_CONFIG_ID || '',
      extras: whatsAppSettings?.coexistence_feature_type
        ? {
            featureType: whatsAppSettings.coexistence_feature_type,
            sessionInfoVersion: whatsAppSettings.coexistence_session_info_version || '3',
          }
        : undefined,
      complete: (payload) => completeWhatsAppCoexistence(session.token, payload),
    });
    whatsAppCoexistenceFlowRef.current = flow;

    return () => {
      flow.dispose();
      if (whatsAppCoexistenceFlowRef.current === flow) whatsAppCoexistenceFlowRef.current = null;
    };
  }, [
    session,
    canManageWhatsAppSettings,
    activeView,
    whatsAppSettings?.coexistence_app_id,
    whatsAppSettings?.coexistence_config_id,
    whatsAppSettings?.coexistence_feature_type,
    whatsAppSettings?.coexistence_session_info_version,
  ]);

  useEffect(() => {
    if (!session || !selectedPipelineId || (isPlatformAdmin && !isAgencyViewing) || (!isAgencyViewing && !hasPermission('crm.view'))) return;
    refreshKanban(session.token, selectedPipelineId, false, tenantContextToken).catch((err) => console.error(err));
  }, [session, selectedPipelineId, isPlatformAdmin, isAgencyViewing, tenantContextToken]);

  useEffect(() => {
    if (!session || (isPlatformAdmin && !isAgencyViewing) || (!isAgencyViewing && !hasPermission('contacts.view'))) return;
    refreshContacts(session.token, contactsPage, tenantContextToken).catch((err) => console.error(err));
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [session, contactsPage, contactSearch, contactSourceFilter, contactClassificationFilter, contactStageFilter, isPlatformAdmin, isAgencyViewing, tenantContextToken]);

  useEffect(() => {
    if (!session || (isPlatformAdmin && !isAgencyViewing) || (!isAgencyViewing && !hasPermission('conversations.view'))) return;
    refreshInboxConversations(session.token, inboxPage, false, tenantContextToken).catch((err) => console.error(err));
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [session, inboxPage, inboxSearch, inboxOwnerFilter, inboxSourceFilter, inboxStageFilter, inboxServiceWindowFilter, isPlatformAdmin, isAgencyViewing, tenantContextToken]);

  useEffect(() => {
    if (!session || !canManageCrm || isPlatformAdmin) {
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
  }, [session, canManageCrm, isPlatformAdmin]);

  useEffect(() => {
    if (!session || !selectedConversationId || (isPlatformAdmin && !isAgencyViewing)) return;
    setInboxOwnerUpdateError(null);
    setInboxOwnerUpdateSuccess(null);
    setInboxOwnerSelection('');
    setInboxDetailTab('messages');
    setInboxEvents([]);
    setInboxEventsError(null);
    setInboxEventsLoading(false);
    refreshInboxDetail(session.token, selectedConversationId, false, tenantContextToken).catch((err) => console.error(err));
  }, [session, selectedConversationId, isPlatformAdmin, isAgencyViewing, tenantContextToken]);

  useEffect(() => {
    if (!session || (isPlatformAdmin && !isAgencyViewing)) return;

    return startPolling(async () => {
      // The ref also protects against a cycle still running after a view/filter change.
      if (pollingInFlightRef.current || document.hidden) return;
      pollingInFlightRef.current = true;
      try {
        if (activeView === 'inbox') {
          await settleRefreshes([
            refreshInboxConversations(session.token, inboxPage, true, tenantContextToken),
            ...(selectedConversationId
              ? [refreshInboxDetail(session.token, selectedConversationId, true, tenantContextToken)]
              : []),
          ]);
        } else if (activeView === 'kanban' && selectedPipelineId) {
          await refreshKanban(session.token, selectedPipelineId, true, tenantContextToken);
        } else if (activeView === 'dashboard') {
          await refreshData(session.token, tenantContextToken);
        }
      } finally {
        pollingInFlightRef.current = false;
      }
    }, (err) => console.error(err));
  }, [session, activeView, inboxPage, selectedConversationId, selectedPipelineId, isPlatformAdmin, isAgencyViewing, tenantContextToken, inboxSearch, inboxOwnerFilter, inboxSourceFilter, inboxStageFilter, inboxServiceWindowFilter]);

  useEffect(() => {
    if (!session || !selectedConversationId || inboxDetailTab !== 'audit' || (isPlatformAdmin && !isAgencyViewing)) return;
    if (inboxEventsLoading || inboxEvents.length > 0 || inboxEventsError) return;

    setInboxEventsLoading(true);
    setInboxEventsError(null);

    fetchInboxEvents(session.token, selectedConversationId, tenantContextToken)
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
  }, [session, selectedConversationId, inboxDetailTab, inboxEventsLoading, inboxEvents.length, inboxEventsError, isPlatformAdmin, isAgencyViewing, tenantContextToken]);

  useEffect(() => {
    if (!session) return;
    if (session.user.role === 'platform_admin') setActiveView('adminSaas');
    else if (hasPermission('dashboard.view')) setActiveView('dashboard');
    else if (hasPermission('whatsapp_settings.view')) setActiveView('whatsappSettings');
  }, [session]);

  useEffect(() => {
    if (!session || !isPlatformAdmin || isAgencyViewing) return;
    refreshAdminCompanies(session.token).catch((err) => console.error(err));
  }, [session, isPlatformAdmin, isAgencyViewing]);

  useEffect(() => {
    const allowedViews: ActiveView[] = isPlatformAdmin
      ? isAgencyViewing
        ? ['dashboard', 'attendance', 'inbox', 'checklist', 'kanban', 'contacts', 'intelligence', 'campaignIntelligence', 'whatsappSettings']
        : ['adminSaas', 'adminUsers', 'adminAccess']
      : [
        ...(hasPermission('dashboard.view') ? ['dashboard' as ActiveView] : []),
        ...(hasPermission('attendance.view') ? ['attendance' as ActiveView] : []),
        ...(hasPermission('conversations.view') ? ['inbox' as ActiveView] : []),
        ...(hasPermission('followups.view') ? ['checklist' as ActiveView] : []),
        ...(hasPermission('crm.view') ? ['kanban' as ActiveView] : []),
        ...(hasPermission('contacts.view') ? ['contacts' as ActiveView] : []),
        ...(hasPermission('conversation_intelligence.view') ? ['intelligence' as ActiveView] : []),
        ...(hasPermission('campaign_intelligence.view') ? ['campaignIntelligence' as ActiveView] : []),
        ...(canViewWhatsAppSettings ? (['whatsappSettings'] as ActiveView[]) : []),
      ];

    if (!allowedViews.includes(activeView)) {
      setActiveView(allowedViews[0]);
    }
  }, [activeView, canManageWhatsAppSettings, isPlatformAdmin, isAgencyViewing]);

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

  function clearTenantData() {
    setOverview(null);
    setDashboard(null);
    setChecklistItems([]);
    setChecklistLoading(false);
    setChecklistError(null);
    setCopyFeedback(null);
    setPipelines([]);
    setSelectedPipelineId(null);
    setKanban(null);
    setKanbanLoading(false);
    setKanbanError(null);
    setContacts([]);
    setContactsMeta({ page: 1, per_page: 10, total: 0, last_page: 1 });
    setContactsPage(1);
    setContactsLoading(false);
    setContactsError(null);
    setContactSearch('');
    setContactSearchInput('');
    setContactSourceFilter('');
    setContactClassificationFilter('');
    setContactStageFilter('');
    setContactsExportLoading(false);
    setContactsExportError(null);
    setContactsExportSuccess(null);
    setInboxConversations([]);
    setInboxMeta({ page: 1, per_page: 20, total: 0, last_page: 1 });
    setInboxPage(1);
    setInboxLoading(false);
    setInboxError(null);
    setInboxSearch('');
    setInboxSearchInput('');
    setInboxOwnerFilter('');
    setInboxSourceFilter('');
    setInboxStageFilter('');
    setInboxServiceWindowFilter('');
    setInboxDetail(null);
    setInboxDetailLoading(false);
    setInboxDetailError(null);
    setSelectedConversationId(null);
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
    setIntelligenceConversationId(null);
    setWhatsAppSettings(null);
    setWhatsAppLoading(false);
    setWhatsAppConnecting(false);
    setWhatsAppError(null);
    setWhatsAppSuccess(null);
    setLoading(false);
    setError(null);
  }

  async function handleOpenAdminCompany(company: AdminCompanyListItem) {
    if (!session || !isPlatformAdmin || !company.active || adminViewContextLoadingCompanyId !== null) return;

    setAdminViewContextLoadingCompanyId(company.id);
    setAdminCompaniesError(null);
    try {
      const response = await createAdminTenantViewContext(session.token, company.id);
      tenantDataEpochRef.current += 1;
      clearTenantData();
      setAdminViewContext(response.data);
      setActiveView('dashboard');
    } catch (err) {
      setAdminCompaniesError(parseApiErrorMessage(err, 'Não foi possível abrir a clínica.'));
    } finally {
      setAdminViewContextLoadingCompanyId(null);
    }
  }

  async function handleBackToAdminCompanies() {
    if (!session || !adminViewContext || adminViewContextExitLoading) return;

    const contextToken = adminViewContext.context_token;
    tenantDataEpochRef.current += 1;
    setAdminViewContextExitLoading(true);
    try {
      await revokeAdminTenantViewContext(session.token, contextToken);
    } catch (err) {
      console.error(err);
    } finally {
      clearTenantData();
      setAdminViewContext(null);
      setActiveView('adminSaas');
      setAdminViewContextExitLoading(false);
      await refreshAdminCompanies(session.token).catch((err) => console.error(err));
    }
  }

  async function logout() {
    const currentSession = session;
    const currentContext = adminViewContext;
    tenantDataEpochRef.current += 1;
    if (currentSession && currentContext) {
      await revokeAdminTenantViewContext(currentSession.token, currentContext.context_token).catch((err) => console.error(err));
    }

    clearTenantData();
    setSession(null);
    setAdminViewContext(null);
    setAdminViewContextLoadingCompanyId(null);
    setAdminViewContextExitLoading(false);
    setAdminCompanies([]);
    setAdminCompaniesLoading(false);
    setAdminCompaniesError(null);
    setAdminCompanyCreateLoading(false);
    setAdminCompanyCreateError(null);
    setAdminCompanyCreateSuccess(null);
    setAdminCompanyTokenInfo(null);
    localStorage.removeItem(STORAGE_KEY);
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
    if (!session || !canExportContacts) return;
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

      setContactsExportSuccess('Exportação iniciada.');
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

  function getAdminRecipientEmail(): string {
    return adminForm.settings.daily_report_recipient.trim() || adminForm.admin_user.email.trim();
  }

  function getAdminFormValidationError(): string | null {
    if (!adminForm.company.name.trim()) return 'Informe o nome da empresa.';
    if (!adminForm.company.slug.trim()) return 'Informe o identificador da empresa.';
    if (!adminForm.admin_user.name.trim()) return 'Informe o nome do administrador inicial.';
    if (!adminForm.admin_user.email.trim()) return 'Informe o e-mail do administrador inicial.';
    if (!adminForm.admin_user.password.trim()) return 'Informe a senha do administrador inicial.';
    if (!getAdminRecipientEmail()) return 'Informe o e-mail que receberá o relatório diário.';
    if (!EMAIL_PATTERN.test(getAdminRecipientEmail())) return 'Informe um e-mail válido para o relatório diário.';
    if (adminForm.settings.repeated_lead_window_days < 1) return 'Janela de lead repetido deve ser maior que zero.';
    if (adminForm.settings.rescue_threshold_hours < 1) return 'O limite para resgate deve ser maior que zero.';
    if (adminForm.settings.first_response_sla_minutes < 1) return 'SLA de primeira resposta deve ser maior que zero.';
    if (adminForm.settings.follow_up_sla_hours < 1) return 'O prazo de acompanhamento deve ser maior que zero.';
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
        settings: {
          ...adminForm.settings,
          daily_report_recipient: getAdminRecipientEmail().toLowerCase(),
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
        settings: { ...prev.settings, daily_report_recipient: '' },
      }));

      await refreshAdminCompanies(session.token);
    } catch (err) {
      setAdminCompanyCreateError(parseApiErrorMessage(err, 'Não foi possível criar a empresa.'));
    } finally {
      setAdminCompanyCreateLoading(false);
    }
  }

  function openAdminCompanyEdit(company: AdminCompanyListItem) {
    setAdminCompanyEdit(company);
    setAdminEditName(company.name);
    setAdminEditSlug(company.slug);
    setAdminEditActive(company.active);
    setAdminRecipientValue(company.daily_report_recipient ?? '');
    setAdminCompanyEditError(null);
  }

  async function handleSaveAdminCompany() {
    if (!session || !adminCompanyEdit) return;

    const name = adminEditName.trim();
    const slug = adminEditSlug.trim().toLowerCase();
    const recipient = adminRecipientValue.trim().toLowerCase();

    if (!name) {
      setAdminCompanyEditError('Informe o nome da empresa.');
      return;
    }
    if (!slug) {
      setAdminCompanyEditError('Informe o identificador da empresa.');
      return;
    }
    if (!SLUG_PATTERN.test(slug)) {
      setAdminCompanyEditError('O identificador aceita apenas letras, números, hífen e sublinhado.');
      return;
    }
    if (!EMAIL_PATTERN.test(recipient)) {
      setAdminCompanyEditError('Informe um e-mail válido para o relatório diário.');
      return;
    }

    const payload: AdminCompanyUpdateRequest = {};
    if (name !== adminCompanyEdit.name) payload.name = name;
    if (slug !== adminCompanyEdit.slug) payload.slug = slug;
    if (adminEditActive !== adminCompanyEdit.active) payload.active = adminEditActive;
    if (recipient !== (adminCompanyEdit.daily_report_recipient ?? '')) payload.daily_report_recipient = recipient;

    if (Object.keys(payload).length === 0) {
      setAdminCompanyEdit(null);
      return;
    }

    setAdminCompanyEditLoading(true);
    setAdminCompanyEditError(null);
    try {
      await updateAdminCompany(session.token, adminCompanyEdit.id, payload);
      setAdminCompanyEdit(null);
      setAdminRecipientValue('');
      await refreshAdminCompanies(session.token);
    } catch (err) {
      setAdminCompanyEditError(parseApiErrorMessage(err, 'Não foi possível atualizar a clínica.'));
    } finally {
      setAdminCompanyEditLoading(false);
    }
  }

  async function handleDisconnectWhatsApp() {
    if (!session || !canManageWhatsAppSettings || whatsAppDisconnecting || whatsAppConnecting || whatsAppSyncLoading) return;
    const epoch = tenantDataEpochRef.current;
    setWhatsAppDisconnecting(true);
    setWhatsAppError(null);
    setWhatsAppSuccess(null);
    try {
      const settings = await disconnectWhatsApp(session.token);
      if (epoch !== tenantDataEpochRef.current) return;
      tenantDataEpochRef.current += 1;
      setWhatsAppSettings(settings);
      setOverview((previous) => previous ? { ...previous, whatsapp_status: 'not_configured' } : previous);
      setConfirmWhatsAppDisconnect(false);
      setWhatsAppSuccess('WhatsApp desconectado do LeadsWhats. Seus dados importados foram preservados.');
    } catch (err) {
      if (epoch === tenantDataEpochRef.current) {
        setWhatsAppError(parseApiErrorMessage(err, 'Não foi possível desconectar o WhatsApp.'));
        setConfirmWhatsAppDisconnect(false);
      }
    } finally {
      setWhatsAppDisconnecting(false);
    }
  }

  async function handleConnectWhatsAppCoexistence() {
    if (!session || !canManageWhatsAppSettings) return;

    const flow = whatsAppCoexistenceFlowRef.current;
    const hasPublicIds = Boolean(
      (whatsAppSettings?.coexistence_app_id || import.meta.env.VITE_META_APP_ID)
      && (whatsAppSettings?.coexistence_config_id || import.meta.env.VITE_META_COEXISTENCE_CONFIG_ID),
    );

    if (!hasPublicIds) {
      setWhatsAppError('A conexão com a Meta ainda não foi configurada para este ambiente.');
      setWhatsAppSuccess(null);
      return;
    }

    if (!flow) {
      setWhatsAppError('Não foi possível preparar a conexão com a Meta. Recarregue a página e tente novamente.');
      setWhatsAppSuccess(null);
      return;
    }

    setWhatsAppConnecting(true);
    setWhatsAppError(null);
    setWhatsAppSuccess(null);
    try {
      const updatedSettings = await flow.start();
      setWhatsAppSettings(updatedSettings);
      setWhatsAppSuccess(
        updatedSettings.is_coexistence
          ? 'WhatsApp conectado em coexistência com o aplicativo WhatsApp Business.'
          : 'WhatsApp conectado com sucesso.',
      );
      if (hasPermission('dashboard.view')) await refreshData(session.token).catch(() => undefined);
    } catch (err) {
      setWhatsAppError(
        err instanceof CoexistenceFlowError
          ? err.message
          : 'Não foi possível concluir a conexão com o WhatsApp. Tente novamente.',
      );
    } finally {
      setWhatsAppConnecting(false);
    }
  }

  async function handleRequestCoexistenceSync(syncType: 'contacts' | 'history' | 'both') {
    if (!session || !canManageWhatsAppSettings) return;

    setWhatsAppSyncLoading(true);
    setWhatsAppError(null);
    setWhatsAppSuccess(null);
    try {
      const updatedSettings = await requestWhatsAppCoexistenceSync(session.token, syncType);
      setWhatsAppSettings(updatedSettings);
      setWhatsAppSuccess(
        syncType === 'history'
          ? 'Sincronização do histórico solicitada à Meta.'
          : syncType === 'contacts'
            ? 'Sincronização de contatos solicitada à Meta.'
            : 'Sincronização de contatos e histórico solicitada à Meta.',
      );
    } catch (err) {
      setWhatsAppError(parseApiErrorMessage(err, 'Não foi possível solicitar a sincronização à Meta.'));
    } finally {
      setWhatsAppSyncLoading(false);
    }
  }

  if (!session) {
    return (
      <main className="lw-login-shell">
        <Card className="lw-login-card">
          <PageHeader title="LEADSWHATS" subtitle="Entre para acessar a Visão Geral da operação." />
          <form onSubmit={handleLogin} className="lw-grid-3">
            <FormGroup label="E-mail">
              <Input value={email} onChange={(e) => setEmail(e.target.value)} placeholder="E-mail" />
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

  if (session.user.must_change_password) {
    return <PasswordChangePage token={session.token} onChanged={(user) => {
      const next = { ...session, user };
      setSession(next);
      localStorage.setItem(STORAGE_KEY, JSON.stringify(next));
    }} />;
  }

  const navSections: Array<{ id: ActiveView; label: string; subtitle: string }> = isPlatformAdmin
    ? isAgencyViewing
      ? [
        { id: 'dashboard', label: 'Visão Geral', subtitle: 'Indicadores e prioridades comerciais' },
        { id: 'attendance', label: 'Atendimento', subtitle: 'Inbox operacional em tempo real' },
        { id: 'inbox', label: 'Conversas', subtitle: 'Histórico e auditoria' },
        { id: 'checklist', label: 'Acompanhamento', subtitle: 'Pendências e próximos contatos' },
        { id: 'kanban', label: 'Auto-CRM', subtitle: 'Funil orientado por inteligência' },
        { id: 'contacts', label: 'Contatos', subtitle: 'Busca de contatos' },
        { id: 'intelligence', label: 'Inteligência de Conversas', subtitle: 'Qualidade, intenção e oportunidades' },
        { id: 'campaignIntelligence', label: 'Inteligência da Campanha', subtitle: 'Períodos, volume, resgates e qualidade' },
        { id: 'whatsappSettings', label: 'Configurações', subtitle: 'Situação da integração WhatsApp' },
      ]
      : [
        { id: 'adminSaas', label: 'Clínicas', subtitle: 'Central da Agência' },
        { id: 'adminUsers', label: 'Usuários', subtitle: 'Usuários das clínicas' },
        { id: 'adminAccess', label: 'Controle de acesso', subtitle: 'Perfis e permissões' },
      ]
    : [
      ...(hasPermission('dashboard.view') ? [{ id: 'dashboard' as ActiveView, label: 'Visão Geral', subtitle: 'Indicadores e prioridades comerciais' }] : []),
      ...(hasPermission('attendance.view') ? [{ id: 'attendance' as ActiveView, label: 'Atendimento', subtitle: 'Responder mensagens do WhatsApp' }] : []),
      ...(hasPermission('conversations.view') ? [{ id: 'inbox' as ActiveView, label: 'Conversas', subtitle: 'Histórico somente leitura e auditoria' }] : []),
      ...(hasPermission('followups.view') ? [{ id: 'checklist' as ActiveView, label: 'Acompanhamento', subtitle: 'Pendências e próximos contatos' }] : []),
      ...(hasPermission('crm.view') ? [{ id: 'kanban' as ActiveView, label: 'Auto-CRM', subtitle: 'Funil orientado por inteligência' }] : []),
      ...(hasPermission('contacts.view') ? [{ id: 'contacts' as ActiveView, label: 'Contatos', subtitle: 'Busca e exportação' }] : []),
      ...(hasPermission('conversation_intelligence.view') ? [{ id: 'intelligence' as ActiveView, label: 'Inteligência de Conversas', subtitle: 'Qualidade, intenção e oportunidades das conversas' }] : []),
      ...(hasPermission('campaign_intelligence.view') ? [{ id: 'campaignIntelligence' as ActiveView, label: 'Inteligência da Campanha', subtitle: 'Comparação de períodos, resgates e qualidade' }] : []),
      ...(canViewWhatsAppSettings ? [{ id: 'whatsappSettings' as ActiveView, label: 'Configurações', subtitle: 'Integração WhatsApp' }] : []),
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
            <small className="lw-side-subtitle">Inteligência Comercial</small>
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
            <Badge variant="info">{formatRoleLabel(session.user.role)}</Badge>
            <button
              type="button"
              className="lw-theme-toggle lw-mt-2"
              onClick={() => setTheme(theme === 'dark' ? 'light' : 'dark')}
            >
              {theme === 'dark' ? '☀️ Modo Claro' : '🌙 Modo Escuro'}
            </button>
          </div>
        </Sidebar>

        <div className={`lw-shell-main ${activeView === 'kanban' ? 'lw-shell-main--kanban' : ''}`}>
          <Topbar
            left={(
              <PageHeader
                title={activeNav.label}
                subtitle={overview
                  ? activeView === 'dashboard'
                    ? `${overview.company.name} · Hoje`
                    : `${activeNav.subtitle} · ${overview.company.name}`
                  : activeNav.subtitle}
              />
            )}
            right={(
              <div className="lw-topbar-actions">
                {overview?.demo_mode ? <Badge variant="info">Modo demonstração</Badge> : null}
                <Button onClick={() => void logout()}>Sair</Button>
              </div>
            )}
          />

          {isAgencyViewing && adminViewContext ? (
            <div className="lw-agency-context-banner" role="status" aria-live="polite">
              <div>
                <span>Visualizando:</span>
                <strong>{adminViewContext.company.name}</strong>
                <Badge variant="neutral">Somente leitura</Badge>
                <small>Contexto válido até {formatDateTime(adminViewContext.expires_at)}</small>
              </div>
              <Button
                type="button"
                variant="secondary"
                onClick={() => void handleBackToAdminCompanies()}
                disabled={adminViewContextExitLoading}
              >
                {adminViewContextExitLoading ? 'Encerrando...' : 'Voltar para clínicas'}
              </Button>
            </div>
          ) : null}

          {loading ? <LoadingState message="Carregando dados..." /> : null}
          {error ? <ErrorState message={error} /> : null}

          <>
              {activeView === 'adminSaas' ? (
            <>
              <Section>
                <PageHeader title="Clínicas" subtitle="Central da Agência para gestão das unidades cadastradas" />
                <div className="lw-metrics-grid lw-mt-3">
                  <MetricCard label="Total de empresas" value={adminSummary.total} />
                  <MetricCard label="Empresas configuradas" value={adminSummary.withSettings} />
                  <MetricCard label="Empresas com funil" value={adminSummary.withPipelines} />
                </div>
              </Section>

              <Section>
                <h2>Cadastrar clínica</h2>
                <form onSubmit={(event) => void handleCreateAdminCompany(event)} className="lw-admin-form">
                  <Card>
                    <h3>Empresa</h3>
                    <div className="lw-admin-grid">
                      <FormGroup label="Nome da empresa">
                        <Input value={adminForm.company.name} onChange={(e) => updateAdminForm('company.name', e.target.value)} placeholder="Empresa Exemplo Ltda" />
                      </FormGroup>
                      <FormGroup label="Identificador da empresa">
                        <Input value={adminForm.company.slug} onChange={(e) => updateAdminForm('company.slug', e.target.value)} placeholder="empresa-exemplo" />
                      </FormGroup>
                    </div>
                  </Card>

                  <Card>
                    <h3>Administrador inicial</h3>
                    <div className="lw-admin-grid">
                      <FormGroup label="Nome">
                        <Input value={adminForm.admin_user.name} onChange={(e) => updateAdminForm('admin_user.name', e.target.value)} placeholder="Nome do administrador" />
                      </FormGroup>
                      <FormGroup label="E-mail">
                        <Input type="email" value={adminForm.admin_user.email} onChange={(e) => updateAdminForm('admin_user.email', e.target.value)} placeholder="admin@empresa.com" />
                      </FormGroup>
                      <FormGroup label="Senha">
                        <Input type="password" value={adminForm.admin_user.password} onChange={(e) => updateAdminForm('admin_user.password', e.target.value)} placeholder="Senha temporária" />
                      </FormGroup>
                    </div>
                  </Card>

                  <Card>
                    <h3>Relatório diário de IA</h3>
                    <div className="lw-admin-grid">
                      <FormGroup
                        label="E-mail destinatário"
                        hint="Recebe o relatório todo dia às 18:30 (fuso horário da clínica). Deixe vazio para usar o e-mail do administrador inicial."
                      >
                        <Input
                          type="email"
                          value={adminForm.settings.daily_report_recipient}
                          onChange={(e) => updateAdminForm('settings.daily_report_recipient', e.target.value)}
                          placeholder={adminForm.admin_user.email || 'relatorios@clinica.com'}
                        />
                      </FormGroup>
                    </div>
                  </Card>

                  <Card>
                    <h3>Configurações operacionais</h3>
                    <div className="lw-admin-grid">
                      <FormGroup label="Fuso horário">
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
                      <FormGroup label="Limite para resgate (h)">
                        <Input type="number" min={1} value={adminForm.settings.rescue_threshold_hours} onChange={(e) => updateAdminForm('settings.rescue_threshold_hours', Number(e.target.value || 0))} />
                      </FormGroup>
                      <FormGroup label="Prazo da primeira resposta (min)">
                        <Input type="number" min={1} value={adminForm.settings.first_response_sla_minutes} onChange={(e) => updateAdminForm('settings.first_response_sla_minutes', Number(e.target.value || 0))} />
                      </FormGroup>
                      <FormGroup label="Prazo de acompanhamento (h)">
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
                      Token do webhook: {adminCompanyTokenInfo.configured ? 'configurado' : 'não configurado'} · Token protegido: {adminCompanyTokenInfo.masked || 'não disponível'}
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
                <h2>Clínicas cadastradas</h2>
                {adminCompaniesLoading ? <LoadingState message="Carregando empresas..." /> : null}
                {adminCompaniesError ? <ErrorState message={adminCompaniesError} /> : null}
                {!adminCompaniesLoading && !adminCompaniesError && adminCompanies.length === 0 ? (
                  <EmptyState title="Nenhuma empresa cadastrada." description="Crie a primeira empresa usando o formulário acima." />
                ) : null}
                {!adminCompaniesLoading && !adminCompaniesError && adminCompanies.length > 0 ? (
                  <Table>
                    <thead>
                      <tr>
                        <th>Código</th>
                        <th>Nome</th>
                        <th>Identificador</th>
                        <th>Status</th>
                        <th>Usuários</th>
                        <th>Funis</th>
                        <th>Configurações operacionais</th>
                        <th>Relatório diário</th>
                        <th>Criada em</th>
                        <th>Ações</th>
                      </tr>
                    </thead>
                    <tbody>
                      {adminCompanies.map((company) => (
                        <tr key={company.id}>
                          <td>{company.id}</td>
                          <td>{company.name}</td>
                          <td>{company.slug}</td>
                          <td><Badge variant={company.active ? 'success' : 'warning'}>{company.active ? 'Ativa' : 'Inativa'}</Badge></td>
                          <td>{company.users_count}</td>
                          <td>{company.pipelines_count}</td>
                          <td><Badge variant={company.has_business_settings ? 'success' : 'warning'}>{company.has_business_settings ? 'Sim' : 'Não'}</Badge></td>
                          <td>
                            {company.daily_report_recipient ? (
                              <Badge variant="success">{company.daily_report_recipient}</Badge>
                            ) : (
                              <Badge variant="warning">sem e-mail</Badge>
                            )}
                          </td>
                          <td>{formatDateTime(company.created_at)}</td>
                          <td>
                            <Button
                              type="button"
                              variant="secondary"
                              disabled={!company.active || adminViewContextLoadingCompanyId !== null}
                              onClick={() => void handleOpenAdminCompany(company)}
                            >
                              {adminViewContextLoadingCompanyId === company.id ? 'Abrindo...' : 'Abrir clínica'}
                            </Button>
                            <Button
                              type="button"
                              variant="secondary"
                              style={{ marginLeft: 8 }}
                              onClick={() => openAdminCompanyEdit(company)}
                            >
                              Editar
                            </Button>
                          </td>
                        </tr>
                      ))}
                    </tbody>
                  </Table>
                ) : null}
              </Section>

              <Modal
                open={adminCompanyEdit !== null}
                onClose={() => setAdminCompanyEdit(null)}
                title={adminCompanyEdit ? `Editar clínica — ${adminCompanyEdit.name}` : 'Editar clínica'}
              >
                {adminCompanyEdit ? (
                  <div className="lw-admin-grid">
                    <FormGroup label="Nome da empresa">
                      <Input
                        value={adminEditName}
                        onChange={(e) => setAdminEditName(e.target.value)}
                        placeholder="Nome da empresa"
                      />
                    </FormGroup>
                    <FormGroup
                      label="Identificador (slug)"
                      hint="Usado em URLs e integrações. Aceita letras minúsculas, números, hífen e sublinhado."
                    >
                      <Input
                        value={adminEditSlug}
                        onChange={(e) => setAdminEditSlug(e.target.value)}
                        placeholder="identificador-da-empresa"
                      />
                    </FormGroup>
                    <FormGroup label="Status">
                      <Select value={adminEditActive ? 'active' : 'inactive'} onChange={(e) => setAdminEditActive(e.target.value === 'active')}>
                        <option value="active">Ativa</option>
                        <option value="inactive">Inativa</option>
                      </Select>
                    </FormGroup>
                    <FormGroup
                      label="E-mail do relatório diário"
                      hint="Enviado todo dia às 18:30 (fuso horário da clínica)."
                    >
                      <Input
                        type="email"
                        value={adminRecipientValue}
                        onChange={(e) => setAdminRecipientValue(e.target.value)}
                        placeholder="relatorios@clinica.com"
                      />
                    </FormGroup>
                    {adminCompanyEditError ? <ErrorState message={adminCompanyEditError} /> : null}
                    <div>
                      <Button type="button" disabled={adminCompanyEditLoading} onClick={() => void handleSaveAdminCompany()}>
                        {adminCompanyEditLoading ? 'Salvando...' : 'Salvar alterações'}
                      </Button>
                    </div>
                  </div>
                ) : null}
              </Modal>
            </>
          ) : null}

          {activeView === 'adminUsers' && isPlatformAdmin && !isAgencyViewing ? (
            <Section><AdminUsersPage token={session.token} companies={adminCompanies} /></Section>
          ) : null}

          {activeView === 'adminAccess' && isPlatformAdmin && !isAgencyViewing ? (
            <Section><AdminAccessPage token={session.token} companies={adminCompanies} /></Section>
          ) : null}

          {activeView === 'whatsappSettings' && canViewWhatsAppSettings ? (
            <>
              <Section>
                <PageHeader title="Configurações" subtitle="Integração WhatsApp" />
                <div className="lw-metrics-grid lw-mt-3">
                  <MetricCard
                    label="Situação da integração"
                    value={formatWhatsAppStatus(whatsAppSettings?.status)}
                    variant={whatsAppSettings?.status === 'configured' ? 'default' : 'risk'}
                  />
                  <MetricCard label="Provedor" value={formatProviderLabel(whatsAppSettings?.provider)} />
                  <MetricCard
                    label="Conexão oficial"
                    value={whatsAppSettings?.status === 'configured' ? 'Ativa' : 'Pendente'}
                    variant={whatsAppSettings?.status === 'configured' ? 'default' : 'risk'}
                  />
                  <MetricCard
                    label="Última conexão"
                    value={formatDateTime(whatsAppSettings?.connected_at)}
                  />
                </div>
              </Section>

              <Section>
                {whatsAppLoading ? <LoadingState message="Carregando configurações do WhatsApp..." /> : null}
                {whatsAppError ? <ErrorState message={whatsAppError} /> : null}
                {whatsAppSuccess ? <Alert variant="success">{whatsAppSuccess}</Alert> : null}
                {isAgencyViewing ? <Alert variant="info">Consulta administrativa da situação da integração. Nenhuma credencial pode ser alterada neste modo.</Alert> : null}
                {!whatsAppLoading && whatsAppSettings?.status === 'not_configured' ? (
                  <Alert variant="warning">
                    {isAgencyViewing
                      ? 'A integração desta clínica ainda não está configurada.'
                      : 'O WhatsApp ainda não foi conectado à Meta.'}
                  </Alert>
                ) : null}
                {!whatsAppLoading && !whatsAppSuccess && whatsAppSettings?.status === 'configured' ? (
                  <Alert variant="success">WhatsApp conectado. Status: Configurado.</Alert>
                ) : null}
                {!whatsAppLoading && whatsAppSettings?.status === 'error' ? (
                  <Alert variant="danger">A integração precisa de atenção. Tente conectar o WhatsApp novamente.</Alert>
                ) : null}

                {!whatsAppLoading && canManageWhatsAppSettings ? (
                  <Card className="lw-whatsapp-connect-card">
                    <div className="lw-whatsapp-connect-copy">
                      <div className="lw-whatsapp-connect-heading">
                        <h3>{whatsAppSettings?.status === 'configured' ? 'WhatsApp conectado' : 'Conecte o WhatsApp da clínica'}</h3>
                        <Badge variant={whatsAppSettings?.status === 'configured' ? 'success' : 'neutral'}>
                          {whatsAppSettings?.status === 'configured' ? 'Configurado' : 'Não configurado'}
                        </Badge>
                        {whatsAppSettings?.is_coexistence ? <Badge variant="info">Coexistência</Badge> : null}
                      </div>
                      <p>
                        A autorização acontece no ambiente oficial da Meta. O número que já é usado no aplicativo WhatsApp Business é
                        conectado à plataforma e o LEADSWHATS passa a receber as conversas — inclusive as enviadas pelo próprio
                        aplicativo, por meio dos eventos de coexistência.
                      </p>
                      <p className="lw-whatsapp-security-note">
                        Nenhum token de acesso ou segredo da Meta é exibido ou armazenado neste navegador.
                      </p>
                    </div>
                    <div className="lw-flex-wrap-gap">
                    <Button
                      type="button"
                      disabled={whatsAppConnecting || whatsAppDisconnecting || whatsAppSyncLoading}
                      onClick={() => void handleConnectWhatsAppCoexistence()}
                    >
                      {whatsAppConnecting
                        ? 'Conectando...'
                        : whatsAppSettings?.status === 'configured'
                          ? 'Conectar novamente'
                          : 'Conectar WhatsApp (Coexistência)'}
                    </Button>
                    {(whatsAppSettings?.access_token_configured || whatsAppSettings?.waba_id) ? (
                      <Button type="button" variant="secondary"
                        disabled={whatsAppDisconnecting || whatsAppConnecting || whatsAppSyncLoading}
                        onClick={() => setConfirmWhatsAppDisconnect(true)}>
                        {whatsAppDisconnecting ? 'Desconectando...' : 'Desconectar WhatsApp'}
                      </Button>
                    ) : null}
                    </div>
                    <Modal open={confirmWhatsAppDisconnect}
                      onClose={() => { if (!whatsAppDisconnecting) setConfirmWhatsAppDisconnect(false); }}
                      title="Desconectar WhatsApp?">
                      <p>A integração com o LeadsWhats será interrompida. Leads, contatos e conversas já importados serão preservados.</p>
                      <p>Isso não apaga seu WhatsApp do celular nem revoga todas as permissões concedidas na Meta. Você poderá conectar novamente depois.</p>
                      <div className="lw-flex-wrap-gap">
                        <Button type="button" variant="secondary" disabled={whatsAppDisconnecting}
                          onClick={() => setConfirmWhatsAppDisconnect(false)}>Cancelar</Button>
                        <Button type="button" disabled={whatsAppDisconnecting}
                          onClick={() => void handleDisconnectWhatsApp()}>
                          {whatsAppDisconnecting ? 'Desconectando...' : 'Confirmar desconexão'}
                        </Button>
                      </div>
                    </Modal>
                  </Card>
                ) : null}

                {!whatsAppLoading && canManageWhatsAppSettings && whatsAppSettings?.is_coexistence ? (
                  <Card className="lw-whatsapp-coexistence-card">
                    <div className="lw-whatsapp-connect-copy">
                      <div className="lw-whatsapp-connect-heading">
                        <h3>Sincronização do WhatsApp Business</h3>
                        <Badge variant={whatsAppSettings.history_sync_status === 'completed' ? 'success' : 'neutral'}>
                          {formatWhatsAppSyncStatus(whatsAppSettings.history_sync_status, 'Histórico')}
                        </Badge>
                        <Badge variant={whatsAppSettings.contacts_sync_status === 'completed' ? 'success' : 'neutral'}>
                          {formatWhatsAppSyncStatus(whatsAppSettings.contacts_sync_status, 'Contatos')}
                        </Badge>
                      </div>
                      <p>
                        A Meta importa os contatos e as mensagens dos últimos 180 dias do aplicativo WhatsApp Business. O histórico é
                        processado em segundo plano no servidor, sem mover os leads no funil nem afetar os indicadores de tempo real.
                      </p>
                    </div>
                    <Button
                      type="button"
                      disabled={whatsAppSyncLoading || whatsAppDisconnecting || whatsAppConnecting}
                      onClick={() => void handleRequestCoexistenceSync('both')}
                    >
                      {whatsAppSyncLoading ? 'Solicitando...' : 'Sincronizar novamente'}
                    </Button>
                  </Card>
                ) : null}

                {!whatsAppLoading && !whatsAppSettings ? (
                  <EmptyState title="Configuração indisponível no momento." description="Tente recarregar a página e salvar novamente." />
                ) : null}
              </Section>
            </>
          ) : null}

          {activeView === 'dashboard' && overview && dashboard ? (
            <DashboardPage
              overview={overview}
              dashboard={dashboard}
              canViewIntelligence={canViewIntelligence}
              onNavigate={(destination) => setActiveView(destination)}
            />
          ) : null}

      {activeView === 'attendance' ? <AttendancePage token={session.token} tenantContext={tenantContextToken} readOnly={isAgencyViewing || !hasPermission('attendance.respond')} /> : null}

      {activeView === 'inbox' ? (
      <Section>
        <h2>Conversas</h2>

        <form onSubmit={(event) => void applyInboxFilters(event)} className="lw-filter-form lw-mb-3">
          <div className="lw-filter-bar">
            <input
              value={inboxSearchInput}
              onChange={(event) => setInboxSearchInput(event.target.value)}
              placeholder="Buscar conversa por nome ou telefone"
              className="lw-input lw-min-width-260"
            />
            <select value={inboxOwnerFilter} onChange={(event) => setInboxOwnerFilter(event.target.value ? Number(event.target.value) : '')}>
              <option value="">Todos os responsáveis</option>
              {inboxOwnerOptions.map((owner) => (
                <option key={owner.id} value={owner.id}>{owner.name}</option>
              ))}
            </select>
            <select value={inboxSourceFilter} onChange={(event) => setInboxSourceFilter(event.target.value)}>
              <option value="">Todas as origens</option>
              <option value="instagram">Instagram</option>
              <option value="google">Google</option>
              <option value="facebook">Facebook</option>
              <option value="indicacao">Indicação</option>
              <option value="desconhecido">Não identificada</option>
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

        {inboxLoading ? <p className="lw-text-sm-soft">Carregando conversas...</p> : null}
        {inboxError ? <ErrorState message={inboxError} /> : null}

        {!inboxLoading && !inboxError && inboxConversations.length === 0 ? (
          <EmptyState
            title="Nenhuma conversa encontrada."
            description="Novas conversas aparecerão conforme forem recebidas pela integração."
          />
        ) : null}

        {!inboxLoading && !inboxError && inboxConversations.length > 0 ? (
          <>
            <div className="lw-inbox-list">
              {inboxConversations.map((conversation) => (
                <button
                  key={conversation.conversation_id}
                  type="button"
                  onClick={() => setSelectedConversationId(conversation.conversation_id)}
                  className="lw-inbox-list-row"
                >
                  <div className="lw-inbox-list-row-main">
                    <p><strong>{conversation.lead_name || formatPhoneDisplay(conversation.phone)}</strong></p>
                    {conversation.lead_name ? <small>{formatPhoneDisplay(conversation.phone)}</small> : null}
                  </div>
                  <div className="lw-inbox-list-row-meta">
                    <span>Origem: {formatSourceLabel(conversation.source)}</span>
                    <span>
                      Última mensagem {formatDirectionLabel(conversation.last_message_direction)} · {formatDateTime(conversation.last_message_at)}
                    </span>
                  </div>
                  <span className="lw-inbox-list-row-arrow">→</span>
                </button>
              ))}
              <div className="lw-inbox-list-pagination">
                <button onClick={() => changeInboxPage(inboxMeta.page - 1)} disabled={inboxMeta.page <= 1 || inboxLoading}>Anterior</button>
                <small>Página {inboxMeta.page} de {Math.max(inboxMeta.last_page, 1)}</small>
                <button onClick={() => changeInboxPage(inboxMeta.page + 1)} disabled={inboxMeta.page >= inboxMeta.last_page || inboxLoading}>Próxima</button>
                <small className="lw-text-xs-muted">Total: {inboxMeta.total}</small>
              </div>
            </div>

            <Modal
              open={selectedConversationId !== null}
              onClose={() => setSelectedConversationId(null)}
              title={inboxDetail ? `Conversa #${inboxDetail.conversation_id}` : 'Conversa'}
            >
              <>
                {inboxDetailLoading ? <p className="lw-text-sm-soft">Carregando conversa...</p> : null}
                {inboxDetailError ? <ErrorState message={inboxDetailError} /> : null}

                {!inboxDetailLoading && !inboxDetailError && inboxDetail ? (
                  <>
                    <div className="lw-inbox-detail-info">
                      <small><strong>Lead:</strong> {inboxDetail.lead.lead_name || 'Sem nome'}</small>
                      <small><strong>Telefone:</strong> {formatPhoneDisplay(inboxDetail.lead.phone)}</small>
                      <small><strong>Origem:</strong> {formatSourceLabel(inboxDetail.lead.source)}</small>
                      <small><strong>Etapa:</strong> {inboxDetail.lead.current_stage || 'Sem etapa'}</small>
                      <small><strong>Responsável:</strong> {inboxDetail.owner.owner_name || 'Sem responsável'}</small>
                    </div>
                    {canManageCrm ? (
                      <form onSubmit={(event) => void handleUpdateInboxOwner(event)} className="lw-grid-2 lw-mt-2 lw-mb-2">
                        <div className="lw-flex-wrap-gap lw-flex-align-center-gap">
                          <label className="lw-flex-align-center-gap lw-m-0">
                            Alterar responsável:
                            <select
                              value={inboxOwnerSelection}
                              onChange={(event) => setInboxOwnerSelection(event.target.value ? Number(event.target.value) : '')}
                              disabled={inboxOwnerUpdateLoading}
                              className="lw-select lw-max-width-280"
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
                        <small className="lw-text-xs-muted">
                          Registro no histórico: responsável alterado pela área de Conversas.
                        </small>
                        {assignableUsersError ? (
                          <small className="lw-text-xs-warning">
                            {assignableUsersError}
                          </small>
                        ) : null}
                        {!assignableUsersError && canManageCrm && inboxAssignableOwnerOptions.length === 0 ? (
                          <small className="lw-text-xs-warning">
                            Nenhum usuário atribuível encontrado. Verifique usuários ativos da empresa.
                          </small>
                        ) : null}
                        {inboxOwnerUpdateError ? <small className="lw-text-xs-danger">{inboxOwnerUpdateError}</small> : null}
                        {inboxOwnerUpdateSuccess ? <small className="lw-text-xs-success">{inboxOwnerUpdateSuccess}</small> : null}
                      </form>
                    ) : null}
                    <small className="lw-inbox-detail-window">
                      <strong>Janela:</strong> {inboxDetail.service_window_open ? 'Aberta' : 'Fechada'}
                      {inboxDetail.service_window_expires_at ? ` · expira em ${formatDateTime(inboxDetail.service_window_expires_at)}` : ''}
                    </small>

                    <div className="lw-flex-wrap-gap lw-mb-2">
                      <button
                        type="button"
                        onClick={() => setInboxDetailTab('messages')}
                        className={`lw-tab-button ${inboxDetailTab === 'messages' ? 'lw-tab-button--active' : ''}`}
                      >
                        Mensagens
                      </button>
                      <button
                        type="button"
                        onClick={() => setInboxDetailTab('audit')}
                        className={`lw-tab-button ${inboxDetailTab === 'audit' ? 'lw-tab-button--active' : ''}`}
                      >
                        Auditoria
                      </button>
                    </div>

                    {inboxDetailTab === 'messages' ? (
                      <div className="lw-inbox-messages-list">
                        {inboxDetail.messages.map((message) => (
                          <div
                            key={message.id}
                            className={`lw-chat-bubble ${message.direction === 'inbound' ? 'lw-chat-bubble--inbound' : 'lw-chat-bubble--outbound'}`}
                          >
                            <small>
                              <strong>{message.direction === 'inbound' ? 'Cliente' : 'Time'}</strong> · {formatDateTime(message.sent_at)}
                            </small>
                            <p>{message.body || 'Mensagem sem texto'}</p>
                            <small>Provedor: {formatProviderLabel(message.provider)} · Identificador externo: {message.external_message_id || 'não disponível'}</small>
                          </div>
                        ))}
                      </div>
                    ) : null}

                    {inboxDetailTab === 'audit' ? (
                      <div className="lw-inbox-messages-list">
                        {inboxEventsLoading ? <p className="lw-text-sm-soft">Carregando auditoria...</p> : null}
                        {inboxEventsError ? <ErrorState message={inboxEventsError} /> : null}
                        {!inboxEventsLoading && !inboxEventsError && inboxEvents.length === 0 ? (
                          <p className="lw-text-sm-soft">Nenhum evento de auditoria encontrado para esta conversa.</p>
                        ) : null}
                        {!inboxEventsLoading && !inboxEventsError && inboxEvents.length > 0 ? (
                          inboxEvents.map((event) => (
                            <div key={`${event.event_type}-${event.event_id}`} className="lw-audit-item">
                              <small>
                                <strong>{getFriendlyAuditEventType(event.event_type)}</strong> · {formatDateTime(event.occurred_at)}
                              </small>
                              {event.event_type === 'conversation_opened' ? (
                                <p>
                                  Conversa aberta por {event.user_name || 'usuário não identificado'}
                                </p>
                              ) : null}
                              {event.event_type === 'message_sent' ? (
                                <>
                                  <p>
                                    Mensagem enviada por {event.user_name || 'usuário não identificado'}
                                  </p>
                                  {(event.metadata?.provider || event.metadata?.external_message_id) ? (
                                    <small>
                                      Provedor: {formatProviderLabel(typeof event.metadata?.provider === 'string' ? event.metadata.provider : null)} · Identificador externo: {String(event.metadata?.external_message_id || 'não disponível')}
                                    </small>
                                  ) : null}
                                </>
                              ) : null}
                              {event.event_type === 'stage_changed' ? (
                                <>
                                  <p>
                                    Etapa alterada por {event.user_name || 'usuário não identificado'}
                                  </p>
                                  <small>
                                    de {event.metadata?.from_column_name || 'Sem etapa'} para {event.metadata?.to_column_name || 'Sem etapa'}
                                  </small>
                                  <small>
                                    Origem da movimentação: {formatMovementSource(event.metadata?.move_source)}
                                  </small>
                                  {event.metadata?.reason ? (
                                    <small>
                                      Motivo: {formatAuditReason(event.metadata.reason)}
                                    </small>
                                  ) : null}
                                </>
                              ) : null}
                              {(event.event_type === 'owner_assigned' || event.event_type === 'owner_changed' || event.event_type === 'owner_removed') ? (
                                <>
                                  <p>
                                    {event.event_type === 'owner_assigned' ? 'Responsável atribuído' : event.event_type === 'owner_changed' ? 'Responsável alterado' : 'Responsável removido'} por {event.user_name || 'usuário não identificado'}
                                  </p>
                                  <small>
                                    Anterior: {event.metadata?.previous_owner_name || 'Sem responsável'} · Novo: {event.metadata?.new_owner_name || 'Sem responsável'}
                                  </small>
                                  {event.metadata?.reason ? (
                                    <small>
                                      Motivo: {formatAuditReason(event.metadata.reason)}
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
              </>
            </Modal>
          </>
        ) : null}
      </Section>
      ) : null}

      {activeView === 'checklist' ? (
      <Section>
        <h2>Acompanhamento do dia</h2>
        {checklistLoading ? <p>Carregando acompanhamento...</p> : null}
        {checklistError ? <p className="lw-text-xs-danger">{checklistError}</p> : null}
        {copyFeedback ? <p className="lw-text-xs-success">{copyFeedback}</p> : null}

        {!checklistLoading && !checklistError && checklistItems.length === 0 ? <p>Nenhuma tarefa operacional pendente no momento.</p> : null}

        {!checklistLoading && !checklistError && checklistItems.length > 0 ? (
          <div className="lw-contacts-grid">
            {checklistItems.map((item) => (
              <div key={`${item.lead_id}-${item.conversation_id}-${item.task_type}`} className="lw-contact-card">
                <p><strong>{item.lead_name || formatPhoneDisplay(item.phone)}</strong></p>
                <small>Telefone: {formatPhoneDisplay(item.phone)}</small>
                <small>Origem: {formatSourceLabel(item.source)}</small>
                <small>Etapa atual: {item.current_stage || 'Sem etapa'}</small>
                <small>Tarefa: {item.task_label}</small>
                <small>Horas desde última mensagem: {item.hours_since_last_message}</small>
                <small className="lw-mb-2">Prioridade: <strong>{formatPriorityLabel(item.priority)}</strong></small>
                {!isAgencyViewing ? (
                  <div className="lw-flex-wrap-gap">
                    <button onClick={() => void copyText(item.phone, 'Telefone copiado!')}>Copiar telefone</button>
                    <button onClick={() => void copyText(CHECKLIST_DEFAULT_MESSAGE, 'Mensagem padrão copiada!')}>Copiar mensagem padrão</button>
                  </div>
                ) : null}
              </div>
            ))}
          </div>
        ) : null}
      </Section>
      ) : null}

      {activeView === 'kanban' ? (
        <AutoCrmPage
          token={session.token}
          tenantContext={tenantContextToken}
          pipelines={pipelines}
          selectedPipelineId={selectedPipelineId}
          kanban={kanban}
          loading={kanbanLoading}
          error={kanbanError}
          canManage={canManageCrm}
          onPipelineChange={setSelectedPipelineId}
          onRefresh={async () => {
            if (selectedPipelineId) await refreshKanban(session.token, selectedPipelineId, false, tenantContextToken);
          }}
          onOpenConversation={(conversationId) => {
            setSelectedConversationId(conversationId);
            setActiveView('inbox');
          }}
          onOpenIntelligence={(conversationId) => {
            setIntelligenceConversationId(conversationId);
            setActiveView('intelligence');
          }}
        />
      ) : null}

      {activeView === 'contacts' ? (
      <Section>
        <h2>Contatos</h2>

        <form onSubmit={(event) => void applyContactsFilters(event)} className="lw-filter-form lw-mb-3">
          <div className="lw-filter-bar">
            <input value={contactSearchInput} onChange={(event) => setContactSearchInput(event.target.value)} placeholder="Buscar por nome ou telefone" className="lw-input lw-min-width-260" />
            <select className="lw-select" value={contactSourceFilter} onChange={(event) => setContactSourceFilter(event.target.value)}>
              <option value="">Todas as origens</option>
              <option value="instagram">Instagram</option>
              <option value="google">Google</option>
              <option value="facebook">Facebook</option>
              <option value="indicacao">Indicação</option>
              <option value="desconhecido">Não identificada</option>
            </select>
            <select className="lw-select" value={contactClassificationFilter} onChange={(event) => setContactClassificationFilter(event.target.value as '' | 'lead_novo' | 'lead_repetido')}>
              <option value="">Todas as classificações</option>
              <option value="lead_novo">Lead novo</option>
              <option value="lead_repetido">Lead recorrente</option>
            </select>
            <select className="lw-select" value={contactStageFilter} onChange={(event) => setContactStageFilter(event.target.value ? Number(event.target.value) : '')}>
              <option value="">Todas as etapas</option>
              {(kanban?.columns ?? []).map((column) => <option key={column.id} value={column.id}>{column.name}</option>)}
            </select>
            <button type="submit">Aplicar filtros</button>
            {canExportContacts ? (
              <button type="button" onClick={() => void handleExportContactsCsv()} disabled={contactsExportLoading}>
                {contactsExportLoading ? 'Exportando...' : 'Exportar CSV'}
              </button>
            ) : null}
          </div>
        </form>

        {contactsLoading ? <p>Carregando contatos...</p> : null}
        {contactsError ? <p className="lw-text-xs-danger">{contactsError}</p> : null}
        {contactsExportError ? <p className="lw-text-xs-danger">{contactsExportError}</p> : null}
        {contactsExportSuccess ? <p className="lw-text-xs-success">{contactsExportSuccess}</p> : null}

        {!contactsLoading && !contactsError && contacts.length === 0 ? <p>Nenhum contato encontrado com os filtros atuais.</p> : null}

        {!contactsLoading && !contactsError && contacts.length > 0 ? (
          <>
            <div className="lw-contacts-grid">
              {contacts.map((contact) => (
                <div key={contact.lead_id} className="lw-contact-card">
                  <p><strong>{contact.name || formatPhoneDisplay(contact.phone)}</strong></p>
                  <small>Telefone: {formatPhoneDisplay(contact.phone)}</small>
                  <small>Origem: {formatSourceLabel(contact.source)}</small>
                  <small>Classificação: {formatClassificationLabel(contact.classification)}</small>
                  <small>Etapa atual: {contact.current_stage || 'Sem etapa'}</small>
                  <small>Última mensagem: {formatDateTime(contact.last_message_at)}</small>
                  <small className="lw-mb-2">Criado em: {formatDateTime(contact.created_at)}</small>
                  {!isAgencyViewing ? <button onClick={() => void copyText(contact.phone, 'Telefone copiado!')}>Copiar telefone</button> : null}
                </div>
              ))}
            </div>

            <div className="lw-flex-align-center-gap lw-mt-3">
              <button onClick={() => changeContactsPage(contactsMeta.page - 1)} disabled={contactsMeta.page <= 1 || contactsLoading}>Anterior</button>
              <small>Página {contactsMeta.page} de {Math.max(contactsMeta.last_page, 1)}</small>
              <button onClick={() => changeContactsPage(contactsMeta.page + 1)} disabled={contactsMeta.page >= contactsMeta.last_page || contactsLoading}>Próxima</button>
              <small className="lw-text-xs-muted">Total: {contactsMeta.total}</small>
            </div>
          </>
        ) : null}
      </Section>
      ) : null}

      {activeView === 'intelligence' && canViewIntelligence ? (
        <ConversationIntelligencePage
          token={session.token}
          tenantContext={tenantContextToken}
          readOnly={isAgencyViewing || !hasPermission('conversation_intelligence.analyze')}
          initialConversationId={intelligenceConversationId}
        />
      ) : null}

      {activeView === 'campaignIntelligence' && canViewIntelligence ? (
        <CampaignIntelligencePage
          token={session.token}
          tenantContext={tenantContextToken}
          readOnly={isAgencyViewing || !hasPermission('campaign_intelligence.analyze')}
          onOpenConversation={(conversationId) => {
            setIntelligenceConversationId(conversationId);
            setActiveView('intelligence');
          }}
        />
      ) : null}
            </>
        </div>
      </div>
    </AppShell>
  );
}
