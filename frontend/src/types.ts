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
