import { useEffect, useRef, useState } from 'react';
import type { FormEvent, KeyboardEvent } from 'react';

import { getInboxAttachmentBlob, getInboxConversationDetail, getInboxConversations, sendInboxMessage } from '../api';
import type { InboxConversationDetail, InboxConversationListItem, InboxMessageItem } from '../types';
import { Alert, Button, EmptyState, Input, LoadingState } from '../components/ui';

function time(value: string | null): string {
  if (!value) return '';
  return new Date(value).toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' });
}

function Attachment({ token, tenantContext, attachment }: { token: string; tenantContext?: string; attachment: InboxMessageItem['attachments'][number] }) {
  const [url, setUrl] = useState<string | null>(null);
  useEffect(() => {
    let active = true;
    let objectUrl: string | null = null;
    getInboxAttachmentBlob(token, attachment.id, tenantContext).then((blob) => {
      objectUrl = URL.createObjectURL(blob);
      if (active) setUrl(objectUrl);
    }).catch(() => undefined);
    return () => { active = false; if (objectUrl) URL.revokeObjectURL(objectUrl); };
  }, [token, tenantContext, attachment.id]);
  if (!url) return <small>Carregando anexo...</small>;
  if (attachment.type === 'image') return <a href={url} target="_blank" rel="noreferrer"><img className="lw-attendance-media" src={url} alt={attachment.original_name || 'Imagem'} /></a>;
  if (attachment.type === 'video') return <video className="lw-attendance-media" src={url} controls />;
  if (attachment.type === 'audio') return <audio className="lw-attendance-audio" src={url} controls />;
  return <a className="lw-attendance-document" href={url} download={attachment.original_name || 'arquivo'}>📎 {attachment.original_name || 'Baixar documento'}</a>;
}

