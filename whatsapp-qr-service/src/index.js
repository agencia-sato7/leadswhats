import express from 'express';
import { makeWASocket, useMultiFileAuthState, DisconnectReason, fetchLatestBaileysVersion } from '@whiskeysockets/baileys';
import { isJidUser, isLidUser, isJidGroup, isJidStatusBroadcast, isJidNewsletter, jidDecode } from '@whiskeysockets/baileys/lib/WABinary/jid-utils.js';
import pino from 'pino';
import QRCode from 'qrcode';
import { existsSync, mkdirSync, rmSync, readFileSync, writeFileSync, readdirSync } from 'fs';
import { join, dirname } from 'path';
import { fileURLToPath } from 'url';

const __dirname = dirname(fileURLToPath(import.meta.url));
const SESSIONS_DIR = join(__dirname, '..', 'sessions');
const LID_MAP_FILE = join(SESSIONS_DIR, 'lid_map.json');
const LARAVEL_WEBHOOK_URL = process.env.LARAVEL_WEBHOOK_URL || 'http://backend:8000/api/v1/webhooks/whatsapp/baileys';
const PORT = parseInt(process.env.PORT || '3001', 10);
const LARAVEL_API_TOKEN = process.env.LARAVEL_API_TOKEN || '';

const DisconnectCodes = {
  loggedOut: 401,
  badSession: 408,
  connectionClosed: 428,
  timeout: 440,
  restartRequired: 515,
};

// Ensure sessions directory exists
if (!existsSync(SESSIONS_DIR)) {
  mkdirSync(SESSIONS_DIR, { recursive: true });
}

const app = express();
app.use(express.json());

// Store active sessions: { [companyId]: { socket, qrCode, status, phone } }
const sessions = {};

// LID → phone JID mapping (in-memory, persisted to file)
const lidMap = {};

function loadLidMap() {
  try {
    if (existsSync(LID_MAP_FILE)) {
      const raw = readFileSync(LID_MAP_FILE, 'utf-8');
      const parsed = JSON.parse(raw);
      Object.assign(lidMap, parsed);
      console.log(`Loaded ${Object.keys(lidMap).length} LID mappings`);
    }
  } catch (err) {
    console.error('Failed to load LID map:', err.message);
  }
}

function saveLidMap() {
  try {
    writeFileSync(LID_MAP_FILE, JSON.stringify(lidMap, null, 2));
  } catch (err) {
    console.error('Failed to save LID map:', err.message);
  }
}

function getSessionDir(companyId) {
  return join(SESSIONS_DIR, `company_${companyId}`);
}

function getSessionState(companyId) {
  const session = sessions[companyId];
  if (!session) {
    return { status: 'disconnected', qr_code: null, phone: null };
  }
  return {
    status: session.status || 'disconnected',
    qr_code: session.qrCode || null,
    phone: session.phone || null,
  };
}

async function notifyLaravel(companyId, event, data) {
  try {
    const payload = {
      company_id: companyId,
      event,
      data,
    };
    const headers = {
      'Content-Type': 'application/json',
    };
    if (LARAVEL_API_TOKEN) {
      headers['Authorization'] = `Bearer ${LARAVEL_API_TOKEN}`;
    }
    const response = await fetch(LARAVEL_WEBHOOK_URL, {
      method: 'POST',
      headers,
      body: JSON.stringify(payload),
    });

    if (!response.ok) {
      const errorBody = await response.text().catch(() => '');
      console.error(`[${companyId}] Webhook ${event} returned ${response.status}: ${errorBody.substring(0, 200)}`);
    }
  } catch (err) {
    console.error(`[${companyId}] Failed to notify Laravel (${event}):`, err.message);
  }
}

/**
 * Extracts the phone number from a JID or LID.
 * For @s.whatsapp.net JIDs, returns the numeric phone.
 * For @lid JIDs, tries the LID→phone mapping; falls back to the LID numeric part.
 */
