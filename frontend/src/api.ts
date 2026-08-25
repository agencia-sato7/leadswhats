import type {
  AdminCompanyCreateRequest,
  AdminCompanyCreateResponse,
  AdminCompanyListItem,
  AdminTenantViewContextResponse,
  ContactItem,
  ContactsResponse,
  ChecklistTaskItem,
  DashboardSummaryResponse,
  AssignableUsersResponse,
  InboxConversationDetail,
  InboxConversationEvent,
  InboxMessageItem,
  InboxConversationsResponse,
  LeadStageHistoryItem,
  LeadSourceItem,
  LeadOwnerUpdateResponse,
  LoginResponse,
  MoveLeadStageResponse,
  KanbanRecommendationResponse,
  OverviewResponse,
  PipelineKanban,
  PipelineListItem,
  CompleteWhatsAppEmbeddedSignupRequest,
  CompleteWhatsAppEmbeddedSignupResponse,
  WhatsAppSettings,
  WhatsAppSettingsResponse,
  UpdateWhatsAppSettingsRequest,
  AnalyzeConversationResponse,
  ConversationIntelligenceDetailResponse,
  ConversationIntelligenceListResponse,
  ConversationIntelligenceSummaryResponse,
  CampaignPreviewResponse,
  CampaignReport,
  CampaignReportLead,
  CampaignReportListResponse,
} from './types';

const API_BASE = import.meta.env.VITE_API_BASE_URL || 'https://leadswhats.appsato7.com.br/api/v1';

function authenticatedHeaders(token: string, tenantContext?: string): Record<string, string> {
  return {
    Authorization: `Bearer ${token}`,
    ...(tenantContext ? { 'X-Tenant-Context': tenantContext } : {}),
  };
}

async function request<T>(path: string, init?: RequestInit): Promise<T> {
  const res = await fetch(`${API_BASE}${path}`, {
    ...init,
    headers: {
      Accept: 'application/json',
      'Content-Type': 'application/json',
      ...(init?.headers ?? {}),
    },
  });

  if (!res.ok) {
    const text = await res.text();
    let apiMessage = '';

    try {
      const payload = JSON.parse(text) as { message?: unknown };
      if (typeof payload.message === 'string') apiMessage = payload.message;
    } catch {
      apiMessage = text;
    }

    throw new Error(
      res.status >= 500
        ? 'O servidor não conseguiu concluir esta solicitação. Tente novamente em instantes.'
        : apiMessage || `Erro HTTP ${res.status}`,
    );
  }

  return res.json() as Promise<T>;
}

export function login(email: string, password: string): Promise<LoginResponse> {
  return request<LoginResponse>('/auth/login', {
    method: 'POST',
    body: JSON.stringify({ email, password, device_name: 'web' }),
  });
}

export function getOverview(token: string, tenantContext?: string): Promise<OverviewResponse> {
  return request<OverviewResponse>('/bootstrap/overview', {
    headers: authenticatedHeaders(token, tenantContext),
  });
}

export function getDashboardSummary(token: string, tenantContext?: string): Promise<DashboardSummaryResponse> {
  return request<DashboardSummaryResponse>('/dashboard/summary', {
    headers: authenticatedHeaders(token, tenantContext),
  });
}

export async function getUnknownLeads(token: string): Promise<LeadSourceItem[]> {
  const data = await request<{ data: LeadSourceItem[] }>('/leads/sources/unknown', {
    headers: { Authorization: `Bearer ${token}` },
  });

  return data.data;
}

export async function getRecentLeads(token: string): Promise<LeadSourceItem[]> {
  const data = await request<{ data: LeadSourceItem[] }>('/leads/sources/recent', {
    headers: { Authorization: `Bearer ${token}` },
  });

  return data.data;
}

export function classifyLeadSource(token: string, leadId: number, source: string, reason?: string) {
  return request(`/leads/${leadId}/source`, {
    method: 'PATCH',
    headers: { Authorization: `Bearer ${token}` },
    body: JSON.stringify({ source, reason }),
  });
}

export async function getPipelines(token: string, tenantContext?: string): Promise<PipelineListItem[]> {
  const data = await request<{ data: PipelineListItem[] }>('/pipelines', {
    headers: authenticatedHeaders(token, tenantContext),
  });

  return data.data;
}

export async function getPipelineKanban(token: string, pipelineId: number, tenantContext?: string): Promise<PipelineKanban> {
  const data = await request<{ data: PipelineKanban }>(`/pipelines/${pipelineId}/kanban`, {
    headers: authenticatedHeaders(token, tenantContext),
  });

  return data.data;
}