export function AttendancePage({ token, tenantContext, readOnly = false }: { token: string; tenantContext?: string; readOnly?: boolean }) {
  const [conversations, setConversations] = useState<InboxConversationListItem[]>([]);
  const [selectedId, setSelectedId] = useState<number | null>(null);
  const [detail, setDetail] = useState<InboxConversationDetail | null>(null);
  const [search, setSearch] = useState('');
  const [body, setBody] = useState('');
  const [file, setFile] = useState<File | null>(null);
  const [sending, setSending] = useState(false);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [recording, setRecording] = useState(false);
  const recorderRef = useRef<MediaRecorder | null>(null);
  const chunksRef = useRef<Blob[]>([]);
  const endRef = useRef<HTMLDivElement | null>(null);

  async function refresh(silent = false) {
    if (!silent) setLoading(true);
    try {
      const list = await getInboxConversations(token, { search: search || undefined, per_page: 100 }, tenantContext);
      setConversations(list.data);
      const nextId = selectedId ?? list.data[0]?.conversation_id ?? null;
      if (nextId) {
        setSelectedId(nextId);
        setDetail(await getInboxConversationDetail(token, nextId, tenantContext));
      } else setDetail(null);
      setError(null);
    } catch (err) {
      if (!silent) setError(err instanceof Error ? err.message : 'Não foi possível carregar o atendimento.');
    } finally { if (!silent) setLoading(false); }
  }

  useEffect(() => { void refresh(); }, [token, search]);
  useEffect(() => {
    const id = window.setInterval(() => void refresh(true), 3000);
    return () => window.clearInterval(id);
  }, [token, search, selectedId]);
  useEffect(() => { endRef.current?.scrollIntoView({ behavior: 'smooth' }); }, [detail?.messages.length]);

  async function selectConversation(id: number) {
    setSelectedId(id); setLoading(true);
    try { setDetail(await getInboxConversationDetail(token, id, tenantContext)); setError(null); }
    catch (err) { setError(err instanceof Error ? err.message : 'Não foi possível abrir a conversa.'); }
    finally { setLoading(false); }
  }

  async function submit(event?: FormEvent) {
    event?.preventDefault();
    if (!selectedId || sending || (!body.trim() && !file)) return;
    setSending(true); setError(null);
    try {
      const message = await sendInboxMessage(token, selectedId, body, file ?? undefined);
      setDetail((current) => current ? { ...current, messages: [...current.messages, message] } : current);
      setBody(''); setFile(null);
      void refresh(true);
    } catch (err) { setError(err instanceof Error ? err.message : 'Falha ao enviar mensagem.'); }
    finally { setSending(false); }
  }

  function handleKeyDown(event: KeyboardEvent<HTMLTextAreaElement>) {
    if (event.key === 'Enter' && !event.shiftKey) { event.preventDefault(); void submit(); }
  }

  async function toggleRecording() {
    if (recording) { recorderRef.current?.stop(); return; }
    try {
      const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
      const recorder = new MediaRecorder(stream);
      chunksRef.current = [];
      recorder.ondataavailable = (event) => { if (event.data.size) chunksRef.current.push(event.data); };
      recorder.onstop = () => {
        const blob = new Blob(chunksRef.current, { type: recorder.mimeType || 'audio/webm' });
        setFile(new File([blob], `audio-${Date.now()}.webm`, { type: blob.type }));
        stream.getTracks().forEach((track) => track.stop());
        setRecording(false);
      };
      recorderRef.current = recorder; recorder.start(); setRecording(true);
    } catch { setError('Não foi possível acessar o microfone.'); }
  }

  const blocked = readOnly || !detail?.service_window_open;
  return (
    <section className="lw-attendance-shell">
      <aside className="lw-attendance-list">
        <div className="lw-attendance-list-header"><h2>Atendimento</h2><Input value={search} onChange={(event) => setSearch(event.target.value)} placeholder="Buscar conversa" /></div>
        {conversations.map((conversation) => (
          <button key={conversation.conversation_id} className={`lw-attendance-contact ${selectedId === conversation.conversation_id ? 'is-active' : ''}`} onClick={() => void selectConversation(conversation.conversation_id)}>
            <span className="lw-attendance-avatar">{(conversation.lead_name || conversation.phone).slice(0, 1).toUpperCase()}</span>
            <span><strong>{conversation.lead_name || conversation.phone}</strong><small>{conversation.last_message_body || 'Mídia recebida'}</small></span>
            <time>{time(conversation.last_message_at)}</time>
          </button>
        ))}
      </aside>

      <main className="lw-attendance-chat">
        {loading && !detail ? <LoadingState message="Carregando atendimento..." /> : null}
        {!loading && !detail ? <EmptyState title="Selecione uma conversa" /> : null}
        {detail ? <>
          <header className="lw-attendance-chat-header"><span className="lw-attendance-avatar">{(detail.lead.lead_name || detail.lead.phone).slice(0, 1).toUpperCase()}</span><div><strong>{detail.lead.lead_name || detail.lead.phone}</strong><small>{detail.service_window_open ? 'Janela de atendimento aberta' : 'Janela de 24 horas fechada'}</small></div></header>
          <div className="lw-attendance-messages">
            {detail.messages.map((message) => <div key={message.id} className={`lw-attendance-bubble ${message.direction}`}>
              {message.attachments.map((attachment) => <Attachment key={attachment.id} token={token} tenantContext={tenantContext} attachment={attachment} />)}
              {message.body ? <p>{message.body}</p> : null}
              {message.audio_transcript ? <small>Transcrição: {message.audio_transcript}</small> : null}
              <footer>{time(message.sent_at)} {message.direction === 'outbound' ? `· ${message.delivery_status || 'enviada'}` : ''}</footer>
            </div>)}
            <div ref={endRef} />
          </div>
          {error ? <Alert variant="danger">{error}</Alert> : null}
          <form className="lw-attendance-composer" onSubmit={(event) => void submit(event)}>
            {file ? <div className="lw-attendance-file-preview"><span>📎 {file.name}</span><button type="button" onClick={() => setFile(null)}>Remover</button></div> : null}
            {blocked ? <Alert variant="warning">{readOnly ? 'Visualização administrativa: envio desabilitado.' : 'A janela de atendimento de 24 horas está fechada.'}</Alert> : null}
            <div><label className="lw-attendance-attach" title="Anexar arquivo">📎<input type="file" accept="image/*,video/*,audio/*,.pdf,.doc,.docx,.txt" onChange={(event) => setFile(event.target.files?.[0] ?? null)} disabled={blocked || sending} /></label>
              <button type="button" className={recording ? 'is-recording' : ''} onClick={() => void toggleRecording()} disabled={blocked || sending}>{recording ? '■' : '🎙️'}</button>
              <textarea value={body} onChange={(event) => setBody(event.target.value)} onKeyDown={handleKeyDown} placeholder="Digite uma mensagem" disabled={blocked || sending} rows={1} />
              <Button type="submit" disabled={blocked || sending || (!body.trim() && !file)}>{sending ? '...' : 'Enviar'}</Button></div>
          </form>
        </> : null}
      </main>

      {detail ? <aside className="lw-attendance-info"><h3>Dados do contato</h3><p><strong>Telefone</strong><br />{detail.lead.phone}</p><p><strong>Origem</strong><br />{detail.lead.source}</p><p><strong>Etapa</strong><br />{detail.lead.current_stage || 'Sem etapa'}</p><p><strong>Responsável</strong><br />{detail.owner.owner_name || 'Sem responsável'}</p></aside> : null}
    </section>
  );
}