function resolvePhone(remoteJid) {
  if (!remoteJid) {
    return '';
  }

  // Normal phone JID
  if (isJidUser(remoteJid)) {
    return jidDecode(remoteJid)?.user || remoteJid.replace('@s.whatsapp.net', '');
  }

  // LID JID — try mapping, fall back to numeric LID
  if (isLidUser(remoteJid)) {
    const mappedJid = lidMap[remoteJid];
    if (mappedJid && isJidUser(mappedJid)) {
      return jidDecode(mappedJid)?.user || mappedJid.replace('@s.whatsapp.net', '');
    }
    // Fallback: use the LID numeric part as a unique identifier
    return jidDecode(remoteJid)?.user || remoteJid.replace('@lid', '');
  }

  return '';
}

/**
 * Extracts text body from a Baileys message object, handling multiple
 * message types and unwrapping ephemeralMessage / viewOnceMessage wrappers.
 */
function extractBody(message) {
  if (!message?.message) {
    return '';
  }

  let msg = message.message;

  // Unwrap ephemeralMessage
  if (msg.ephemeralMessage?.message) {
    msg = msg.ephemeralMessage.message;
  }

  // Unwrap viewOnceMessage
  if (msg.viewOnceMessage?.message) {
    msg = msg.viewOnceMessage.message;
  }

  // Unwrap viewOnceMessageV2 (extension)
  if (msg.viewOnceMessageV2?.message) {
    msg = msg.viewOnceMessageV2.message;
  }

  // Text messages
  const text =
    msg.conversation ||
    msg.extendedTextMessage?.text ||
    msg.imageMessage?.caption ||
    msg.videoMessage?.caption ||
    msg.documentMessage?.caption ||
    msg.templateMessage?.hydratedFourRowTemplate?.hydratedTitleText ||
    msg.templateMessage?.hydratedTemplate?.hydratedTitleText ||
    '';

  // If no text but there is media, use a placeholder so the lead is still captured
  if (!text) {
    if (msg.imageMessage) return '[Imagem]';
    if (msg.videoMessage) return '[Vídeo]';
    if (msg.audioMessage) {
      return msg.audioMessage.ptt ? '[Áudio]' : '[Áudio]';
    }
    if (msg.stickerMessage) return '[Sticker]';
    if (msg.documentMessage) return '[Documento]';
    if (msg.contactMessage) return '[Contato]';
    if (msg.locationMessage) return '[Localização]';
    if (msg.liveLocationMessage) return '[Localização ao vivo]';
    if (msg.buttonsMessage) return '[Mensagem com botões]';
    if (msg.listMessage) return '[Mensagem de lista]';
    if (msg.reactionMessage) return '[Reação]';
  }

  return text || '';
}