export function moveLeadStage(token: string, leadId: number, kanbanColumnId: number, reason?: string): Promise<MoveLeadStageResponse> {
  return request<MoveLeadStageResponse>(`/leads/${leadId}/stage`, {
    method: 'PATCH',
    headers: { Authorization: `Bearer ${token}` },
    body: JSON.stringify({
      kanban_column_id: kanbanColumnId,
      reason: reason ?? 'Movido manualmente pelo operador',
    }),
  });
}

export async function getLeadStageHistory(token: string, leadId: number, tenantContext?: string): Promise<LeadStageHistoryItem[]> {
  const data = await request<{ data: LeadStageHistoryItem[] }>(`/leads/${leadId}/stage-history`, {
    headers: authenticatedHeaders(token, tenantContext),
  });

  return data.data;
}

export function applyKanbanRecommendation(token: string, leadId: number, analysisId: number): Promise<KanbanRecommendationResponse> {
  return request<KanbanRecommendationResponse>(`/leads/${leadId}/recommendations/${analysisId}/apply`, {
    method: 'POST',
    headers: { Authorization: `Bearer ${token}` },
  });
}

export function keepCurrentKanbanStage(token: string, leadId: number, analysisId: number): Promise<KanbanRecommendationResponse> {
  return request<KanbanRecommendationResponse>(`/leads/${leadId}/recommendations/${analysisId}/keep-current`, {
    method: 'POST',
    headers: { Authorization: `Bearer ${token}` },
  });
}

export function updateKanbanColumn(token: string, columnId: number, payload: { name: string; rule: string | null }) {
  return request(`/kanban-columns/${columnId}`, {
    method: 'PATCH',
    headers: { Authorization: `Bearer ${token}` },
    body: JSON.stringify(payload),
  });
}

export async function getTasksChecklist(token: string, tenantContext?: string): Promise<ChecklistTaskItem[]> {
  const data = await request<{ data: ChecklistTaskItem[] }>('/tasks/checklist', {
    headers: authenticatedHeaders(token, tenantContext),
  });

  return data.data;
}

export async function getContacts(
  token: string,
  params: {
    search?: string;
    source?: string;
    classification?: 'lead_novo' | 'lead_repetido' | '';
    stage_id?: number | '';
    page?: number;
    per_page?: number;
  },
  tenantContext?: string,
): Promise<ContactsResponse> {
  const query = new URLSearchParams();

  if (params.search) query.set('search', params.search);
  if (params.source) query.set('source', params.source);
  if (params.classification) query.set('classification', params.classification);
  if (params.stage_id) query.set('stage_id', String(params.stage_id));
  if (params.page) query.set('page', String(params.page));
  if (params.per_page) query.set('per_page', String(params.per_page));

  const path = query.size > 0 ? `/contacts?${query.toString()}` : '/contacts';
  return request<{ data: ContactItem[]; meta: ContactsResponse['meta'] }>(path, {
    headers: authenticatedHeaders(token, tenantContext),
  });
}

export async function exportContactsCsv(
  token: string,
  params: {
    search?: string;
    source?: string;
    classification?: 'lead_novo' | 'lead_repetido' | '';
    stage_id?: number | '';
  },
): Promise<Blob> {
  const query = new URLSearchParams();

  if (params.search) query.set('search', params.search);
  if (params.source) query.set('source', params.source);
  if (params.classification) query.set('classification', params.classification);
  if (params.stage_id) query.set('stage_id', String(params.stage_id));

  const path = query.size > 0 ? `/contacts/export?${query.toString()}` : '/contacts/export';
  const res = await fetch(`${API_BASE}${path}`, {
    method: 'GET',
    headers: {
      Authorization: `Bearer ${token}`,
    },
  });

  if (!res.ok) {
    const text = await res.text();
    throw new Error(text || `Erro HTTP ${res.status}`);
  }

  return res.blob();
}

