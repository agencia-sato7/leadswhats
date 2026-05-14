import type {
  ContactItem,
  ContactsResponse,
  ChecklistTaskItem,
  DashboardSummaryResponse,
  InboxConversationDetail,
  InboxConversationsResponse,
  LeadStageHistoryItem,
  LeadSourceItem,
  LoginResponse,
  MoveLeadStageResponse,
  OverviewResponse,
  PipelineKanban,
  PipelineListItem,
} from './types';

const API_BASE = 'http://localhost:8000/api/v1';

async function request<T>(path: string, init?: RequestInit): Promise<T> {
  const res = await fetch(`${API_BASE}${path}`, {
    ...init,
    headers: {
      'Content-Type': 'application/json',
      ...(init?.headers ?? {}),
    },
  });

  if (!res.ok) {
    const text = await res.text();
    throw new Error(text || `Erro HTTP ${res.status}`);
  }

  return res.json() as Promise<T>;
}

export function login(email: string, password: string): Promise<LoginResponse> {
  return request<LoginResponse>('/auth/login', {
    method: 'POST',
    body: JSON.stringify({ email, password, device_name: 'web' }),
  });
}

export function getOverview(token: string): Promise<OverviewResponse> {
  return request<OverviewResponse>('/bootstrap/overview', {
    headers: { Authorization: `Bearer ${token}` },
  });
}

export function getDashboardSummary(token: string): Promise<DashboardSummaryResponse> {
  return request<DashboardSummaryResponse>('/dashboard/summary', {
    headers: { Authorization: `Bearer ${token}` },
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

export async function getPipelines(token: string): Promise<PipelineListItem[]> {
  const data = await request<{ data: PipelineListItem[] }>('/pipelines', {
    headers: { Authorization: `Bearer ${token}` },
  });

  return data.data;
}

export async function getPipelineKanban(token: string, pipelineId: number): Promise<PipelineKanban> {
  const data = await request<{ data: PipelineKanban }>(`/pipelines/${pipelineId}/kanban`, {
    headers: { Authorization: `Bearer ${token}` },
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

export async function getLeadStageHistory(token: string, leadId: number): Promise<LeadStageHistoryItem[]> {
  const data = await request<{ data: LeadStageHistoryItem[] }>(`/leads/${leadId}/stage-history`, {
    headers: { Authorization: `Bearer ${token}` },
  });

  return data.data;
}

export async function getTasksChecklist(token: string): Promise<ChecklistTaskItem[]> {
  const data = await request<{ data: ChecklistTaskItem[] }>('/tasks/checklist', {
    headers: { Authorization: `Bearer ${token}` },
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
    headers: { Authorization: `Bearer ${token}` },
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
    headers: { Authorization: `Bearer ${token}` },
  });
}

export async function getInboxConversationDetail(token: string, conversationId: number): Promise<InboxConversationDetail> {
  const data = await request<{ data: InboxConversationDetail }>(`/inbox/conversations/${conversationId}`, {
    headers: { Authorization: `Bearer ${token}` },
  });
  return data.data;
}