async function startSession(companyId) {
  // If session already exists and is connected, return it
  if (sessions[companyId] && sessions[companyId].status === 'connected') {
    return getSessionState(companyId);
  }

  // Create session directory if it doesn't exist (do NOT delete existing session)
  const sessionDir = getSessionDir(companyId);
  if (!existsSync(sessionDir)) {
    mkdirSync(sessionDir, { recursive: true });
  }

  const { state, saveCreds } = await useMultiFileAuthState(sessionDir);
  const { version, isLatest } = await fetchLatestBaileysVersion();

  console.log(`[${companyId}] Starting Baileys session, version: ${version}, isLatest: ${isLatest}`);

  const logger = pino({ level: 'silent' });

  const socket = makeWASocket({
    version,
    logger,
    printQRInTerminal: false,
    auth: state,
    browser: ['LeadsWhats', 'Chrome', '1.0.0'],
    syncFullHistory: false,
    markOnlineOnConnect: false,
  });

  sessions[companyId] = {
    socket,
    qrCode: null,
    status: 'connecting',
    phone: null,
  };

  socket.ev.on('creds.update', saveCreds);

  // Build LID → phone mapping from WhatsApp's phone number sharing
  socket.ev.on('chats.phoneNumberShare', ({ lid, jid }) => {
    if (lid && jid) {
      lidMap[lid] = jid;
      saveLidMap();
      console.log(`[${companyId}] LID mapping: ${lid} → ${jid}`);
    }
  });

  socket.ev.on('connection.update', async (update) => {
    const { connection, lastDisconnect, qr } = update;

    if (qr) {
      try {
        const qrCodeDataUrl = await QRCode.toDataURL(qr);
        if (sessions[companyId]) {
          sessions[companyId].qrCode = qrCodeDataUrl;
          sessions[companyId].status = 'connecting';
        }
        console.log(`[${companyId}] QR code generated`);
        await notifyLaravel(companyId, 'qr_updated', { qr_code: qrCodeDataUrl });
      } catch (err) {
        console.error(`[${companyId}] Failed to generate QR code image:`, err.message);
      }
    }

    if (connection === 'open') {
      const phone = socket.user?.id ? socket.user.id.split(':')[0] : null;
      if (sessions[companyId]) {
        sessions[companyId].status = 'connected';
        sessions[companyId].qrCode = null;
        sessions[companyId].phone = phone;
      }
      console.log(`[${companyId}] Connected! Phone: ${phone}`);
      await notifyLaravel(companyId, 'connected', { phone });
    }

    if (connection === 'close') {
      const statusCode = lastDisconnect?.error?.output?.statusCode;
      const errorMessage = lastDisconnect?.error?.message || '';
      const reason = statusCode || DisconnectReason.loggedOut;
      console.log(`[${companyId}] Disconnected, reason: ${reason}, error: ${errorMessage}`);

      if (sessions[companyId]) {
        sessions[companyId].status = 'disconnected';
        sessions[companyId].qrCode = null;
        sessions[companyId].phone = null;
      }

      await notifyLaravel(companyId, 'disconnected', { reason, error: errorMessage });

      // Handle "MAC is invalid" / corrupted session error
      const isMacInvalid = errorMessage.includes('MAC') || errorMessage.includes('invalid') || statusCode === DisconnectCodes.badSession;
      const isLoggedOut = reason === DisconnectReason.loggedOut || statusCode === DisconnectCodes.loggedOut;

      if (isMacInvalid) {
        console.log(`[${companyId}] Session corrupted (MAC invalid). Clearing session and generating new QR...`);
        // Clear corrupted session data
        if (existsSync(sessionDir)) {
          rmSync(sessionDir, { recursive: true, force: true });
        }
        mkdirSync(sessionDir, { recursive: true });
        // Restart session to generate new QR code
        setTimeout(() => startSession(companyId), 1000);
        return;
      }

      // If not logged out, try to reconnect
      if (!isLoggedOut) {
        console.log(`[${companyId}] Attempting to reconnect in 5s...`);
        setTimeout(() => startSession(companyId), 5000);
      }
    }
  });

  socket.ev.on('messages.upsert', async (msg) => {
    // Only process real-time messages ('notify'), skip history sync ('append')
    if (msg.type && msg.type !== 'notify') {
      return;
    }

    const messages = msg.messages || [];
    for (const message of messages) {
      if (!message.key || message.key.fromMe) continue;

      const remoteJid = message.key.remoteJid || '';

      // Skip non-chat messages: status broadcast, groups, newsletters, bots
      if (isJidStatusBroadcast(remoteJid)) continue;
      if (isJidGroup(remoteJid)) continue;
      if (isJidNewsletter(remoteJid)) continue;
      if (remoteJid === 'status@broadcast') continue;

      const body = extractBody(message);

      if (!body) continue;

      const phone = resolvePhone(remoteJid);
      const messageId = message.key.id || '';

      console.log(`[${companyId}] Message from ${phone} (jid: ${remoteJid}): ${body.substring(0, 50)}`);

      await notifyLaravel(companyId, 'message_received', {
        phone,
        body,
        external_message_id: messageId,
        sent_at: new Date((message.messageTimestamp || 0) * 1000).toISOString(),
        raw_payload: JSON.stringify(message),
      });
    }
  });

  return getSessionState(companyId);
}

