<?php
/**
 * DGV7 phase 2 — support chat widget (customer side).
 *
 * Included from bc_app_foot() (func/bc-plate-app.php) so every signed-in page gets it without per-page edits. It renders
 * nothing at all when the visitor is not a signed-in user, when the tenant has not switched chat
 * on, or when the schema is not usable.
 *
 * SECURITY: this file reads tenant settings only to decide *whether* to render and to fetch the
 * welcome line. No API key, model name or prompt reaches the browser. The browser talks only to
 * /web/ajax-support-chat.php, which does the privileged work server-side and re-checks the session.
 */

if (!isset($get_logged_user_details) || !is_array($get_logged_user_details) || empty($get_logged_user_details['id'])) return;
if (!isset($connection_server) || !($connection_server instanceof mysqli)) return;

$bc_widget_vendor = (int)($get_logged_user_details['vendor_id'] ?? 0);
if ($bc_widget_vendor <= 0) return;

require_once(__DIR__ . '/bc-support-chat.php');
if (!bc_chat_widget_enabled($bc_widget_vendor)) return;

$bc_widget_settings = bc_chat_settings($bc_widget_vendor);
$bc_widget_welcome  = (string)$bc_widget_settings['chat_welcome'];
// §28 quick replies: configured per tenant by the admin (Chat Settings); sane defaults
// otherwise. A chip whose label carries no server "action" just fills the composer.
$bc_widget_quick_src = $bc_widget_settings['chat_quick_replies'] ?? '';
if (is_string($bc_widget_quick_src)) {
    $bc_widget_quick_src = array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $bc_widget_quick_src))));
}
$bc_widget_quick = is_array($bc_widget_quick_src) && $bc_widget_quick_src
    ? array_values(array_filter(array_map(function ($q) {
        if (is_array($q) && !empty($q['label'])) return array('label' => (string)$q['label'], 'action' => (string)($q['action'] ?? ''));
        if (is_string($q) && trim($q) !== '') return array('label' => $q, 'action' => '');
        return null;
    }, $bc_widget_quick_src)))
    : array(
        array('label' => 'Check my wallet balance', 'action' => 'Please check my wallet balance.'),
        array('label' => 'A payment failed', 'action' => 'A payment failed. Please look into it.'),
        array('label' => 'Talk to a person', 'action' => 'I would like to talk to a person.'),
    );
