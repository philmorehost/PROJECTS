(function() {
    /**
     * ai-assistant.js — DGV6.90 AI Edition
     * Lightweight Floating AI Assistant Widget with Persistent History
     */

    const HANDLER_URL = window.__ai_handler_url || '/web/ai-handler.php';
    const PAGE_SLUG   = window.__ai_page_slug || 'unknown';

    // ─── 0. Check if we are on the Dedicated Terminal Page ───────
    // If we are, we don't want to inject the floating widget.
    if (window.location.pathname.includes('AI-Assistant.php')) {
        console.log('AI Terminal detected. Floating widget disabled.');
        return;
    }

    // ─── 1. Styles ───────────────────────────────────────────
    const styles = `
        #ai-fab { position: fixed; bottom: 20px; right: 20px; width: 60px; height: 60px; border-radius: 50%; background: linear-gradient(135deg, #6366f1, #a855f7); color: white; border: none; box-shadow: 0 4px 15px rgba(0,0,0,0.2); cursor: pointer; z-index: 10000; font-size: 24px; transition: transform 0.3s cubic-bezier(0.175, 0.885, 0.32, 1.275); }
        #ai-fab:hover { transform: scale(1.1) rotate(5deg); }
        #ai-fab.recording { background: #ef4444; animation: ai-pulse 1.5s infinite; }
        @keyframes ai-pulse { 0% { box-shadow: 0 0 0 0 rgba(239, 68, 68, 0.7); } 70% { box-shadow: 0 0 0 15px rgba(239, 68, 68, 0); } 100% { box-shadow: 0 0 0 0 rgba(239, 68, 68, 0); } }
        #ai-panel { position: fixed; bottom: 90px; right: 20px; width: 380px; height: 550px; background: white; border-radius: 20px; box-shadow: 0 10px 40px rgba(0,0,0,0.15); display: flex; flex-direction: column; overflow: hidden; transform: translateY(20px) scale(0.95); opacity: 0; pointer-events: none; transition: all 0.3s ease; z-index: 9999; border: 1px solid #e2e8f0; }
        #ai-panel.open { transform: translateY(0) scale(1); opacity: 1; pointer-events: all; }
        #ai-header { padding: 15px 20px; background: linear-gradient(to right, #9333ea, #a855f7); color: white; display: flex; justify-content: space-between; align-items: center; }
        #ai-header-btns { display: flex; gap: 10px; align-items: center; }
        .ai-header-btn { background: rgba(255,255,255,0.2); border: none; color: white; padding: 5px 8px; border-radius: 6px; font-size: 14px; cursor: pointer; transition: background 0.2s; }
        .ai-header-btn:hover { background: rgba(255,255,255,0.3); }
        #ai-tokens { font-size: 11px; opacity: 0.9; margin-top: 2px; }
        #ai-messages { flex: 1; overflow-y: auto; padding: 20px; display: flex; flex-direction: column; gap: 12px; background: #f8fafc; scroll-behavior: smooth; }
        .ai-msg { max-width: 85%; padding: 12px 16px; border-radius: 18px; font-size: 14px; line-height: 1.5; word-wrap: break-word; }
        .ai-msg.user { align-self: flex-end; background: #6366f1; color: white; border-bottom-right-radius: 4px; }
        .ai-msg.bot { align-self: flex-start; background: white; color: #1e293b; border-bottom-left-radius: 4px; box-shadow: 0 2px 5px rgba(0,0,0,0.05); }
        .ai-msg.info { align-self: stretch; background: #e2e8f0; color: #1e293b; border-radius: 12px; font-size: 14px; }
        .ai-msg.error { align-self: center; background: #fee2e2; color: #991b1b; font-size: 12px; }
        .ai-quick-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-top: 5px; width: 100%; }
        .ai-quick-btn { background: white; border: 1.5px solid #9333ea; color: #1e293b; padding: 10px 12px; border-radius: 25px; font-size: 14px; cursor: pointer; transition: all 0.2s; text-align: center; }
        .ai-quick-btn:hover { background: #f3e8ff; border-color: #7e22ce; transform: translateY(-1px); }
        #ai-footer { padding: 15px; background: white; border-top: 1px solid #e2e8f0; display: flex; gap: 10px; align-items: center; position: relative; }
        .ai-input-wrapper { flex: 1; position: relative; display: flex; align-items: center; }
        #ai-input { width: 100%; border: 1px solid #9333ea; border-radius: 25px; padding: 10px 45px 10px 15px; outline: none; font-size: 14px; transition: box-shadow 0.3s; }
        #ai-input:focus { box-shadow: 0 0 0 2px rgba(147, 51, 234, 0.2); }
        #ai-voice { position: absolute; right: 10px; color: #9333ea; font-size: 18px; }
        .ai-btn { background: none; border: none; cursor: pointer; color: #64748b; font-size: 24px; transition: color 0.3s; display: flex; align-items: center; }
        .ai-btn:hover { color: #9333ea; }
        .ai-typing { font-size: 12px; color: #94a3b8; margin-bottom: 5px; font-style: italic; }
        @keyframes ai-hand-wave { 0%, 100% { transform: rotate(0deg); } 25% { transform: rotate(-20deg); } 75% { transform: rotate(20deg); } }
        @keyframes ai-hand-point { 0%, 100% { transform: translateX(0); } 50% { transform: translateX(10px); } }
        #ai-hand-guide { font-size: 22px; cursor: pointer; transition: opacity 0.3s; z-index: 10; }
        @media (max-width: 480px) { #ai-panel { width: calc(100% - 40px); height: 80vh; bottom: 85px; } }
    `;

    // ─── 2. Template ──────────────────────────────────────────
    const template = `
        <button id="ai-fab" title="AI Assistant" aria-haspopup="true" aria-expanded="false">🤖</button>
        <div id="ai-panel">
            <div id="ai-header">
                <div>
                    <div style="font-weight: 700; font-size: 16px;">✨ AI Assistant</div>
                    <div id="ai-tokens">Loading tokens...</div>
                </div>
                <div id="ai-header-btns">
                    <div id="ai-hand-guide" style="display:none;">👋</div>
                    <button id="ai-terminal-btn" class="ai-header-btn" onclick="window.location.href='/web/AI-Assistant.php'" title="Full Terminal"><i class="bi bi-display"></i> Terminal</button>
                    <button id="ai-close" style="background:none; border:none; color:white; font-size: 24px; cursor:pointer; line-height:1;">&times;</button>
                </div>
            </div>
            <div id="ai-messages"></div>
            <div id="ai-footer">
                <div class="ai-input-wrapper">
                    <button id="ai-voice" class="ai-btn" title="Voice Command">🎤</button>
                    <input type="text" id="ai-input" placeholder="Ask me anything..." autocomplete="off">
                </div>
                <button id="ai-send" class="ai-btn" title="Send message">
                    <svg width="30" height="30" viewBox="0 0 24 24" fill="none" stroke="#9333ea" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><line x1="22" y1="2" x2="11" y2="13"></line><polygon points="22 2 15 22 11 13 2 9 22 2"></polygon></svg>
                </button>
            </div>
        </div>
    `;

    // ─── 3. Initialization ────────────────────────────────────
    function injectWidget() {
        if (document.getElementById('ai-widget-container')) return;
        const styleEl = document.createElement('style');
        styleEl.textContent = styles;
        document.head.appendChild(styleEl);

        const container = document.createElement('div');
        container.id = 'ai-widget-container';
        container.innerHTML = template;
        document.body.appendChild(container);
    }

    function openPanel() {
        const panel = document.getElementById('ai-panel');
        const fab   = document.getElementById('ai-fab');
        const initialMsg = window.__ai_init_msg;
        
        panel.classList.add('open');
        fab.setAttribute('aria-expanded', 'true');
        fab.textContent = '✕';
        
        // Only load history if messages are empty
        const msgs = document.getElementById('ai-messages');
        if (msgs.children.length === 0) {
            loadHistory();
            if (msgs.children.length === 0) {
                if (initialMsg) appendMsg('bot', initialMsg);
                renderWelcome();
            } else {
                renderWelcome();
            }
        }
        
        document.getElementById('ai-input').focus();
    }

    function renderWelcome() {
        const actions = [
            { label: 'Buy Airtime', prompt: 'Buy MTN 500 airtime for 08012345678' },
            {
                label: 'Buy Data',
                isParent: true,
                sub: [
                    { label: 'MTN SME', prompt: 'Buy MTN 1GB SME data for 08123456789' },
                    { label: 'Shared Data', prompt: 'Buy MTN 1GB Shared Data for 08123456789' },
                    { label: 'CG Data', prompt: 'Buy MTN 1GB CG data for 08123456789' },
                    { label: 'Direct Data', prompt: 'Buy MTN 1GB DD-DATA for 08123456789' }
                ]
            },
            {
                label: 'Pay Utility',
                isParent: true,
                sub: [
                    { label: 'Pay PHCN', prompt: 'Pay IKEDC 1000 for meter 11223344556' },
                    { label: 'Pay Cable', prompt: 'Pay DSTV 5000 for smartcard 1020304050' },
                    { label: 'Pay Betting', prompt: 'Topup Bet9ja 1000 for ID 12345678' }
                ]
            },
            { label: 'Buy Exam Pin', prompt: 'Buy 1 WAEC pin' },
        ];

        renderActionGrid(actions, true);
    }

    function renderActionGrid(actions, isMain = false) {
        const msgs = document.getElementById('ai-messages');

        if (isMain) {
            const instr = document.createElement('div');
            instr.className = 'ai-msg info';
            instr.innerHTML = `Need more space? Click <b>Terminal</b> above. Or select an action below:`;
            msgs.appendChild(instr);
        }

        const grid = document.createElement('div');
        grid.className = 'ai-quick-grid';

        actions.forEach(action => {
            const btn = document.createElement('div');
            btn.className = 'ai-quick-btn';
            btn.textContent = action.label;
            btn.onclick = () => {
                if (action.isParent) {
                    const info = document.createElement('div');
                    info.className = 'ai-msg info';
                    info.style.marginTop = '10px';
                    info.textContent = `Select a ${action.label} type:`;
                    msgs.appendChild(info);
                    renderActionGrid(action.sub);
                    return;
                }

                const input = document.getElementById('ai-input');
                input.value = action.prompt;
                input.focus();

                let selectionStart = -1, selectionEnd = -1;
                const phoneMatch = action.prompt.match(/\d{11}/);
                const amountMatch = action.prompt.match(/\d{3,5}/);

                if (phoneMatch) {
                    selectionStart = action.prompt.indexOf(phoneMatch[0]);
                    selectionEnd = selectionStart + phoneMatch[0].length;
                } else if (amountMatch) {
                    selectionStart = action.prompt.indexOf(amountMatch[0]);
                    selectionEnd = selectionStart + amountMatch[0].length;
                }

                if (selectionStart !== -1) {
                    input.setSelectionRange(selectionStart, selectionEnd);
                }
            };
            grid.appendChild(btn);
        });

        msgs.appendChild(grid);
        msgs.scrollTop = msgs.scrollHeight;
    }

    function closePanel() {
        const panel = document.getElementById('ai-panel');
        const fab   = document.getElementById('ai-fab');
        panel.classList.remove('open');
        fab.setAttribute('aria-expanded', 'false');
        fab.textContent = '🤖';
    }

    function formatMarkdown(text) {
        return text
            .replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>')
            .replace(/\*(.*?)\*/g, '<em>$1</em>')
            .replace(/\n/g, '<br>')
            .replace(/^- (.*)/gm, '• $1');
    }

    function appendMsg(role, text, skipSave = false) {
        const msgs = document.getElementById('ai-messages');
        const div  = document.createElement('div');
        div.className = `ai-msg ${role}`;
        
        if (role === 'bot') {
            div.innerHTML = formatMarkdown(text);
        } else {
            div.textContent = text;
        }
        
        msgs.appendChild(div);
        msgs.scrollTop = msgs.scrollHeight;

        if (!skipSave && (role === 'user' || role === 'bot')) {
            saveHistory();
        }
        return div;
    }

    // ─── 4. History Management ────────────────────────────────
    function getHistoryKey() {
        const ctx = window.__ai_context || {};
        const user = ctx.username || 'anon';
        const vid  = ctx.vendor_id || '0';
        return `ai_hist_${vid}_${user}`;
    }

    function saveHistory() {
        const msgs = document.getElementById('ai-messages');
        const history = [];
        const msgEls = msgs.querySelectorAll('.ai-msg.user, .ai-msg.bot');
        
        const start = Math.max(0, msgEls.length - 20);
        for (let i = start; i < msgEls.length; i++) {
            const el = msgEls[i];
            history.push({
                role: el.classList.contains('user') ? 'user' : 'bot',
                text: el.innerText || el.textContent
            });
        }

        if (history.length > 0) {
            localStorage.setItem(getHistoryKey(), JSON.stringify({
                ts: Date.now(),
                msgs: history
            }));
        }
    }

    function loadHistory() {
        const key = getHistoryKey();
        const raw = localStorage.getItem(key);
        if (!raw) return;

        try {
            const data = JSON.parse(raw);
            const now = Date.now();
            const ageHours = (now - data.ts) / (1000 * 60 * 60);
            if (ageHours > 48) {
                localStorage.removeItem(key);
                return;
            }
            data.msgs.forEach(m => {
                appendMsg(m.role, m.text, true);
            });
        } catch (e) {
            console.error('AI History load failed', e);
        }
    }

    // ─── 5. Send Message ──────────────────────────────────────
    async function sendMessage(forceAction = null) {
        const input  = document.getElementById('ai-input');
        const sendBtn = document.getElementById('ai-send');
        const prompt = input.value.trim();
        if (!prompt) return;

        const isTx = /send|buy|recharge|topup|data|airtime|pay|pin|exam/i.test(prompt);
        let action = forceAction || (isTx ? 'voice_vtu' : 'chat');
        let payloadExtra = {};

        const isConfirmation = /\b(yes|confirm|proceed|go ahead|yep|sure|ok|do it|okay|process)\b/i.test(prompt);
        const pendingVtu = sessionStorage.getItem('pending_vtu');

        if (isConfirmation && pendingVtu) {
            action = 'execute_vtu';
            payloadExtra.intent = JSON.parse(pendingVtu);
            sessionStorage.removeItem('pending_vtu'); 
        }

        input.value = '';
        sendBtn.disabled = true;
        appendMsg('user', prompt);

        const typing = document.createElement('div');
        typing.className = 'ai-typing';
        typing.textContent = 'Thinking...';
        document.getElementById('ai-messages').appendChild(typing);
        document.getElementById('ai-messages').scrollTop = 99999;

        try {
            const context = window.__ai_context || {};
            const resp = await fetch(HANDLER_URL, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ 
                    prompt, 
                    action: action, 
                    ...payloadExtra,
                    context: {
                        page: PAGE_SLUG,
                        ...context
                    }
                }),
            });
            const data = await resp.json();
            if (typing && typing.parentNode) typing.remove();

            if (data.status === 'success') {
                appendMsg('bot', data.response);
                if (data.pending_vtu) sessionStorage.setItem('pending_vtu', JSON.stringify(data.pending_vtu));
                
                const tokEl = document.getElementById('ai-tokens');
                if (tokEl && data.tokens_remaining !== undefined) {
                    tokEl.textContent = `${data.tokens_remaining.toLocaleString()} tokens remaining`;
                }

                // Triggers the beautiful transaction details modal (receipt) when transaction is completed via AI
                if (data.ref && typeof showTransactionDetails === 'function') {
                    showTransactionDetails(data.ref);
                }
            } else {
                appendMsg('error', data.message || 'Something went wrong.');
            }
        } catch (e) {
            if (typing && typing.parentNode) typing.remove();
            appendMsg('error', 'Connection error.');
        } finally {
            sendBtn.disabled = false;
            input.focus();
        }
    }

    // ─── 6. Wire Up Events ────────────────────────────────────
    function bindEvents() {
        document.getElementById('ai-fab').onclick   = () => {
            const panel = document.getElementById('ai-panel');
            panel.classList.contains('open') ? closePanel() : openPanel();
        };
        document.getElementById('ai-close').onclick = closePanel;
        document.getElementById('ai-send').onclick  = () => sendMessage();
        document.getElementById('ai-input').onkeydown = (e) => {
            if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); sendMessage(); }
        };

        const voiceBtn = document.getElementById('ai-voice');
        if ('webkitSpeechRecognition' in window || 'SpeechRecognition' in window) {
            const SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition;
            const recognition = new SpeechRecognition();
            recognition.lang = 'en-NG';
            recognition.onstart = () => voiceBtn.classList.add('recording');
            recognition.onend = () => voiceBtn.classList.remove('recording');
            recognition.onresult = (event) => {
                const transcript = event.results[0][0].transcript;
                document.getElementById('ai-input').value = transcript;
                sendMessage();
            };
            voiceBtn.onclick = () => {
                try { recognition.start(); } catch(e) { recognition.stop(); }
            };
        } else {
            voiceBtn.style.display = 'none';
        }
    }

    // ─── 7. Boot ─────────────────────────────────────────────
    function boot() {
        injectWidget();
        bindEvents();
        const tokEl = document.getElementById('ai-tokens');
        if (tokEl && window.__ai_tokens !== undefined) {
            tokEl.textContent = `${window.__ai_tokens.toLocaleString()} tokens remaining`;
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
