/**
 * DGV6.90 AI Edition — WhatsApp Gateway Bridge (Multi-Session)
 * Uses @whiskeysockets/baileys
 *
 * Endpoints:
 *   GET  /status?session_id=X
 *   GET  /qr?session_id=X
 *   POST /send?session_id=X       → { phone, message }
 *   POST /disconnect?session_id=X
 *   POST /reset?session_id=X
 */

'use strict';

const { default: makeWASocket, useMultiFileAuthState, DisconnectReason, fetchLatestBaileysVersion, Browsers } = require('@whiskeysockets/baileys');
const { Boom } = require('@hapi/boom');
const P = require('pino');
const http = require('http');
const qrcode = require('qrcode');
const fs = require('fs');
const path = require('path');

const CONFIG = {
    PORT:           parseInt(process.env.WA_PORT || '3001'),
    HOST:           '127.0.0.1',
    AUTH_BASE_DIR:  path.join(__dirname, '.wa_auth'),
    RATE_LIMIT_MS:  2000,
    MAX_QUEUE:      100,
    LOG_LEVEL:      'info',
};

// ── Multi-Session State ──────────────────────────────────────────────────────
const sessions = new Map(); // session_id -> { sock, qrBase64, isOnline, phone, queue, isSending }

const logger = P({ level: CONFIG.LOG_LEVEL });

if (!fs.existsSync(CONFIG.AUTH_BASE_DIR)) {
    try {
        fs.mkdirSync(CONFIG.AUTH_BASE_DIR, { recursive: true });
        console.log(`[WA] Created auth directory: ${CONFIG.AUTH_BASE_DIR}`);
    } catch (err) {
        console.error(`[WA] FAILED to create auth directory: ${err.message}`);
    }
}

async function processQueue(sessionId) {
    const session = sessions.get(sessionId);
    if (!session || session.isSending || session.queue.length === 0) return;
    session.isSending = true;

    const { phone, message, resolve, reject } = session.queue.shift();
    try {
        const jid = phone.replace(/[^0-9]/g, '') + '@s.whatsapp.net';
        const result = await session.sock.sendMessage(jid, { text: message });
        resolve({ success: true, message_id: result.key.id });
    } catch (err) {
        reject({ success: false, error: err.message });
    }

    await new Promise(r => setTimeout(r, CONFIG.RATE_LIMIT_MS));
    session.isSending = false;
    processQueue(sessionId);
}

async function getSession(sessionId) {
    if (sessions.has(sessionId)) return sessions.get(sessionId);

    const sessionDir = path.join(CONFIG.AUTH_BASE_DIR, `session_${sessionId}`);
    const { state, saveCreds } = await useMultiFileAuthState(sessionDir);
    const { version } = await fetchLatestBaileysVersion();

    const session = {
        sock: null,
        qrBase64: null,
        isOnline: false,
        phone: null,
        queue: [],
        isSending: false,
        reconnectAttempts: 0
    };

    const sock = makeWASocket({
        version,
        logger,
        auth: state,
        printQRInTerminal: false,
        browser: Browsers.ubuntu('Chrome'),
        syncFullHistory: false,
        linkPreviewImageThumbnailWidth: 192,
        generateHighQualityLinkPreview: true,
    });

    session.sock = sock;
    sessions.set(sessionId, session);

    sock.ev.on('creds.update', async () => {
        try {
            await saveCreds();
        } catch (err) {
            console.error(`[WA] Session ${sessionId} FAILED to save credentials: ${err.message}`);
        }
    });

    sock.ev.on('connection.update', async (update) => {
        const { connection, lastDisconnect, qr } = update;

        if (qr) {
            try { session.qrBase64 = await qrcode.toDataURL(qr); } catch (e) {}
        }

        if (connection === 'open') {
            session.isOnline = true;
            session.qrBase64 = null;
            session.reconnectAttempts = 0;
            session.phone = sock.user?.id?.split(':')[0] || 'unknown';
            console.log(`[WA] Session ${sessionId} Connected: ${session.phone}`);
        }

        if (connection === 'close') {
            session.isOnline = false;
            session.phone = null;
            const reason = new Boom(lastDisconnect?.error)?.output?.statusCode;

            if (reason === DisconnectReason.loggedOut) {
                console.log(`[WA] Session ${sessionId} Logged Out.`);
                try { fs.rmSync(sessionDir, { recursive: true, force: true }); } catch(e) {}
                sessions.delete(sessionId);
            } else if (session.reconnectAttempts < 5) {
                session.reconnectAttempts++;
                setTimeout(() => getSession(sessionId), 5000);
            }
        }
    });

    return session;
}

