import { useState } from 'react';
import { changePassword } from '../api';
import type { AuthUser } from '../types';
import { Button, Card, ErrorState, FormGroup, Input } from '../components/ui';
import { PageHeader } from '../components/layout';

export function PasswordChangePage({ token, onChanged }: { token: string; onChanged: (user: AuthUser) => void }) {
  const [current, setCurrent] = useState(''); const [password, setPassword] = useState(''); const [confirmation, setConfirmation] = useState(''); const [error, setError] = useState<string|null>(null); const [loading, setLoading] = useState(false);
  async function submit(e: React.FormEvent) { e.preventDefault(); if (password !== confirmation) { setError('A confirmação não corresponde à nova senha.'); return; } setLoading(true); setError(null); try { const response = await changePassword(token, current, password); onChanged(response.user); } catch (err) { setError(err instanceof Error ? err.message : 'Não foi possível trocar a senha.'); } finally { setLoading(false); } }
  return <main className="lw-login-shell"><Card className="lw-login-card"><PageHeader title="Troque sua senha temporária" subtitle="Defina uma senha pessoal antes de acessar a plataforma."/><form onSubmit={submit} className="lw-stack"><FormGroup label="Senha temporária"><Input type="password" required value={current} onChange={(e)=>setCurrent(e.target.value)}/></FormGroup><FormGroup label="Nova senha"><Input type="password" minLength={8} required value={password} onChange={(e)=>setPassword(e.target.value)}/></FormGroup><FormGroup label="Confirmar nova senha"><Input type="password" minLength={8} required value={confirmation} onChange={(e)=>setConfirmation(e.target.value)}/></FormGroup>{error?<ErrorState message={error}/>:null}<Button type="submit" disabled={loading}>{loading?'Salvando...':'Trocar senha'}</Button></form></Card></main>;
}