export async function getInboxConversations(
  token: string,
  params: {
    search?: string;
    owner_user_id?: number | '';
    source?: string;
    stage_id?: number | '';
    service_window_open?: 'true' | 'false' | '';
    page?: number;
    per_page?: number;
  },
  tenantContext?: string,
): Promise<InboxConversationsResponse> {
  const query = new URLSearchParams();

  if (params.search) query.set('search', params.search);
  if (params.owner_user_id) query.set('owner_user_id', String(params.owner_user_id));
  if (params.source) query.set('source', params.source);
  if (params.stage_id) query.set('stage_id', String(params.stage_id));
  if (params.service_window_open) query.set('service_window_open', params.service_window_open);
  if (params.page) query.set('page', String(params.page));
  if (params.per_page) query.set('per_page', String(params.per_page));

  const path = query.size > 0 ? `/inbox/conversations?${query.toString()}` : '/inbox/conversations';
  return request<InboxConversationsResponse>(path, {
    headers: authenticatedHeaders(token, tenantContext),
  });
}

export async function getInboxConversationDetail(token: string, conversationId: number, tenantContext?: string): Promise<InboxConversationDetail> {
  const data = await request<{ data: InboxConversationDetail }>(`/inbox/conversations/${conversationId}`, {
    headers: authenticatedHeaders(token, tenantContext),
  });
  return data.data;
}

export async function getInboxConversationEvents(token: string, conversationId: number, tenantContext?: string): Promise<InboxConversationEvent[]> {
  const data = await request<{ data: InboxConversationEvent[] }>(`/inbox/conversations/${conversationId}/events`, {
    headers: authenticatedHeaders(token, tenantContext),
  });
  return data.data;
}

export async function sendInboxMessage(token: string, conversationId: number, body: string, file?: File): Promise<InboxMessageItem> {
  const form = new FormData();
  if (body.trim()) form.append('body', body.trim());
  if (file) form.append('file', file);
  const res = await fetch(`${API_BASE}/inbox/conversations/${conversationId}/messages`, {
    method: 'POST', headers: { Accept: 'application/json', Authorization: `Bearer ${token}` }, body: form,
  });
  if (!res.ok) throw new Error(await res.text() || `Erro HTTP ${res.status}`);
  return ((await res.json()) as { data: InboxMessageItem }).data;
}

export async function getInboxAttachmentBlob(token: string, attachmentId: number, tenantContext?: string): Promise<Blob> {
  const res = await fetch(`${API_BASE}/inbox/attachments/${attachmentId}`, {
    headers: { Accept: '*/*', ...authenticatedHeaders(token, tenantContext) },
  });
  if (!res.ok) throw new Error(await res.text() || `Erro HTTP ${res.status}`);
  return res.blob();
}

export function updateLeadOwner(token: string, leadId: number, ownerUserId: number | null, reason: string): Promise<LeadOwnerUpdateResponse> {
  return request<LeadOwnerUpdateResponse>(`/leads/${leadId}/owner`, {
    method: 'PATCH',
    headers: { Authorization: `Bearer ${token}` },
    body: JSON.stringify({
      owner_user_id: ownerUserId,
      reason,
    }),
  });
}

export function getAssignableUsers(token: string): Promise<AssignableUsersResponse> {
  return request<AssignableUsersResponse>('/users/assignable', {
    headers: { Authorization: `Bearer ${token}` },
  });
}

export async function getAdminCompanies(token: string): Promise<AdminCompanyListItem[]> {
  const data = await request<{ data: AdminCompanyListItem[] }>('/admin/companies', {
    headers: { Authorization: `Bearer ${token}` },
  });
  return data.data;
}

export function createAdminCompany(token: string, payload: AdminCompanyCreateRequest): Promise<AdminCompanyCreateResponse> {
  return request<AdminCompanyCreateResponse>('/admin/companies', {
    method: 'POST',
    headers: { Authorization: `Bearer ${token}` },
    body: JSON.stringify(payload),
  });
}

export function createAdminTenantViewContext(token: string, companyId: number): Promise<AdminTenantViewContextResponse> {
  return request<AdminTenantViewContextResponse>(`/admin/companies/${companyId}/view-context`, {
    method: 'POST',
    headers: authenticatedHeaders(token),
  });
}

export function revokeAdminTenantViewContext(token: string, tenantContext: string): Promise<{ message: string }> {
  return request<{ message: string }>('/admin/view-context', {
    method: 'DELETE',
    headers: authenticatedHeaders(token, tenantContext),
  });
}

export async function getWhatsAppSettings(token: string, tenantContext?: string): Promise<WhatsAppSettings> {
  const data = await request<WhatsAppSettingsResponse>('/settings/whatsapp', {
    headers: authenticatedHeaders(token, tenantContext),
  });
  return data.data;
}