// Auto-load existing sessions
fs.readdirSync(CONFIG.AUTH_BASE_DIR).forEach(dir => {
    if (dir.startsWith('session_')) {
        const sid = dir.replace('session_', '');
        getSession(sid);
    }
});

const server = http.createServer(async (req, res) => {
    const remoteAddr = req.socket.remoteAddress;
    if (remoteAddr !== '127.0.0.1' && remoteAddr !== '::1' && remoteAddr !== '::ffff:127.0.0.1') {
        res.writeHead(403); return res.end('Forbidden');
    }

    const parsedUrl = new URL(req.url, `http://${req.headers.host}`);
    const url = parsedUrl.pathname;
    const sessionId = parsedUrl.searchParams.get('session_id') || 'default';

    const session = await getSession(sessionId);
    console.log(`[WA] Request: ${req.method} ${url} (Session: ${sessionId})`);

    const json = (status, data) => {
        res.writeHead(status, { 'Content-Type': 'application/json' });
        res.end(JSON.stringify(data));
    };

    if (req.method === 'GET' && url === '/status') {
        return json(200, {
            success: true,
            online: session.isOnline,
            phone: session.phone,
            qr_ready: session.qrBase64 !== null,
            queue_len: session.queue.length
        });
    }

    if (req.method === 'GET' && url === '/qr') {
        return json(200, { success: !!session.qrBase64, qr_base64: session.qrBase64 });
    }

    if (req.method === 'GET' && url === '/pairing-code') {
        const phone = parsedUrl.searchParams.get('phone');
        if (!phone) return json(400, { success: false, error: 'Missing phone number' });
        if (session.isOnline) return json(400, { success: false, error: 'Already connected' });

        try {
            const code = await session.sock.requestPairingCode(phone.replace(/[^0-9]/g, ''));
            return json(200, { success: true, code });
        } catch (err) {
            return json(500, { success: false, error: err.message });
        }
    }

    if (req.method === 'POST' && url === '/send') {
        let chunks = [];
        req.on('data', chunk => chunks.push(chunk));
        req.on('end', async () => {
            const body = Buffer.concat(chunks).toString('utf8');
            const data = JSON.parse(body || '{}');
            if (!session.isOnline) return json(503, { success: false, error: 'Offline' });
            if (!data.phone || !data.message) return json(400, { success: false, error: 'Missing params' });

            try {
                const result = await new Promise((resolve, reject) => {
                    session.queue.push({ phone: data.phone, message: data.message, resolve, reject });
                    processQueue(sessionId);
                });
                json(200, result);
            } catch (err) {
                json(500, { success: false, error: err.message });
            }
        });
        return;
    }

    if (req.method === 'POST' && url === '/disconnect') {
        if (session.sock) await session.sock.logout().catch(() => {});
        return json(200, { success: true });
    }

    if (req.method === 'POST' && url === '/reset') {
        if (session.sock) session.sock.logout().catch(() => {});
        const sessionDir = path.join(CONFIG.AUTH_BASE_DIR, `session_${sessionId}`);
        try { fs.rmSync(sessionDir, { recursive: true, force: true }); } catch(e) {}
        sessions.delete(sessionId);
        return json(200, { success: true, message: 'Session reset. Please refresh and scan again.' });
    }

    res.writeHead(404); res.end();
});

server.listen(CONFIG.PORT, CONFIG.HOST, () => {
    console.log(`[WA] Multi-Session Bridge listening on ${CONFIG.HOST}:${CONFIG.PORT}`);
});
