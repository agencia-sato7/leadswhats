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
