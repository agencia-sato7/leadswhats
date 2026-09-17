export type Role = 'admin' | 'gestor' | 'sdr' | 'platform_admin';

export type AuthUser = {
  id: number;
  name: string;
  email: string;
  role: Role;
  company_id: number | null;
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
  demo_mode: boolean;
  whatsapp_status: 'not_configured' | 'configured' | 'error';
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
    successful_conversations_today: number;
    lost_conversations_today: number;
    effectiveness_percentage: number;
    average_conversation_quality: number | null;
    low_quality_conversations: number;
    ai_stage_mismatch_opportunities: number;
  };
  funnel: Array<{
    stage_name: string;
    position: number;
    count: number;
  }>;
  team_performance: Array<{
    name: string;
    active_opportunities: number;
    average_score: number | null;
    avg_first_response_seconds: number | null;
  }>;
  funnel_by_source: Array<{
    source: string;
    stage_name: string;
    count: number;
  }>;
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
  owner_name: string | null;
  latest_analysis: {
    id: number;
    conversation_id: number;
    score: number;
    summary: string;
    intent: string | null;
    objections: string[];
    commercial_data: Record<string, unknown>;
    recommended_kanban_column_id: number | null;
    recommended_kanban_column_name: string | null;
    classification_reason: string | null;
    confidence: number | null;
    analyzed_at: string;
    recommendation_decision: 'applied' | 'kept_current' | null;
  } | null;
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