export async function updateWhatsAppSettings(
  token: string,
  payload: UpdateWhatsAppSettingsRequest,
): Promise<WhatsAppSettings> {
  const data = await request<WhatsAppSettingsResponse>('/settings/whatsapp', {
    method: 'PUT',
    headers: authenticatedHeaders(token),
    body: JSON.stringify(payload),
  });
  return data.data;
}

export async function completeWhatsAppEmbeddedSignup(
  token: string,
  payload: CompleteWhatsAppEmbeddedSignupRequest,
): Promise<WhatsAppSettings> {
  const data = await request<CompleteWhatsAppEmbeddedSignupResponse>('/settings/whatsapp/embedded-signup/complete', {
    method: 'POST',
    headers: { Authorization: `Bearer ${token}` },
    body: JSON.stringify(payload),
  });
  return data.data;
}

// ==== Conversation Intelligence ====

export function getConversationIntelligenceSummary(token: string, tenantContext?: string): Promise<ConversationIntelligenceSummaryResponse> {
  return request<ConversationIntelligenceSummaryResponse>('/intelligence/summary', {
    headers: authenticatedHeaders(token, tenantContext),
  });
}

export function getConversationIntelligenceList(
  token: string,
  filters: { search?: string; analysis_status?: 'pending' | 'analyzed'; page?: number } = {},
  tenantContext?: string,
): Promise<ConversationIntelligenceListResponse> {
  const query = new URLSearchParams();
  if (filters.search) query.set('search', filters.search);
  if (filters.analysis_status) query.set('analysis_status', filters.analysis_status);
  if (filters.page) query.set('page', String(filters.page));
  const suffix = query.size > 0 ? `?${query.toString()}` : '';

  return request<ConversationIntelligenceListResponse>(`/intelligence/conversations${suffix}`, {
    headers: authenticatedHeaders(token, tenantContext),
  });
}

export function getConversationIntelligenceDetail(token: string, conversationId: number, tenantContext?: string): Promise<ConversationIntelligenceDetailResponse> {
  return request<ConversationIntelligenceDetailResponse>(`/intelligence/conversations/${conversationId}`, {
    headers: authenticatedHeaders(token, tenantContext),
  });
}

export function analyzeConversation(token: string, conversationId: number): Promise<AnalyzeConversationResponse> {
  return request<AnalyzeConversationResponse>(`/intelligence/conversations/${conversationId}/analyze`, {
    method: 'POST',
    headers: { Authorization: `Bearer ${token}` },
  });
}

// ==== Campaign Intelligence ====

export function previewCampaignReport(token: string, startDate: string, endDate: string, tenantContext?: string): Promise<CampaignPreviewResponse> {
  const query = new URLSearchParams({ start_date: startDate, end_date: endDate });
  return request<CampaignPreviewResponse>(`/intelligence/campaign-reports/preview?${query.toString()}`, {
    headers: authenticatedHeaders(token, tenantContext),
  });
}

export function createCampaignReport(token: string, startDate: string, endDate: string): Promise<{ message: string; reused: boolean; data: CampaignReport }> {
  return request('/intelligence/campaign-reports', {
    method: 'POST',
    headers: authenticatedHeaders(token),
    body: JSON.stringify({ start_date: startDate, end_date: endDate }),
  });
}

export function getCampaignReports(token: string, page = 1, tenantContext?: string): Promise<CampaignReportListResponse> {
  return request<CampaignReportListResponse>(`/intelligence/campaign-reports?page=${page}`, {
    headers: authenticatedHeaders(token, tenantContext),
  });
}

export function getCampaignReport(token: string, reportId: number, tenantContext?: string): Promise<{ data: CampaignReport }> {
  return request<{ data: CampaignReport }>(`/intelligence/campaign-reports/${reportId}`, {
    headers: authenticatedHeaders(token, tenantContext),
  });
}

export function getCampaignReportLeads(
  token: string,
  reportId: number,
  filters: { cohort?: 'new' | 'rescued'; owner_user_id?: number; page?: number } = {},
  tenantContext?: string,
): Promise<{ data: CampaignReportLead[]; meta: { page: number; per_page: number; total: number; last_page: number } }> {
  const query = new URLSearchParams();
  if (filters.cohort) query.set('cohort', filters.cohort);
  if (filters.owner_user_id !== undefined) query.set('owner_user_id', String(filters.owner_user_id));
  if (filters.page) query.set('page', String(filters.page));
  return request(`/intelligence/campaign-reports/${reportId}/leads?${query.toString()}`, {
    headers: authenticatedHeaders(token, tenantContext),
  });
}
