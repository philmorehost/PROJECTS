<?php
/**
 * DGV7 phase 2 — support chat widget (customer side).
 *
 * Included from bc-footer.php so every signed-in page gets it without per-page edits. It renders
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
// Absolute, because the footer is included from /web/* and from the site root.
$bc_widget_endpoint = '/web/ajax-support-chat.php';
$bc_widget_name     = (string)($get_logged_user_details['username'] ?? '');
?>
<style>
    /* Namespaced under #bcChat so nothing here can collide with the host page's own styles. */
    #bcChat-launcher {
        position: fixed; right: 20px; bottom: 20px; z-index: 1080;
        width: 56px; height: 56px; border-radius: 50%; border: 0;
        background: #0f766e; color: #fff; cursor: pointer;
        box-shadow: 0 8px 24px rgba(15, 118, 110, .35);
        display: flex; align-items: center; justify-content: center;
        transition: transform .15s ease;
    }
    #bcChat-launcher:hover { transform: scale(1.05); }
    #bcChat-launcher:focus-visible { outline: 3px solid #99f6e4; outline-offset: 2px; }
    #bcChat-badge {
        position: absolute; top: -2px; right: -2px; min-width: 18px; height: 18px;
        border-radius: 9px; background: #dc2626; color: #fff; font: 600 11px/18px system-ui, sans-serif;
        text-align: center; padding: 0 4px; display: none;
    }
    #bcChat-panel {
        position: fixed; right: 20px; bottom: 88px; z-index: 1081;
        width: min(380px, calc(100vw - 32px)); max-height: min(560px, calc(100vh - 120px));
        background: #fff; border-radius: 16px; overflow: hidden;
        box-shadow: 0 20px 50px rgba(0, 0, 0, .22);
        display: none; flex-direction: column;
        font-family: system-ui, -apple-system, "Segoe UI", sans-serif;
    }
    #bcChat-panel[data-open="1"] { display: flex; }
    #bcChat-head {
        background: #0f766e; color: #fff; padding: 14px 16px;
        display: flex; align-items: center; justify-content: space-between; gap: 8px;
    }
    #bcChat-head h6 { margin: 0; font-size: 15px; font-weight: 600; }
    #bcChat-status { font-size: 11px; opacity: .85; display: block; }
    #bcChat-close { background: transparent; border: 0; color: #fff; font-size: 20px; cursor: pointer; line-height: 1; }
    #bcChat-log {
        flex: 1 1 auto; overflow-y: auto; padding: 14px; background: #f8fafc;
        display: flex; flex-direction: column; gap: 10px; min-height: 220px;
    }
    .bcChat-msg { max-width: 84%; font-size: 13.5px; line-height: 1.45; }
    .bcChat-msg__who { font-size: 10.5px; text-transform: uppercase; letter-spacing: .04em; color: #64748b; margin-bottom: 3px; }
    .bcChat-msg__body { padding: 9px 12px; border-radius: 12px; word-wrap: break-word; white-space: pre-wrap; }
    .bcChat-msg--user { align-self: flex-end; text-align: right; }
    .bcChat-msg--user .bcChat-msg__body { background: #0f766e; color: #fff; border-bottom-right-radius: 4px; }
    .bcChat-msg--ai .bcChat-msg__body,
    .bcChat-msg--agent .bcChat-msg__body { background: #fff; border: 1px solid #e2e8f0; border-bottom-left-radius: 4px; }
    .bcChat-msg--system { align-self: center; max-width: 100%; text-align: center; }
    .bcChat-msg--system .bcChat-msg__body { background: #e2e8f0; color: #475569; font-size: 12px; border-radius: 8px; }
    #bcChat-typing { font-size: 12px; color: #64748b; padding: 0 14px 6px; display: none; font-style: italic; }
    #bcChat-form { display: flex; gap: 8px; padding: 10px; border-top: 1px solid #e2e8f0; background: #fff; }
    #bcChat-input {
        flex: 1; resize: none; border: 1px solid #cbd5e1; border-radius: 10px;
        padding: 9px 11px; font-size: 13.5px; font-family: inherit; max-height: 90px;
    }
    #bcChat-input:focus { outline: 2px solid #0f766e; outline-offset: -1px; border-color: #0f766e; }
    #bcChat-send {
        border: 0; border-radius: 10px; background: #0f766e; color: #fff;
        padding: 0 16px; font-weight: 600; cursor: pointer; font-size: 13.5px;
    }
    #bcChat-send:disabled { opacity: .55; cursor: default; }
    #bcChat-foot {
        display: flex; justify-content: space-between; gap: 8px;
        padding: 0 12px 10px; background: #fff; font-size: 11.5px;
    }
    #bcChat-foot button {
        background: none; border: 0; color: #0f766e; cursor: pointer;
        text-decoration: underline; font-size: 11.5px; padding: 0;
    }
    @media (max-width: 480px) {
        #bcChat-panel { right: 8px; left: 8px; bottom: 80px; width: auto; }
        #bcChat-launcher { right: 14px; bottom: 14px; }
    }
</style>

<button id="bcChat-launcher" type="button" aria-label="Open support chat" aria-expanded="false" aria-controls="bcChat-panel">
    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
         stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
        <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path>
    </svg>
    <span id="bcChat-badge" aria-hidden="true"></span>
</button>

<div id="bcChat-panel" role="dialog" aria-modal="false" aria-labelledby="bcChat-title" data-open="0">
    <div id="bcChat-head">
        <div>
            <h6 id="bcChat-title">Support</h6>
            <span id="bcChat-status">Usually replies in a few minutes</span>
        </div>
        <button id="bcChat-close" type="button" aria-label="Close chat">&times;</button>
    </div>
    <div id="bcChat-log" role="log" aria-live="polite" aria-relevant="additions"></div>
    <div id="bcChat-typing">Assistant is typing…</div>
    <form id="bcChat-form" autocomplete="off">
        <textarea id="bcChat-input" rows="1" maxlength="4000" placeholder="Type your message…" aria-label="Message"></textarea>
        <button id="bcChat-send" type="submit">Send</button>
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
    var statusEl = document.getElementById("bcChat-status");
    var typing   = document.getElementById("bcChat-typing");

    var threadId  = 0;
    var lastId    = 0;
    var opened    = false;
    var polling   = null;
    var seen      = {};   // message ids already painted

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
            who.textContent = m.who === "ai" ? "Assistant" : (m.who === "user" ? "You" : (m.name || "Agent"));
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
        threadId = t.id;
        statusEl.textContent = t.status === "with_agent" ? "A person is replying"
            : t.status === "pending_agent" ? "Connecting you to a person…"
            : t.ai_active ? "Assistant online" : "Usually replies in a few minutes";
    }

    function setTyping(on) {
        typing.style.display = on ? "block" : "none";
        if (on) log.scrollTop = log.scrollHeight;
    }

    function open() {
        panel.setAttribute("data-open", "1");
        launcher.setAttribute("aria-expanded", "true");
        badge.style.display = "none";
        input.focus();
        if (opened) return;
        opened = true;

        setTyping(true);
        post({ action: "open" }).then(function (res) {
            setTyping(false);
            if (!res.ok) { statusEl.textContent = res.error || "Chat unavailable"; return; }
            applyThread(res.thread);
            (res.messages || []).forEach(paint);
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
                // While the panel is shut, new answers surface as a badge instead of silently landing.
                if (fromAgent && panel.getAttribute("data-open") !== "1") badge.style.display = "block";
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
