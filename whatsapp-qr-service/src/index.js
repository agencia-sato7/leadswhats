import express from 'express';
import { makeWASocket, useMultiFileAuthState, DisconnectReason, fetchLatestBaileysVersion } from '@whiskeysockets/baileys';
import pino from 'pino';
import QRCode from 'qrcode';
import { existsSync, mkdirSync, rmSync, readFileSync, writeFileSync } from 'fs';
import { join, dirname } from 'path';
import { fileURLToPath } from 'url';

const __dirname = dirname(fileURLToPath(import.meta.url));
const SESSIONS_DIR = join(__dirname, '..', 'sessions');
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
    await fetch(LARAVEL_WEBHOOK_URL, {
      method: 'POST',
      headers,
      body: JSON.stringify(payload),
    });
  } catch (err) {
    console.error(`[${companyId}] Failed to notify Laravel:`, err.message);
  }
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
    const messages = msg.messages || [];
    for (const message of messages) {
      if (!message.key || message.key.fromMe) continue;

      const body = message.message?.conversation ||
        message.message?.extendedTextMessage?.text ||
        message.message?.imageMessage?.caption ||
        '';

      if (!body) continue;

      const phone = message.key.remoteJid?.replace('@s.whatsapp.net', '') || '';
      const messageId = message.key.id || '';

      console.log(`[${companyId}] Message from ${phone}: ${body.substring(0, 50)}`);

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

app.listen(PORT, () => {
  console.log(`WhatsApp QR Service running on port ${PORT}`);
  console.log(`Sessions directory: ${SESSIONS_DIR}`);
  console.log(`Laravel webhook URL: ${LARAVEL_WEBHOOK_URL}`);
});