/**
 * Scans the sessions directory and restores any existing sessions on startup.
 */
async function restoreSessions() {
  if (!existsSync(SESSIONS_DIR)) {
    return;
  }

  const entries = readdirSync(SESSIONS_DIR, { withFileTypes: true });
  for (const entry of entries) {
    if (entry.isDirectory() && entry.name.startsWith('company_')) {
      const companyId = parseInt(entry.name.replace('company_', ''), 10);
      if (companyId && !sessions[companyId]) {
        console.log(`Restoring session for company ${companyId}...`);
        try {
          await startSession(companyId);
        } catch (err) {
          console.error(`Failed to restore session ${companyId}:`, err.message);
        }
      }
    }
  }
}

async function stopSession(companyId) {
  const session = sessions[companyId];
  if (session?.socket) {
    session.socket.end(undefined);
    session.socket.ws?.close();
  }
  delete sessions[companyId];

  const sessionDir = getSessionDir(companyId);
  if (existsSync(sessionDir)) {
    rmSync(sessionDir, { recursive: true, force: true });
  }

  await notifyLaravel(companyId, 'disconnected', { reason: 'user_logged_out' });
}

async function sendMessage(companyId, toPhone, body) {
  const session = sessions[companyId];
  if (!session || session.status !== 'connected') {
    throw new Error('Session not connected');
  }

  const jid = `${toPhone.replace('+', '')}@s.whatsapp.net`;
  const result = await session.socket.sendMessage(jid, { text: body });
  return {
    external_message_id: result?.key?.id || null,
  };
}

// === REST API Endpoints ===

// Start a new QR session
app.post('/api/sessions', async (req, res) => {
  try {
    const { company_id } = req.body;
    if (!company_id) {
      return res.status(400).json({ error: 'company_id is required' });
    }
    const state = await startSession(company_id);
    res.json({ data: state });
  } catch (err) {
    console.error('Error starting session:', err);
    res.status(500).json({ error: err.message });
  }
});

// Get current QR code and status
app.get('/api/sessions/:companyId/qr', (req, res) => {
  const { companyId } = req.params;
  const state = getSessionState(companyId);
  res.json({ data: state });
});

// Get session status
app.get('/api/sessions/:companyId/status', (req, res) => {
  const { companyId } = req.params;
  const state = getSessionState(companyId);
  res.json({ data: state });
});

// Logout / disconnect
app.post('/api/sessions/:companyId/logout', async (req, res) => {
  try {
    const { companyId } = req.params;
    await stopSession(companyId);
    res.json({ data: { status: 'disconnected' } });
  } catch (err) {
    console.error('Error logging out:', err);
    res.status(500).json({ error: err.message });
  }
});

// Send a message
app.post('/api/send-message', async (req, res) => {
  try {
    const { company_id, to, body } = req.body;
    if (!company_id || !to || !body) {
      return res.status(400).json({ error: 'company_id, to, and body are required' });
    }
    const result = await sendMessage(company_id, to, body);
    res.json({ data: result });
  } catch (err) {
    console.error('Error sending message:', err);
    res.status(500).json({ error: err.message });
  }
});

// Health check
app.get('/api/health', (req, res) => {
  res.json({ status: 'ok', sessions: Object.keys(sessions).length });
});

app.listen(PORT, async () => {
  console.log(`WhatsApp QR Service running on port ${PORT}`);
  console.log(`Sessions directory: ${SESSIONS_DIR}`);
  console.log(`Laravel webhook URL: ${LARAVEL_WEBHOOK_URL}`);

  // Load LID mapping and restore sessions on startup
  loadLidMap();
  await restoreSessions();
});