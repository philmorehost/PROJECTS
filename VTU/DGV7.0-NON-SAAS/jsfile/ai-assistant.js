/**
 * ai-assistant.js — bc-admin Support Chat Quick-Access Widget.
 *
 * Replaces the retired VTU-purchase AI widget. Gives signed-in bc-admin agents
 * floating quick access from any back-office page to view customer support
 * tickets, claim conversations, mute the AI assistant, close tickets, and
 * reply in real time via bc-admin/ajax-support-chat.php.
 */
(function () {
    "use strict";

    var cfg = window.__bc_admin_chat || null;
    if (!cfg || !cfg.endpoint) return;
    if (document.getElementById("bcAdmChat-launcher")) return;

    var ENDPOINT     = cfg.endpoint;
    var CONSOLE_URL  = cfg.consoleUrl || "/bc-admin/SupportChat.php";
    var SETTINGS_URL = cfg.settingsUrl || "/bc-admin/ChatSettings.php";
    var ADMIN_NAME   = cfg.adminName || "Support Agent";

    var state = {
        open: false,
        view: "queue", // "queue" | "thread"
        threadId: 0,
        thread: null,
        lastId: 0,
        seen: {},
        queue: { pending: [], active: [], open_threads: [] },
        pollInFlight: false,
        queueInFlight: false,
        sending: false
    };

    var style = document.createElement("style");
    style.textContent =
        "#bcAdmChat-launcher{position:fixed;right:20px;bottom:20px;z-index:2147482000;height:56px;min-width:56px;padding:0 16px;border:0;border-radius:18px;display:flex;align-items:center;gap:9px;background:linear-gradient(135deg,var(--brand,#0f766e),var(--brand-dark,#115e59));color:var(--brand-fg,#fff);font:700 13.5px/1 Inter,system-ui,-apple-system,sans-serif;cursor:pointer;box-shadow:0 16px 34px -10px rgba(15,23,42,.5);transition:transform .18s ease,box-shadow .18s ease;}" +
        "#bcAdmChat-launcher:hover{transform:translateY(-2px);box-shadow:0 22px 40px -12px rgba(15,23,42,.58);}" +
        "#bcAdmChat-launcher:focus-visible{outline:3px solid var(--brand,#0f766e);outline-offset:3px;}" +
        "#bcAdmChat-badge{display:none;min-width:21px;height:21px;padding:0 6px;border-radius:999px;background:#dc2626;color:#fff;font:800 11px/21px Inter,system-ui,sans-serif;text-align:center;border:2px solid #fff;}" +
        "#bcAdmChat-panel{position:fixed;right:20px;bottom:88px;z-index:2147482000;width:410px;max-width:calc(100vw - 24px);height:580px;max-height:calc(100dvh - 110px);display:none;flex-direction:column;background:#fff;border-radius:20px;overflow:hidden;border:1px solid rgba(15,23,42,.1);box-shadow:0 30px 64px -16px rgba(2,6,23,.52);font-family:Inter,system-ui,-apple-system,sans-serif;color:#0f172a;}" +
        "#bcAdmChat-panel[data-open='1']{display:flex;}" +
        "#bcAdmChat-head{background:linear-gradient(125deg,var(--brand,#0f766e),var(--brand-dark,#115e59));color:var(--brand-fg,#fff);padding:14px 16px;display:flex;align-items:center;gap:10px;}" +
        "#bcAdmChat-headTitle{margin:0;font-size:15px;font-weight:800;letter-spacing:-.01em;flex:1;min-width:0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}" +
        "#bcAdmChat-headSub{display:block;font-size:11.5px;opacity:.86;font-weight:500;margin-top:1px;}" +
        ".bcAdmChat-hbtn{background:rgba(255,255,255,.16);border:0;color:inherit;border-radius:10px;padding:6px 10px;font-size:12px;font-weight:700;cursor:pointer;text-decoration:none;display:inline-flex;align-items:center;gap:5px;white-space:nowrap;}" +
        ".bcAdmChat-hbtn:hover{background:rgba(255,255,255,.28);color:inherit;}" +
        "#bcAdmChat-body{flex:1 1 auto;overflow-y:auto;background:#f8fafc;display:flex;flex-direction:column;}" +
        ".bcAdmChat-sec{padding:12px 14px 4px;}" +
        ".bcAdmChat-secLbl{font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.07em;color:#64748b;margin:0 0 8px;display:flex;justify-content:space-between;align-items:center;}" +
        ".bcAdmChat-item{width:100%;text-align:left;background:#fff;border:1px solid #e2e8f0;border-radius:14px;padding:11px 12px;margin-bottom:8px;cursor:pointer;display:block;transition:border-color .15s ease,box-shadow .15s ease;}" +
        ".bcAdmChat-item:hover{border-color:var(--brand,#0f766e);box-shadow:0 6px 16px -10px rgba(15,23,42,.35);}" +
        ".bcAdmChat-itemTop{display:flex;align-items:center;gap:6px;margin-bottom:4px;}" +
        ".bcAdmChat-itemName{font-size:13.5px;font-weight:700;color:#0f172a;margin-right:auto;}" +
        ".bcAdmChat-pill{font-size:10.5px;font-weight:700;padding:2px 7px;border-radius:999px;background:#0f172a;color:#fff;}" +
        ".bcAdmChat-pill--warn{background:#dc2626;}" +
        ".bcAdmChat-pill--soft{background:#e2e8f0;color:#334155;}" +
        ".bcAdmChat-pill--info{background:#0284c7;color:#fff;}" +
        ".bcAdmChat-itemMeta{font-size:11.5px;color:#64748b;}" +
        ".bcAdmChat-empty{font-size:12.5px;color:#64748b;background:#fff;border:1px dashed #cbd5e1;border-radius:12px;padding:12px;text-align:center;margin-bottom:8px;}" +
        "#bcAdmChat-bar{padding:10px 14px;background:#fff;border-bottom:1px solid #e2e8f0;display:flex;align-items:center;gap:6px;flex-wrap:wrap;}" +
        ".bcAdmChat-act{border:1px solid #cbd5e1;background:#fff;color:#0f172a;border-radius:8px;padding:4px 9px;font-size:11.5px;font-weight:700;cursor:pointer;}" +
        ".bcAdmChat-act:hover{background:#f1f5f9;}" +
        ".bcAdmChat-act--pri{background:var(--brand,#0f766e);border-color:transparent;color:var(--brand-fg,#fff);}" +
        ".bcAdmChat-act--pri:hover{opacity:.92;background:var(--brand,#0f766e);}" +
        ".bcAdmChat-act--danger{color:#b91c1c;border-color:#fecaca;}" +
        ".bcAdmChat-act--danger:hover{background:#fef2f2;}" +
        "#bcAdmChat-msgs{flex:1 1 auto;overflow-y:auto;padding:14px;display:flex;flex-direction:column;gap:10px;background:#f8fafc;}" +
        ".bcAdmChat-msg{max-width:86%;display:flex;flex-direction:column;}" +
        ".bcAdmChat-msgWho{font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.05em;color:#64748b;margin-bottom:3px;}" +
        ".bcAdmChat-msgBody{padding:9px 12px;border-radius:14px;font-size:13.5px;line-height:1.45;white-space:pre-wrap;word-break:break-word;}" +
        ".bcAdmChat-msg--agent{align-self:flex-end;align-items:flex-end;}" +
        ".bcAdmChat-msg--agent .bcAdmChat-msgBody{background:var(--brand,#0f766e);color:var(--brand-fg,#fff);border-bottom-right-radius:4px;}" +
        ".bcAdmChat-msg--user,.bcAdmChat-msg--ai{align-self:flex-start;align-items:flex-start;}" +
        ".bcAdmChat-msg--user .bcAdmChat-msgBody{background:#fff;border:1px solid #e2e8f0;color:#0f172a;border-bottom-left-radius:4px;}" +
        ".bcAdmChat-msg--ai .bcAdmChat-msgBody{background:#fef3c7;border:1px solid #fde68a;color:#78350f;border-bottom-left-radius:4px;}" +
        ".bcAdmChat-msg--system{align-self:center;max-width:94%;}" +
        ".bcAdmChat-msg--system .bcAdmChat-msgBody{background:#e2e8f0;color:#475569;font-size:11.5px;border-radius:10px;text-align:center;}" +
        "#bcAdmChat-form{display:none;gap:8px;padding:10px 12px;background:#fff;border-top:1px solid #e2e8f0;align-items:flex-end;}" +
        "#bcAdmChat-input{flex:1;resize:none;border:1.5px solid #cbd5e1;border-radius:12px;padding:9px 11px;font:inherit;font-size:13.5px;max-height:100px;background:#f8fafc;}" +
        "#bcAdmChat-input:focus{outline:none;border-color:var(--brand,#0f766e);background:#fff;}" +
        "#bcAdmChat-send{border:0;border-radius:12px;background:var(--brand,#0f766e);color:var(--brand-fg,#fff);padding:0 14px;height:40px;font-size:13px;font-weight:700;cursor:pointer;}" +
        "#bcAdmChat-send:disabled{opacity:.5;cursor:default;}" +
        "#bcAdmChat-err{display:none;padding:8px 14px;background:#fef2f2;color:#b91c1c;font-size:12px;border-top:1px solid #fecaca;}" +
        "@media (max-width:576px){#bcAdmChat-panel{right:8px;left:8px;bottom:82px;width:auto;height:calc(100dvh - 98px);}#bcAdmChat-launcher{right:14px;bottom:14px;}}";
    document.head.appendChild(style);

    var launcher = document.createElement("button");
    launcher.id = "bcAdmChat-launcher";
    launcher.type = "button";
    launcher.setAttribute("aria-label", "Open Support Chat Queue");
    launcher.setAttribute("aria-expanded", "false");
    launcher.setAttribute("aria-controls", "bcAdmChat-panel");
    launcher.innerHTML =
        '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' +
        '<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path></svg>' +
        '<span>Support</span><span id="bcAdmChat-badge" aria-hidden="true">0</span>';

    var panel = document.createElement("div");
    panel.id = "bcAdmChat-panel";
    panel.setAttribute("role", "dialog");
    panel.setAttribute("aria-label", "Support Chat Quick Access");
    panel.setAttribute("data-open", "0");

    panel.innerHTML =
        '<div id="bcAdmChat-head">' +
            '<button id="bcAdmChat-back" class="bcAdmChat-hbtn" type="button" style="display:none;">&larr; Queue</button>' +
            '<div style="flex:1;min-width:0;">' +
                '<div id="bcAdmChat-headTitle">Support Queue</div>' +
                '<span id="bcAdmChat-headSub">Quick access to customer support chats</span>' +
            '</div>' +
            '<a id="bcAdmChat-consoleLink" class="bcAdmChat-hbtn" title="Open Full Support Console">Console</a>' +
            '<button id="bcAdmChat-close" class="bcAdmChat-hbtn" type="button" aria-label="Close">&times;</button>' +
        '</div>' +
        '<div id="bcAdmChat-bar" style="display:none;"></div>' +
        '<div id="bcAdmChat-body">' +
            '<div id="bcAdmChat-queueView"></div>' +
            '<div id="bcAdmChat-msgs" style="display:none;"></div>' +
        '</div>' +
        '<div id="bcAdmChat-err" role="alert"></div>' +
        '<form id="bcAdmChat-form" autocomplete="off">' +
            '<textarea id="bcAdmChat-input" rows="1" maxlength="4000" placeholder="Reply to customer..."></textarea>' +
            '<button id="bcAdmChat-send" type="submit">Send</button>' +
        '</form>';

    document.body.appendChild(launcher);
    document.body.appendChild(panel);

    var badgeEl     = document.getElementById("bcAdmChat-badge");
    var backBtn     = document.getElementById("bcAdmChat-back");
    var titleEl     = document.getElementById("bcAdmChat-headTitle");
    var subEl       = document.getElementById("bcAdmChat-headSub");
    var consoleLink = document.getElementById("bcAdmChat-consoleLink");
    var closeBtn    = document.getElementById("bcAdmChat-close");
    var barEl       = document.getElementById("bcAdmChat-bar");
    var queueView   = document.getElementById("bcAdmChat-queueView");
    var msgsEl      = document.getElementById("bcAdmChat-msgs");
    var errEl       = document.getElementById("bcAdmChat-err");
    var formEl      = document.getElementById("bcAdmChat-form");
    var inputEl     = document.getElementById("bcAdmChat-input");
    var sendBtn     = document.getElementById("bcAdmChat-send");

    consoleLink.href = CONSOLE_URL;

    function showError(msg) {
        if (!msg) { errEl.style.display = "none"; errEl.textContent = ""; return; }
        errEl.textContent = String(msg);
        errEl.style.display = "block";
    }

    function post(payload) {
        return fetch(ENDPOINT, {
            method: "POST",
            credentials: "same-origin",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify(payload)
        }).then(function (r) { return r.json(); });
    }

    function updateBadge() {
        var pendingCount = state.queue.pending.length;
        var unreadTotal = 0;
        state.queue.pending.concat(state.queue.active, state.queue.open_threads).forEach(function (t) {
            unreadTotal += Number(t.unread_for_agent || 0);
        });
        var count = Math.max(pendingCount, unreadTotal);
        badgeEl.textContent = count > 99 ? "99+" : String(count);
        badgeEl.style.display = count > 0 ? "inline-block" : "none";
    }

    function renderQueueGroup(label, rows, emptyText, tagStyle) {
        var sec = document.createElement("div");
        sec.className = "bcAdmChat-sec";

        var head = document.createElement("div");
        head.className = "bcAdmChat-secLbl";
        var lSpan = document.createElement("span");
        lSpan.textContent = label;
        var cSpan = document.createElement("span");
        cSpan.textContent = String(rows.length);
        head.appendChild(lSpan);
        head.appendChild(cSpan);
        sec.appendChild(head);

        if (!rows.length) {
            var empty = document.createElement("div");
            empty.className = "bcAdmChat-empty";
            empty.textContent = emptyText;
            sec.appendChild(empty);
            return sec;
        }

        rows.forEach(function (t) {
            var btn = document.createElement("button");
            btn.type = "button";
            btn.className = "bcAdmChat-item";

            var top = document.createElement("div");
            top.className = "bcAdmChat-itemTop";

            var name = document.createElement("span");
            name.className = "bcAdmChat-itemName";
            name.textContent = t.username || "Customer";
            top.appendChild(name);

            if (t.ticket_no) {
                var tk = document.createElement("span");
                tk.className = "bcAdmChat-pill " + (tagStyle || "");
                tk.textContent = t.ticket_no;
                top.appendChild(tk);
            }

            if (Number(t.unread_for_agent || 0) > 0) {
                var u = document.createElement("span");
                u.className = "bcAdmChat-pill bcAdmChat-pill--warn";
                u.textContent = String(t.unread_for_agent) + " new";
                top.appendChild(u);
            }

            var meta = document.createElement("div");
            meta.className = "bcAdmChat-itemMeta";
            var statusTxt = t.status === "pending_agent" ? "Waiting for person"
                : t.status === "with_agent" ? "In progress"
                : t.ai_enabled ? "AI handling" : "Open";
            meta.textContent = statusTxt + (t.last_message_at ? " · " + t.last_message_at : "");

            btn.appendChild(top);
            btn.appendChild(meta);
            btn.addEventListener("click", function () { openThread(t.id); });
            sec.appendChild(btn);
        });

        return sec;
    }

    function renderQueue() {
        queueView.innerHTML = "";
        queueView.appendChild(renderQueueGroup(
            "Waiting for a person",
            state.queue.pending,
            "No customers are waiting for an agent.",
            ""
        ));
        queueView.appendChild(renderQueueGroup(
            "In progress",
            state.queue.active,
            "No active agent conversations.",
            "bcAdmChat-pill--info"
        ));
        if (state.queue.open_threads.length) {
            queueView.appendChild(renderQueueGroup(
                "Active AI / Open chats",
                state.queue.open_threads,
                "",
                "bcAdmChat-pill--soft"
            ));
        }

        var footerWrap = document.createElement("div");
        footerWrap.style.cssText = "padding:8px 14px 14px;display:flex;justify-content:space-between;align-items:center;font-size:12px;";
        var linkConsole = document.createElement("a");
        linkConsole.href = CONSOLE_URL;
        linkConsole.textContent = "Open full Support Chat";
        var linkSettings = document.createElement("a");
        linkSettings.href = SETTINGS_URL;
        linkSettings.textContent = "Chat Settings";
        footerWrap.appendChild(linkConsole);
        footerWrap.appendChild(linkSettings);
        queueView.appendChild(footerWrap);
    }

    function showQueueView() {
        state.view = "queue";
        state.threadId = 0;
        state.thread = null;
        state.lastId = 0;
        state.seen = {};
        showError("");
        backBtn.style.display = "none";
        barEl.style.display = "none";
        msgsEl.style.display = "none";
        formEl.style.display = "none";
        queueView.style.display = "block";
        titleEl.textContent = "Support Queue";
        subEl.textContent = "Quick access to customer support chats";
        consoleLink.href = CONSOLE_URL;
        renderQueue();
        fetchQueue();
    }

    function paintMessage(m) {
        if (state.seen[m.id]) return;
        state.seen[m.id] = true;
        if (m.id > state.lastId) state.lastId = m.id;

        var wrap = document.createElement("div");
        var kind = m.author_type || "system";
        wrap.className = "bcAdmChat-msg bcAdmChat-msg--" + kind;

        if (kind !== "system") {
            var who = document.createElement("div");
            who.className = "bcAdmChat-msgWho";
            who.textContent = (m.author_name || kind) + (m.created_at ? " · " + m.created_at : "");
            wrap.appendChild(who);
        }

        var body = document.createElement("div");
        body.className = "bcAdmChat-msgBody";
        body.appendChild(document.createTextNode(m.body || ""));
        wrap.appendChild(body);
        msgsEl.appendChild(wrap);
    }

    function renderThreadBar() {
        var t = state.thread;
        barEl.innerHTML = "";
        if (!t) return;

        var statusBadge = document.createElement("span");
        statusBadge.className = "bcAdmChat-pill bcAdmChat-pill--soft";
        statusBadge.textContent = t.status || "open";
        barEl.appendChild(statusBadge);

        var aiBadge = document.createElement("span");
        aiBadge.className = "bcAdmChat-pill " + (Number(t.ai_enabled) === 1 ? "bcAdmChat-pill--info" : "bcAdmChat-pill--soft");
        aiBadge.textContent = Number(t.ai_enabled) === 1 ? "AI active" : "AI muted";
        barEl.appendChild(aiBadge);

        var spacer = document.createElement("span");
        spacer.style.marginLeft = "auto";
        barEl.appendChild(spacer);

        if (!t.assigned_admin && t.status !== "closed") {
            var claimBtn = document.createElement("button");
            claimBtn.type = "button";
            claimBtn.className = "bcAdmChat-act bcAdmChat-act--pri";
            claimBtn.textContent = "Claim";
            claimBtn.addEventListener("click", function () { threadAction("claim"); });
            barEl.appendChild(claimBtn);
        }
        if (Number(t.ai_enabled) === 1 && t.status !== "closed") {
            var pauseBtn = document.createElement("button");
            pauseBtn.type = "button";
            pauseBtn.className = "bcAdmChat-act";
            pauseBtn.textContent = "Mute AI";
            pauseBtn.addEventListener("click", function () { threadAction("pause_ai"); });
            barEl.appendChild(pauseBtn);
        }
        if (t.status !== "closed") {
            var closeThreadBtn = document.createElement("button");
            closeThreadBtn.type = "button";
            closeThreadBtn.className = "bcAdmChat-act bcAdmChat-act--danger";
            closeThreadBtn.textContent = "Close";
            closeThreadBtn.addEventListener("click", function () {
                if (window.confirm("Close this conversation?")) threadAction("close");
            });
            barEl.appendChild(closeThreadBtn);
        }

        formEl.style.display = t.status === "closed" ? "none" : "flex";
    }

    function threadAction(act) {
        if (!state.threadId) return;
        showError("");
        post({ action: act, thread_id: state.threadId }).then(function (res) {
            if (!res || !res.ok) { showError((res && res.error) || "Action failed."); return; }
            if (res.thread) state.thread = res.thread;
            renderThreadBar();
            fetchThread(true);
            fetchQueue();
        }).catch(function () { showError("Network error while updating ticket."); });
    }

    function openThread(tid) {
        state.view = "thread";
        state.threadId = Number(tid);
        state.lastId = 0;
        state.seen = {};
        msgsEl.innerHTML = "";
        showError("");

        backBtn.style.display = "inline-flex";
        queueView.style.display = "none";
        barEl.style.display = "flex";
        msgsEl.style.display = "flex";
        formEl.style.display = "flex";
        titleEl.textContent = "Loading...";
        subEl.textContent = "Reply as " + ADMIN_NAME;
        consoleLink.href = CONSOLE_URL + "?thread=" + encodeURIComponent(state.threadId);

        fetchThread(true);
    }

    function fetchThread(scrollToEnd) {
        if (!state.threadId || state.pollInFlight) return;
        state.pollInFlight = true;
        post({ action: "thread", thread_id: state.threadId, after_id: state.lastId })
            .then(function (res) {
                if (!res || !res.ok) { showError((res && res.error) || "Could not load conversation."); return; }
                if (res.thread) {
                    state.thread = res.thread;
                    titleEl.textContent = (res.thread.username || "Customer") + (res.thread.ticket_no ? " · " + res.thread.ticket_no : "");
                    renderThreadBar();
                }
                var wasNearBottom = msgsEl.scrollHeight - msgsEl.scrollTop - msgsEl.clientHeight < 100;
                (res.messages || []).forEach(paintMessage);
                if (scrollToEnd || wasNearBottom) msgsEl.scrollTop = msgsEl.scrollHeight;
            })
            .catch(function () { /* keep quiet on transient poll failure */ })
            .then(function () { state.pollInFlight = false; });
    }

    function fetchQueue() {
        if (state.queueInFlight) return;
        state.queueInFlight = true;
        post({ action: "queue" })
            .then(function (res) {
                if (!res || !res.ok) return;
                state.queue = {
                    pending: Array.isArray(res.pending) ? res.pending : [],
                    active: Array.isArray(res.active) ? res.active : [],
                    open_threads: Array.isArray(res.open_threads) ? res.open_threads : []
                };
                updateBadge();
                if (state.open && state.view === "queue") renderQueue();
            })
            .catch(function () {})
            .then(function () { state.queueInFlight = false; });
    }

    function toggleOpen(force) {
        var next = typeof force === "boolean" ? force : !state.open;
        state.open = next;
        panel.setAttribute("data-open", next ? "1" : "0");
        launcher.setAttribute("aria-expanded", next ? "true" : "false");
        if (next) {
            if (state.view === "thread" && state.threadId) fetchThread(true);
            else showQueueView();
        }
    }

    launcher.addEventListener("click", function () { toggleOpen(); });
    closeBtn.addEventListener("click", function () { toggleOpen(false); });
    backBtn.addEventListener("click", showQueueView);

    formEl.addEventListener("submit", function (ev) {
        ev.preventDefault();
        if (!state.threadId || state.sending) return;
        var text = inputEl.value.trim();
        if (!text) return;

        showError("");
        state.sending = true;
        sendBtn.disabled = true;

        post({ action: "reply", thread_id: state.threadId, body: text })
            .then(function (res) {
                if (!res || !res.ok) {
                    showError((res && res.error) || "Could not send reply.");
                    return;
                }
                inputEl.value = "";
                inputEl.style.height = "auto";
                if (res.thread) {
                    state.thread = res.thread;
                    renderThreadBar();
                }
                (res.messages || []).forEach(paintMessage);
                msgsEl.scrollTop = msgsEl.scrollHeight;
                fetchQueue();
            })
            .catch(function () { showError("Network error while sending reply."); })
            .then(function () {
                state.sending = false;
                sendBtn.disabled = false;
                inputEl.focus();
            });
    });

    inputEl.addEventListener("keydown", function (ev) {
        if (ev.key === "Enter" && !ev.shiftKey) {
            ev.preventDefault();
            formEl.requestSubmit();
        }
    });
    inputEl.addEventListener("input", function () {
        inputEl.style.height = "auto";
        inputEl.style.height = Math.min(inputEl.scrollHeight, 100) + "px";
    });

    fetchQueue();
    setInterval(function () {
        if (document.hidden) return;
        if (state.open && state.view === "thread" && state.threadId) {
            fetchThread(false);
        }
        fetchQueue();
    }, 4000);
})();