// Absolute, because the footer is included from /web/* and from the site root.
$bc_widget_endpoint = '/web/ajax-support-chat.php';
$bc_widget_name     = (string)($get_logged_user_details['username'] ?? '');
?>
<style>
    /* §28 chat widget redesign: brand-aware, avatar + quick replies.
       Colours derive from --brand/--brand-fg on :root (bc-plate-app), with teal fallback. */
    #bcChat-launcher {
        position: fixed; right: 18px; bottom: 18px; z-index: 2147483000; width: 58px; height: 58px;
        display: flex; align-items: center; justify-content: center; border: 0; border-radius: 18px;
        background: linear-gradient(135deg, var(--brand, #0f766e), var(--accent, #115e59)); color: var(--brand-fg, #fff); cursor: pointer;
        box-shadow: 0 14px 30px -10px rgba(15,23,42,.55); transition: transform .18s ease, box-shadow .18s ease;
        animation: bcChat-in .35s cubic-bezier(.2,.9,.3,1.2);
    }
    #bcChat-launcher:hover { transform: translateY(-3px) scale(1.03); box-shadow: 0 20px 38px -12px rgba(15,23,42,.6); }
    #bcChat-launcher:focus-visible { outline: 3px solid var(--brand, #0f766e); outline-offset: 3px; }
    #bcChat-launcher svg { width: 26px; height: 26px; }
    #bcChat-launcher .bcChat-ico-close { display: none; }
    #bcChat-launcher[aria-expanded="true"] .bcChat-ico-chat { display: none; }
    #bcChat-launcher[aria-expanded="true"] .bcChat-ico-close { display: block; }
    #bcChat-badge {
        display: none; position: absolute; top: -5px; right: -5px; min-width: 20px; height: 20px; padding: 0 6px;
        border-radius: 999px; background: #dc2626; color: #fff; font: 700 11px/20px system-ui, sans-serif;
        box-shadow: 0 4px 10px rgba(220,38,38,.5); border: 2px solid #fff;
    }
    #bcChat-panel {
        position: fixed; right: 18px; bottom: 88px; z-index: 2147483000; width: 380px; max-width: calc(100vw - 24px);
        height: 560px; max-height: calc(100dvh - 110px); display: none; flex-direction: column;
        background: #fff; border-radius: 22px; overflow: hidden; border: 1px solid rgba(15,23,42,.06);
        box-shadow: 0 30px 60px -18px rgba(2,6,23,.55); font-family: Inter, system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
    }
    #bcChat-panel[data-open="1"] { display: flex; animation: bcChat-pop .28s cubic-bezier(.2,.9,.3,1.15); transform-origin: bottom right; }
    #bcChat-head {
        background: linear-gradient(120deg, var(--brand, #0f766e), var(--accent, #115e59)); color: var(--brand-fg, #fff);
        padding: 16px; display: flex; align-items: center; gap: 12px;
    }
    #bcChat-avatar {
        width: 44px; height: 44px; flex: 0 0 auto; border-radius: 14px; display: flex; align-items: center; justify-content: center;
        background: rgba(255,255,255,.18); border: 1px solid rgba(255,255,255,.3); position: relative;
    }
    #bcChat-avatar svg { width: 24px; height: 24px; }
    #bcChat-avatar::after { content: ""; position: absolute; right: -2px; bottom: -2px; width: 12px; height: 12px; border-radius: 50%; background: #22c55e; border: 2px solid #fff; }
    #bcChat-head h6 { margin: 0; font-size: 15.5px; font-weight: 800; letter-spacing: -.01em; }
    #bcChat-status { font-size: 11.5px; opacity: .88; display: block; margin-top: 1px; }
    #bcChat-close { background: rgba(255,255,255,.14); border: 0; color: inherit; width: 34px; height: 34px; border-radius: 10px; font-size: 18px; cursor: pointer; margin-left: auto; line-height: 1; }
    #bcChat-close:hover { background: rgba(255,255,255,.28); }
    #bcChat-log {
        flex: 1 1 auto; overflow-y: auto; padding: 16px 14px; background: #f8fafc; display: flex; flex-direction: column; gap: 10px;
    }
    .bcChat-msg { max-width: 84%; display: flex; flex-direction: column; animation: bcChat-msg .22s ease; }
    .bcChat-msg__who { font-size: 10px; font-weight: 800; text-transform: uppercase; letter-spacing: .06em; color: #94a3b8; margin-bottom: 4px; display: flex; align-items: center; gap: 5px; }
    .bcChat-msg__who::before { content: ""; width: 18px; height: 18px; border-radius: 6px; background: #e2e8f0; display: inline-block; }
    .bcChat-msg__body { padding: 10px 13px; border-radius: 16px; font-size: 14px; line-height: 1.5; word-wrap: break-word; white-space: pre-wrap; }
    .bcChat-msg--user { align-self: flex-end; align-items: flex-end; }
    .bcChat-msg--user .bcChat-msg__body { background: var(--brand, #0f766e); color: var(--brand-fg, #fff); border-bottom-right-radius: 5px; box-shadow: 0 6px 14px -8px rgba(15,23,42,.5); }
    .bcChat-msg--user .bcChat-msg__who::before { background: var(--brand, #0f766e); }
    .bcChat-msg--ai, .bcChat-msg--agent { align-self: flex-start; align-items: flex-start; }
    .bcChat-msg--ai .bcChat-msg__body, .bcChat-msg--agent .bcChat-msg__body { background: #fff; border: 1px solid #e8eef4; color: #0f172a; border-bottom-left-radius: 5px; box-shadow: 0 4px 12px -8px rgba(15,23,42,.25); }
    .bcChat-msg--ai .bcChat-msg__who::before { background: linear-gradient(135deg, #f59e0b, #d97706); }
    .bcChat-msg--agent .bcChat-msg__who::before { background: linear-gradient(135deg, #0ea5e9, #0369a1); }
    .bcChat-msg--system { align-self: center; max-width: 92%; }
    .bcChat-msg--system .bcChat-msg__body { background: #eef2f7; color: #64748b; font-size: 12px; border-radius: 10px; text-align: center; }
    #bcChat-quick { display: none; flex-wrap: wrap; gap: 6px; padding: 0 14px 10px; background: #f8fafc; }
    .bcChat-chip {
        border: 1.5px solid color-mix(in srgb, var(--brand, #0f766e) 30%, #e2e8f0); background: #fff;
        color: var(--brand-ink, #0f766e); border-radius: 999px; padding: 6px 12px; font-size: 12.5px; font-weight: 600;
        cursor: pointer; transition: all .15s ease;
    }
    .bcChat-chip:hover { background: var(--brand, #0f766e); color: var(--brand-fg, #fff); }
    #bcChat-typing { font-size: 12px; color: #64748b; padding: 0 16px 8px; display: none; font-style: italic; background: #f8fafc; }
    #bcChat-form { display: flex; gap: 8px; padding: 12px; border-top: 1px solid #eef2f7; background: #fff; align-items: flex-end; }
    #bcChat-input {
        flex: 1; resize: none; border: 1.5px solid #e2e8f0; border-radius: 14px;
        padding: 10px 13px; font: inherit; font-size: 14px; max-height: 110px; background: #f8fafc;
    }
    #bcChat-input:focus { outline: none; border-color: var(--brand, #0f766e); background: #fff; box-shadow: 0 0 0 3px color-mix(in srgb, var(--brand, #0f766e) 18%, transparent); }
    #bcChat-send {
        border: 0; border-radius: 14px; background: var(--brand, #0f766e); color: var(--brand-fg, #fff);
        width: 44px; height: 44px; display: flex; align-items: center; justify-content: center; cursor: pointer; flex: 0 0 auto;
        transition: transform .15s ease, opacity .15s ease;
    }
    #bcChat-send:hover:not(:disabled) { transform: scale(1.07); }
    #bcChat-send svg { width: 20px; height: 20px; }
    #bcChat-send:disabled { opacity: .5; cursor: default; }
    #bcChat-foot {
        display: flex; justify-content: space-between; gap: 8px;
        padding: 0 14px 12px; background: #fff; font-size: 12px;
    }
    #bcChat-foot button {
        background: #f1f5f9; border: 1px solid #e2e8f0; border-radius: 999px; padding: 6px 12px;
        color: #334155; cursor: pointer; font-weight: 600; font-size: 12px; transition: all .15s ease;
    }
    #bcChat-foot button:hover { background: var(--brand, #0f766e); color: var(--brand-fg, #fff); border-color: transparent; }
    @keyframes bcChat-in { from { opacity: 0; transform: translateY(14px) scale(.9); } to { opacity: 1; transform: translateY(0) scale(1); } }
    @keyframes bcChat-pop { from { opacity: 0; transform: translateY(14px) scale(.96); } to { opacity: 1; transform: translateY(0) scale(1); } }
    @keyframes bcChat-msg { from { opacity: 0; transform: translateY(6px); } to { opacity: 1; transform: translateY(0); } }
    @media (prefers-reduced-motion: reduce) {
        #bcChat-launcher, #bcChat-panel[data-open="1"], .bcChat-msg { animation: none; }
        #bcChat-launcher:hover, #bcChat-send:hover:not(:disabled) { transform: none; }
    }
    @media (max-width: 576px) {
        #bcChat-panel { right: 8px; left: 8px; bottom: 82px; width: auto; height: calc(100dvh - 100px); }
        #bcChat-launcher { right: 14px; bottom: 14px; }
    }
</style>

<button id="bcChat-launcher" type="button" aria-label="Open support chat" aria-expanded="false" aria-controls="bcChat-panel">
    <svg class="bcChat-ico-chat" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
        <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path>
    </svg>
    <svg class="bcChat-ico-close" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true">
        <line x1="5" y1="5" x2="19" y2="19"></line><line x1="19" y1="5" x2="5" y2="19"></line>
    </svg>
    <span id="bcChat-badge" aria-hidden="true"></span>
</button>

<div id="bcChat-panel" role="dialog" aria-modal="false" aria-labelledby="bcChat-title" data-open="0">
    <div id="bcChat-head">
        <span id="bcChat-avatar" aria-hidden="true">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M3 14v-2a9 9 0 0 1 18 0v2"></path>
                <path d="M21 19a2 2 0 0 1-2 2h-1a2 2 0 0 1-2-2v-3a2 2 0 0 1 2-2h3zM3 19a2 2 0 0 0 2 2h1a2 2 0 0 0 2-2v-3a2 2 0 0 0-2-2H3z"></path>
            </svg>
        </span>
        <div>
            <h6 id="bcChat-title">Support</h6>
            <span id="bcChat-status">Usually replies in a few minutes</span>
        </div>
        <button id="bcChat-close" type="button" aria-label="Close chat">&times;</button>
    </div>
    <div id="bcChat-log" role="log" aria-live="polite" aria-relevant="additions"></div>
    <div id="bcChat-quick" role="group" aria-label="Quick replies"></div>
    <div id="bcChat-typing">Assistant is typing…</div>
    <form id="bcChat-form" autocomplete="off">
        <textarea id="bcChat-input" rows="1" maxlength="4000" placeholder="Type your message…" aria-label="Message"></textarea>
        <button id="bcChat-send" type="submit" aria-label="Send message">
            <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M3.4 20.4 21.8 12 3.4 3.6l-.01 6.53L14 12 3.39 13.87z"></path></svg>
        </button>
    </form>
    <div id="bcChat-foot">
        <button id="bcChat-human" type="button">Talk to a person</button>
        <button id="bcChat-end" type="button">End chat</button>
    </div>
</div>

<script>
(function () {
    "use strict";
    var endpoint = <?php echo json_encode($bc_widget_endpoint); ?>;
    var launcher = document.getElementById("bcChat-launcher");
    var panel    = document.getElementById("bcChat-panel");
    var log      = document.getElementById("bcChat-log");
    var form     = document.getElementById("bcChat-form");
    var input    = document.getElementById("bcChat-input");
    var send     = document.getElementById("bcChat-send");
    var badge    = document.getElementById("bcChat-badge");
    var quick    = document.getElementById("bcChat-quick");
    var statusEl = document.getElementById("bcChat-status");
    var typing   = document.getElementById("bcChat-typing");

    var threadId  = 0;
    var lastId    = 0;
    var opened    = false;
    var polling   = null;
    var seen      = {};   // message ids already painted
    var seenAny   = false;

    var QUICK = <?php echo json_encode($bc_widget_quick, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
    function showQuick() {
        quick.innerHTML = "";
        QUICK.forEach(function (q) {
            var b = document.createElement("button");
            b.type = "button";
            b.className = "bcChat-chip";
            b.textContent = q.label;
            b.addEventListener("click", function () {
                hideQuick();
                if (q.action) { input.value = q.action; form.dispatchEvent(new Event("submit", { cancelable: true })); }
                else { input.value = q.label; input.focus(); }
            });
            quick.appendChild(b);
        });
        quick.style.display = QUICK.length ? "flex" : "none";
    }
    function hideQuick() { quick.style.display = "none"; }

    function post(payload) {
        return fetch(endpoint, {
            method: "POST",
            credentials: "same-origin",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify(payload)
        }).then(function (r) { return r.json(); });
    }

    // Text nodes only: a message body is never assigned to innerHTML, so a customer or an agent
    // cannot inject markup into another person's browser.
    function paint(m) {
        if (seen[m.id]) return;
        seen[m.id] = true;
        if (m.id > lastId) lastId = m.id;

        var wrap = document.createElement("div");
        wrap.className = "bcChat-msg bcChat-msg--" + (m.who || "system");

        if (m.who !== "system") {
            var who = document.createElement("div");
            who.className = "bcChat-msg__who";
            who.textContent = m.who === "ai" ? "AI Assistant" : (m.who === "user" ? "You" : (m.name || "Support Team"));
            wrap.appendChild(who);
        }
        var body = document.createElement("div");
        body.className = "bcChat-msg__body";
        body.appendChild(document.createTextNode(m.body));
        wrap.appendChild(body);

        log.appendChild(wrap);
        log.scrollTop = log.scrollHeight;
    }

    function applyThread(t) {
        if (!t) return;
        if (typeof t.unread === "number" && panel.getAttribute("data-open") !== "1") { unreadCount = t.unread; showBadge(); }
        threadId = t.id;
        statusEl.textContent = t.status === "with_agent" ? "A person is replying"
            : t.status === "pending_agent" ? "Connecting you to a person…"
            : t.ai_active ? "Assistant online" : "Usually replies in a few minutes";
    }

    function setTyping(on) {
        typing.style.display = on ? "block" : "none";
        if (on) log.scrollTop = log.scrollHeight;
    }

    var unreadCount = 0;
    function showBadge() {
        // Live response count: how many support replies are waiting unread.
        badge.textContent = unreadCount > 99 ? "99+" : String(unreadCount);
        badge.style.display = unreadCount > 0 ? "block" : "none";
    }

    function open() {
        panel.setAttribute("data-open", "1");
        launcher.setAttribute("aria-expanded", "true");
        unreadCount = 0;
        showBadge();
        input.focus();
        if (opened) return;
        opened = true;

        setTyping(true);
        post({ action: "open" }).then(function (res) {
            setTyping(false);
            if (!res.ok) { statusEl.textContent = res.error || "Chat unavailable"; return; }
            applyThread(res.thread);
            (res.messages || []).forEach(paint);
            // §28 quick replies: first-run shortcuts, shown only until the user sends something.
            if (!seenAny) showQuick();
            startPolling();
        }).catch(function () {
            setTyping(false);
            statusEl.textContent = "Could not connect. Please try again.";
        });
    }

    function close() {
        panel.setAttribute("data-open", "0");
        launcher.setAttribute("aria-expanded", "false");
    }

    function startPolling() {
        if (polling) return;
        polling = setInterval(function () {
            if (!threadId || document.hidden) return;
            post({ action: "poll", thread_id: threadId, after_id: lastId }).then(function (res) {
                if (!res.ok) return;
                applyThread(res.thread);
                var fresh = res.messages || [];
                var fromAgent = false;
                fresh.forEach(function (m) { if (m.who === "agent" || m.who === "ai") fromAgent = true; paint(m); });
                // While the panel is shut, new answers surface as a live count badge.
                if (fromAgent && panel.getAttribute("data-open") !== "1") {
                    unreadCount += fresh.filter(function (m) { return m.who === "agent" || m.who === "ai"; }).length;
                    showBadge();
                }
            }).catch(function () { /* a dropped poll is not an error worth showing */ });
        }, 5000);
    }

    launcher.addEventListener("click", function () {
        if (panel.getAttribute("data-open") === "1") close(); else open();
    });
    document.getElementById("bcChat-close").addEventListener("click", close);

    form.addEventListener("submit", function (e) {
        e.preventDefault();
        var text = input.value.trim();
        if (!text || !threadId) return;
        input.value = "";
        input.style.height = "auto";
        seenAny = true;
        hideQuick();
        send.disabled = true;

        // Paint the customer's own line immediately; the server echo is the same id, so it is deduped.
        paint({ id: "local-" + Date.now(), who: "user", body: text, name: "You" });

        post({ action: "send", thread_id: threadId, message: text }).then(function (res) {
            send.disabled = false;
            if (!res.ok) { statusEl.textContent = res.error || "Message not sent"; return; }
            applyThread(res.thread);
            (res.messages || []).forEach(paint);
        }).catch(function () {
            send.disabled = false;
            statusEl.textContent = "Message not sent. Check your connection.";
        });
    });

    // Enter sends, Shift+Enter breaks the line: the behaviour people expect in a chat box.
    input.addEventListener("keydown", function (e) {
        if (e.key === "Enter" && !e.shiftKey) { e.preventDefault(); form.requestSubmit(); }
    });
    input.addEventListener("input", function () {
        input.style.height = "auto";
        input.style.height = Math.min(input.scrollHeight, 90) + "px";
    });

    document.getElementById("bcChat-human").addEventListener("click", function () {
        if (!threadId) return;
        post({ action: "escalate", thread_id: threadId }).then(function (res) {
            if (!res.ok) return;
            applyThread(res.thread);
            (res.messages || []).forEach(paint);
        });
    });

    // End chat closes the thread and starts a fresh one, so the next question does not reopen a
    // transcript the customer just finished with.
    document.getElementById("bcChat-end").addEventListener("click", function () {
        if (!threadId) return;
        post({ action: "close", thread_id: threadId }).then(function () {
            log.innerHTML = "";
            seen = {}; lastId = 0; threadId = 0; opened = false;
            statusEl.textContent = "Conversation ended";
            setTimeout(function () { open(); }, 1200);
        });
    });
})();
</script>
