import { useEffect, useMemo, useState } from 'react';
import { createAdminUser, deactivateAdminUser, getAccessAudits, getAccessProfiles, getAdminUsers, resetAdminUserPassword, restoreAdminUser, updateAdminUser } from '../api';
import type { AccessAuditItem, AccessProfileItem, AdminCompanyListItem, AdminUserItem } from '../types';
import { Alert, Badge, Button, Card, EmptyState, ErrorState, FormGroup, Input, LoadingState, Modal, Select, Table } from '../components/ui';

export function AdminUsersPage({ token, companies }: { token: string; companies: AdminCompanyListItem[] }) {
  const [users, setUsers] = useState<AdminUserItem[]>([]);
  const [profiles, setProfiles] = useState<AccessProfileItem[]>([]);
  const [audits, setAudits] = useState<AccessAuditItem[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [search, setSearch] = useState('');
  const [companyFilter, setCompanyFilter] = useState('');
  const [statusFilter, setStatusFilter] = useState('');
  const [editing, setEditing] = useState<AdminUserItem | null>(null);
  const [open, setOpen] = useState(false);
  const [form, setForm] = useState({ company_id: '', access_profile_id: '', name: '', email: '', password: '' });
  const [success, setSuccess] = useState<string | null>(null);

  async function load() {
    setLoading(true); setError(null);
    try {
      const query = new URLSearchParams();
      if (search) query.set('search', search);
      if (companyFilter) query.set('company_id', companyFilter);
      if (statusFilter) query.set('active', statusFilter);
      const [userResponse, profileResponse, auditResponse] = await Promise.all([
        getAdminUsers(token, query.toString()), getAccessProfiles(token, 'active=1'), getAccessAudits(token),
      ]);
      setUsers(userResponse.data); setProfiles(profileResponse.filter((p) => p.company_id !== null)); setAudits(auditResponse);
    } catch (err) { setError(err instanceof Error ? err.message : 'Não foi possível carregar usuários.'); }
    finally { setLoading(false); }
  }

  useEffect(() => { void load(); }, [token, search, companyFilter, statusFilter]);
  const formProfiles = useMemo(() => profiles.filter((p) => String(p.company_id) === form.company_id && p.active), [profiles, form.company_id]);

  function newUser() { setEditing(null); setForm({ company_id: companyFilter, access_profile_id: '', name: '', email: '', password: '' }); setOpen(true); }
  function editUser(user: AdminUserItem) { setEditing(user); setForm({ company_id: String(user.company_id), access_profile_id: String(user.access_profile?.id ?? ''), name: user.name, email: user.email, password: '' }); setOpen(true); }

  async function save(event: React.FormEvent) {
    event.preventDefault(); setError(null); setSuccess(null);
    try {
      if (editing) await updateAdminUser(token, editing.id, { name: form.name, email: form.email, access_profile_id: Number(form.access_profile_id) });
      else await createAdminUser(token, { company_id: Number(form.company_id), access_profile_id: Number(form.access_profile_id), name: form.name, email: form.email, password: form.password });
      setOpen(false); setSuccess(editing ? 'Usuário atualizado.' : 'Usuário criado com senha temporária.'); await load();
    } catch (err) { setError(err instanceof Error ? err.message : 'Não foi possível salvar o usuário.'); }
  }

  async function toggle(user: AdminUserItem) {
    try { user.active ? await deactivateAdminUser(token, user.id) : await restoreAdminUser(token, user.id); setSuccess(user.active ? 'Usuário desativado e sessões revogadas.' : 'Usuário reativado.'); await load(); }
    catch (err) { setError(err instanceof Error ? err.message : 'Não foi possível alterar o usuário.'); }
  }

  async function reset(user: AdminUserItem) {
    const password = window.prompt(`Nova senha temporária para ${user.name} (mínimo 8 caracteres):`);
    if (!password) return;
    try { await resetAdminUserPassword(token, user.id, password); setSuccess('Senha redefinida; as sessões anteriores foram revogadas.'); await load(); }
    catch (err) { setError(err instanceof Error ? err.message : 'Não foi possível redefinir a senha.'); }
  }

  return <>
    <Card>
      <div className="lw-flex-between"><div><h2>Usuários das clínicas</h2><p>Gerencie credenciais, perfis e status sem apagar o histórico.</p></div><Button onClick={newUser}>Adicionar usuário</Button></div>
      <div className="lw-grid-3 lw-mt-3">
        <FormGroup label="Buscar"><Input value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Nome ou e-mail" /></FormGroup>
        <FormGroup label="Clínica"><Select value={companyFilter} onChange={(e) => setCompanyFilter(e.target.value)}><option value="">Todas</option>{companies.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}</Select></FormGroup>
        <FormGroup label="Status"><Select value={statusFilter} onChange={(e) => setStatusFilter(e.target.value)}><option value="">Todos</option><option value="1">Ativos</option><option value="0">Inativos</option></Select></FormGroup>
      </div>
    </Card>
    {success ? <Alert variant="success">{success}</Alert> : null}{error ? <ErrorState message={error} /> : null}{loading ? <LoadingState /> : null}
    {!loading && users.length === 0 ? <EmptyState title="Nenhum usuário encontrado." /> : null}
    {!loading && users.length ? <Table><thead><tr><th>Usuário</th><th>Clínica</th><th>Perfil</th><th>Escopo</th><th>Status</th><th>Ações</th></tr></thead><tbody>{users.map((user) => <tr key={user.id}>
      <td><strong>{user.name}</strong><br/><small>{user.email}</small></td><td>{user.company_name}</td><td>{user.access_profile?.name ?? 'Sem perfil'}</td><td>{user.access_profile?.data_scope === 'own' ? 'Próprios dados' : 'Clínica inteira'}</td>
      <td><Badge variant={user.active ? 'success' : 'warning'}>{user.active ? (user.must_change_password ? 'Troca de senha pendente' : 'Ativo') : 'Inativo'}</Badge></td>
      <td><div className="lw-flex-wrap-gap"><Button variant="secondary" onClick={() => editUser(user)}>Editar</Button><Button variant="secondary" onClick={() => void reset(user)}>Redefinir senha</Button><Button variant="secondary" onClick={() => void toggle(user)}>{user.active ? 'Desativar' : 'Reativar'}</Button></div></td>
    </tr>)}</tbody></Table> : null}
    <Card><h3>Atividade recente</h3>{audits.slice(0, 10).map((audit) => <p key={audit.id}><strong>{audit.actor?.name ?? 'Sistema'}</strong> · {audit.event} · {audit.company?.name ?? 'Global'} · {new Date(audit.created_at).toLocaleString('pt-BR')}</p>)}</Card>
    <Modal open={open} onClose={() => setOpen(false)} title={editing ? 'Editar usuário' : 'Adicionar usuário'}><form onSubmit={save} className="lw-stack">
      <FormGroup label="Clínica"><Select required disabled={Boolean(editing)} value={form.company_id} onChange={(e) => setForm({ ...form, company_id: e.target.value, access_profile_id: '' })}><option value="">Selecione</option>{companies.filter((c) => c.active).map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}</Select></FormGroup>
      <FormGroup label="Perfil"><Select required value={form.access_profile_id} onChange={(e) => setForm({ ...form, access_profile_id: e.target.value })}><option value="">Selecione</option>{formProfiles.map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}</Select></FormGroup>
      <FormGroup label="Nome"><Input required value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })}/></FormGroup>
      <FormGroup label="E-mail"><Input required type="email" value={form.email} onChange={(e) => setForm({ ...form, email: e.target.value })}/></FormGroup>
      {!editing ? <FormGroup label="Senha temporária" hint="O usuário deverá trocá-la no primeiro acesso."><Input required type="password" minLength={8} value={form.password} onChange={(e) => setForm({ ...form, password: e.target.value })}/></FormGroup> : null}
      <Button type="submit">Salvar</Button>
    </form></Modal>
  </>;
}
