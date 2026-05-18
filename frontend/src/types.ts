export type Role = 'admin' | 'gestor' | 'sdr';

export type AuthUser = {
  id: number;
  name: string;
  email: string;
  role: Role;
  company_id: number;
};

export type LoginResponse = {
  token: string;
  token_type: string;
  expires_in_seconds: number;
  user: AuthUser;
};

export type OverviewResponse = {
  company: {
    id: number;
    name: string;
    slug: string;
    timezone: string;
    work_start: string;
    work_end: string;
  };
  counts: {
    users: number;
    leads: number;
    conversations: number;
    messages: number;
    pipelines: number;
    kanban_columns: number;
  };
};

export type DashboardSummaryResponse = {
  date: string;
  metrics: {
    new_leads_today: number;
    repeat_leads_today: number;
    avg_first_response_seconds: number;
    vacuum_24h_open: number;
    rescues_today: number;
    active_conversations: number;
    unknown_source_leads: number;
    manual_classifications_today: number;
    open_tasks: number;
    vacuum_follow_up_tasks: number;
    waiting_first_response_tasks: number;
    overdue_follow_up_tasks: number;
    unassigned_leads: number;
    oldest_pending_task_hours: number;
  };
};

export type LeadSourceItem = {
  id: number;
  name: string | null;
  phone_e164: string;
  source: string;
  source_method: string;
  last_inbound_at: string | null;
  created_at?: string;
  updated_at?: string;
};

export type PipelineListItem = {
  id: number;
  name: string;
  is_default: boolean;
  created_at: string;
  updated_at: string;
};

export type KanbanCard = {
  lead_id: number;
  name: string | null;
  phone: string;
  source: string;
  classification: string;
  last_message_at: string | null;
};

export type KanbanColumn = {
  id: number;
  name: string;
  position: number;
  rule: string | null;
  cards: KanbanCard[];
};

export type PipelineKanban = {
  id: number;
  name: string;
  columns: KanbanColumn[];
};

export type LeadStageHistoryItem = {
  id: number;
  lead_id: number;
  from_column_id: number | null;
  from_column_name: string | null;
  to_column_id: number;
  to_column_name: string | null;
  moved_by_user_id: number | null;
  move_source: string;
  reason: string | null;
  moved_at: string;
};

export type MoveLeadStageResponse = {
  message: string;
  data: {
    lead_id: number;
    kanban_column_id: number;
    moved_by_user_id: number;
    movement_type: string;
    history_created: boolean;
  };
};

export type ChecklistTaskItem = {
  lead_id: number;
  conversation_id: number;
  lead_name: string | null;
  phone: string;
  source: string;
  current_stage: string | null;
  last_message_at: string;
  last_message_direction: 'inbound' | 'outbound';
  hours_since_last_message: number;
  task_type: string;
  task_label: string;
  priority: 'medium' | 'high';
};

export type ContactItem = {
  lead_id: number;
  name: string | null;
  phone: string;
  source: string;
  source_method: string;
  classification: 'lead_novo' | 'lead_repetido';
  current_stage: string | null;
  last_message_at: string | null;
  last_message_direction: 'inbound' | 'outbound' | null;
  created_at: string;
};

export type ContactsResponse = {
  data: ContactItem[];
  meta: {
    page: number;
    per_page: number;
    total: number;
    last_page: number;
  };
};

export type InboxConversationListItem = {
  conversation_id: number;
  lead_id: number;
  lead_name: string | null;
  phone: string;
  source: string;
  current_stage: string | null;
  owner_user_id: number | null;
  owner_name: string | null;
  last_message_body: string | null;
  last_message_direction: 'inbound' | 'outbound' | null;
  last_message_at: string | null;
  unread_count: number;
  has_open_task: boolean;
  service_window_open: boolean;
  service_window_expires_at: string | null;
};

export type InboxConversationsResponse = {
  data: InboxConversationListItem[];
  meta: {
    page: number;
    per_page: number;
    total: number;
    last_page: number;
  };
};

export type AssignableUser = {
  id: number;
  name: string;
  email: string;
  role: Role;
};

export type AssignableUsersResponse = {
  data: AssignableUser[];
};

export type InboxMessageItem = {
  id: number;
  direction: 'inbound' | 'outbound';
  body: string | null;
  sent_at: string;
  provider: string | null;
  external_message_id: string | null;
  created_at: string;
};

export type InboxConversationDetail = {
  conversation_id: number;
  status: string;
  lead: {
    lead_id: number;
    lead_name: string | null;
    phone: string;
    source: string;
    current_stage: string | null;
  };
  owner: {
    owner_user_id: number | null;
    owner_name: string | null;
  };
  service_window_open: boolean;
  service_window_expires_at: string | null;
  messages: InboxMessageItem[];
};

export type InboxSendMessageResponse = {
  message: string;
  data: {
    id: number;
    conversation_id: number;
    lead_id: number;
    direction: 'outbound';
    body: string;
    provider: string | null;
    external_message_id: string | null;
    sent_at: string;
  };
};

export type InboxConversationEventType =
  | 'conversation_opened'
  | 'message_sent'
  | 'stage_changed'
  | 'owner_assigned'
  | 'owner_changed'
  | 'owner_removed';

export type InboxConversationEventMetadata = {
  provider?: string | null;
  external_message_id?: string | null;
  from_column_id?: number | null;
  from_column_name?: string | null;
  to_column_id?: number | null;
  to_column_name?: string | null;
  move_source?: string | null;
  previous_owner_user_id?: number | null;
  previous_owner_name?: string | null;
  new_owner_user_id?: number | null;
  new_owner_name?: string | null;
  reason?: string | null;
};

export type InboxConversationEvent = {
  event_id: number;
  event_type: InboxConversationEventType;
  user_id: number | null;
  user_name: string | null;
  occurred_at: string;
  metadata: InboxConversationEventMetadata | null;
};

export type LeadOwnerUpdateResponse = {
  message: string;
  data: {
    lead_id: number;
    owner_user_id: number | null;
    owner_name: string | null;
    updated_by_user_id: number;
  };
};