export type KanbanRecommendationResponse = {
  message: string;
  data: {
    decision: 'applied' | 'kept_current';
    decided_at: string;
    movement?: MoveLeadStageResponse['data'];
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

export type AdminCompanyListItem = {
  id: number;
  name: string;
  slug: string;
  active: boolean;
  users_count: number;
  pipelines_count: number;
  has_business_settings: boolean;
  created_at: string;
};

export type AdminTenantViewContext = {
  context_token: string;
  company: {
    id: number;
    name: string;
    slug: string;
  };
  read_only: true;
  expires_at: string;
};

export type AdminTenantViewContextResponse = {
  data: AdminTenantViewContext;
};

export type AdminCompanyCreateRequest = {
  company: {
    name: string;
    slug: string;
  };
  admin_user: {
    name: string;
    email: string;
    password: string;
  };
  settings: {
    timezone: string;
    workday_start_time: string;
    workday_end_time: string;
    lunch_start_time: string;
    lunch_end_time: string;
    working_days: number[];
    repeated_lead_window_days: number;
    rescue_threshold_hours: number;
    first_response_sla_minutes: number;
    follow_up_sla_hours: number;
    stale_conversation_hours: number;
  };
};

export type AdminCompanyCreateResponse = {
  message?: string;
  data?: {
    id: number;
    name: string;
    slug: string;
    webhook_token_configured?: boolean;
    masked_webhook_token?: string | null;
  };
  webhook_token_configured?: boolean;
  masked_webhook_token?: string | null;
};

export type InboxMessageItem = {
  id: number;
  direction: 'inbound' | 'outbound';
  body: string | null;
  sent_at: string;
  provider: string | null;
  external_message_id: string | null;
  created_at: string;
  channel: 'text' | 'image' | 'video' | 'audio' | 'document';
  audio_transcript: string | null;
  delivery_status: 'sent' | 'delivered' | 'read' | 'failed' | null;
  delivery_error: string | null;
  attachments: Array<{
    id: number;
    type: 'image' | 'video' | 'audio' | 'document';
    mime_type: string;
    original_name: string | null;
    size_bytes: number;
    url: string;
  }>;
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

export type WhatsAppConnectionMode = 'embedded_signup' | 'coexistence';

export type WhatsAppSyncStatus = 'requested' | 'completed' | 'error' | null;

export type WhatsAppSettings = {
  provider: 'meta_cloud';
  status: 'not_configured' | 'configured' | 'error';
  connection_mode: WhatsAppConnectionMode;
  is_coexistence: boolean;
  coexistence_app_id: string | null;
  coexistence_config_id: string | null;
  coexistence_feature_type: string | null;
  coexistence_session_info_version: string | null;
  phone_number: string | null;
  phone_number_id: string | null;
  business_account_id: string | null;
  waba_id: string | null;
  business_id: string | null;
  page_ids: string[];
  catalog_ids: string[];
  dataset_ids: string[];
  instagram_account_ids: string[];
  coexistence_opted_in_at: string | null;
  history_sync_status: WhatsAppSyncStatus;
  contacts_sync_status: WhatsAppSyncStatus;
  token_expires_at: string | null;
  access_token_configured: boolean;
  webhook_verify_token_configured: boolean;
  webhook_verify_token?: string | null;
  connected_at: string | null;
  last_error: string | null;
};

export type WhatsAppSettingsResponse = {
  data: WhatsAppSettings;
};

export type WhatsAppCoexistenceData = {
  phone_number_id: string;
  waba_id: string;
  business_id?: string;
  phone_number?: string;
  page_ids: string[];
  catalog_ids: string[];
  dataset_ids: string[];
  instagram_account_ids: string[];
};

/**
 * Eventos de session logging do Embedded Signup. A Coexistência conclui com
 * FINISH_WHATSAPP_BUSINESS_APP_ONBOARDING; os demais são aceitos por segurança.
 */
export type WhatsAppCoexistenceFinishEvent =
  | 'FINISH'
  | 'FINISH_ONLY_WABA'
  | 'FINISH_WHATSAPP_BUSINESS_APP_ONBOARDING';

export type WhatsAppCoexistenceEvent = {
  data: WhatsAppCoexistenceData;
  type: 'WA_EMBEDDED_SIGNUP';
  event: WhatsAppCoexistenceFinishEvent;
};

export type CompleteWhatsAppCoexistenceRequest = {
  code: string;
  coexistence: WhatsAppCoexistenceEvent;
};

export type CompleteWhatsAppCoexistenceResponse = {
  data: WhatsAppSettings;
};

export type WhatsAppCoexistenceSyncType = 'contacts' | 'history' | 'both';

// ==== Conversation Intelligence ====

export type ConversationQualityAnalysis = {
  id: number;
  analysis_version: number;
  score: number;
  summary: string;
  intent: string | null;
  objections: string[];
  positive_points: string[];
  errors: string[];
  improvement_suggestion: string | null;
  commercial_data: Record<string, unknown>;
  criteria_scores: Record<string, number>;
  recommended_kanban_column_id: number | null;
  recommended_kanban_column_name: string | null;
  classification_reason: string | null;
  confidence: number | null;
  prompt_version: string;
  model_provider: string | null;
  model_name: string | null;
  source_last_message_id: number | null;
  transcript_hash: string;
  analyzed_at: string;
};

export type ConversationIntelligenceListItem = {
  conversation_id: number;
  lead_id: number;
  lead_name: string | null;
  phone: string | null;
  source: string | null;
  owner_user_id: number | null;
  owner_name: string | null;
  status: string;
  started_at: string;
  last_message_at: string | null;
  current_stage: string | null;
  analysis_status: 'pending' | 'analyzed';
  analysis_count: number;
  latest_analysis: ConversationQualityAnalysis | null;
};

export type ConversationIntelligenceListResponse = {
  data: ConversationIntelligenceListItem[];
  meta: {
    page: number;
    per_page: number;
    total: number;
    last_page: number;
  };
};

export type ConversationIntelligenceDetail = {
  conversation_id: number;
  status: string;
  started_at: string;
  last_message_at: string | null;
  lead: {
    lead_id: number;
    name: string | null;
    phone: string | null;
    source: string | null;
    creative_id: string | null;
    creative_url: string | null;
    campaign_name: string | null;
    current_stage: string | null;
  };
  owner: {
    owner_user_id: number | null;
    name: string | null;
  };
  messages: Array<{
    id: number;
    direction: 'inbound' | 'outbound';
    channel: string;
    body: string | null;
    audio_transcript: string | null;
    sent_at: string;
  }>;
  analysis_status: 'pending' | 'analyzed';
  latest_analysis: ConversationQualityAnalysis | null;
  analysis_history: ConversationQualityAnalysis[];
};

export type ConversationIntelligenceDetailResponse = {
  data: ConversationIntelligenceDetail;
};

export type ConversationIntelligenceSummaryResponse = {
  data: {
    total_conversations: number;
    analyzed_conversations: number;
    pending_conversations: number;
    total_snapshots: number;
    average_score: number | null;
    score_bands: {
      excellent: number;
      attention: number;
      critical: number;
    };
    top_intents: Array<{ intent: string; total: number }>;
  };
};

export type AnalyzeConversationResponse = {
  message: string;
  data: ConversationIntelligenceDetail;
};

// ==== Campaign Intelligence ====

export type CampaignVerdict = 'good' | 'needs_improvement' | 'poor';
export type CampaignReportStatus = 'queued' | 'processing' | 'completed' | 'failed';

export type CampaignVolumeMetrics = {
  current_new_leads: number;
  previous_new_leads: number;
  absolute_change: number;
  percentage_change: number | null;
  trend: 'up' | 'down' | 'stable';
};

export type CampaignMetrics = {
  volume: CampaignVolumeMetrics;
  new_leads: number;
  rescued_leads: number;
  rescue_attempts: number;
  rescued_leads_with_response: number;
  rescue_response_rate: number | null;
  conversations_considered: number;
  messages_considered: number;
};

export type CampaignQualityRollup = {
  lead_count: number;
  score: number | null;
  verdict: CampaignVerdict | null;
  criteria_scores: Record<string, number>;
  strengths: string[];
  improvements: string[];
  summary?: string;
};

export type CampaignReportResult = {
  executive_summary: string;
  overall_verdict: CampaignVerdict;
  volume_assessment: CampaignVolumeMetrics & { summary: string };
  service_quality: CampaignQualityRollup;
  cohorts: { new: CampaignQualityRollup; rescued: CampaignQualityRollup };
  team: Array<CampaignQualityRollup & { owner_user_id: number | null; owner_name: string }>;
  priorities: string[];
};

export type CampaignReport = {
  id: number;
  start_date: string;
  end_date: string;
  comparison_start_date: string;
  comparison_end_date: string;
  status: CampaignReportStatus;
  progress_stage: string;
  progress_percentage: number;
  metrics: CampaignMetrics;
  result: CampaignReportResult | null;
  reused_evidence_count: number;
  new_evidence_count: number;
  base_report_id: number | null;
  requested_by: string | null;
  failure_message: string | null;
  created_at: string;
  completed_at: string | null;
};

export type CampaignPreviewResponse = {
  data: {
    range: { start_date: string; end_date: string; comparison_start_date: string; comparison_end_date: string; days: number; timezone: string };
    metrics: CampaignMetrics;
    has_analyzable_data: boolean;
    cached_report_id: number | null;
    cached_report_completed_at: string | null;
    estimated_evidences: number;
    reusable_evidences: number;
  };
};

export type CampaignReportListResponse = {
  data: CampaignReport[];
  meta: { page: number; per_page: number; total: number; last_page: number };
};

export type CampaignReportLead = {
  lead_id: number;
  lead_name: string | null;
  owner_user_id: number | null;
  owner_name: string | null;
  stage_name: string | null;
  cohort: 'new' | 'rescued';
  average_score: number;
  verdict: CampaignVerdict;
  evidence_count: number;
  message_count: number;
  rescue_attempts: number;
  conversation_id: number | null;
  first_evidence_date: string;
  last_evidence_date: string;
};
