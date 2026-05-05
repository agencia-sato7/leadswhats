import { useEffect, useState } from 'react';
import {
  classifyLeadSource,
  getDashboardSummary,
  getOverview,
  getRecentLeads,
  getUnknownLeads,
  login,
} from './api';
import type { AuthUser, DashboardSummaryResponse, LeadSourceItem, OverviewResponse } from './types';

type Session = {
  token: string;
  user: AuthUser;
};

const STORAGE_KEY = 'leadswhats_session';
const QUICK_SOURCES = ['instagram', 'google', 'facebook', 'indicacao', 'outro'];

function formatSeconds(seconds: number): string {
  const mins = Math.floor(seconds / 60);
  const h = Math.floor(mins / 60);
  const m = mins % 60;
  if (h > 0) return `${h}h ${m}m`;
  return `${m}m`;
}

export function App() {
  const [email, setEmail] = useState('gestor@empresa.local');
  const [password, setPassword] = useState('12345678');
  const [session, setSession] = useState<Session | null>(null);
  const [overview, setOverview] = useState<OverviewResponse | null>(null);
  const [dashboard, setDashboard] = useState<DashboardSummaryResponse | null>(null);
  const [unknownLeads, setUnknownLeads] = useState<LeadSourceItem[]>([]);
  const [recentLeads, setRecentLeads] = useState<LeadSourceItem[]>([]);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);

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

  async function refreshData(token: string) {
    const [overviewData, dashboardData] = await Promise.all([
      getOverview(token),
      getDashboardSummary(token),
    ]);

    setOverview(overviewData);
    setDashboard(dashboardData);

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

  useEffect(() => {
    if (!session) return;

    setLoading(true);
    setError(null);

    refreshData(session.token)
      .catch((err) => {
        setError('Falha ao carregar dados da API.');
        console.error(err);
      })
      .finally(() => setLoading(false));
  }, [session]);

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
    <main style={{ maxWidth: 1050, margin: '20px auto', fontFamily: 'system-ui', padding: 16 }}>
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
          </ul>
        </section>
      ) : null}

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
