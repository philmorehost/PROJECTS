<?php session_start();
include("../func/bc-admin-config.php");
require_once(__DIR__ . "/../func/bc-support-chat.php");

/**
 * DGV7 phase 2 — support chat console.
 *
 * The human half of the chat system. Agents see the tenant's queue, claim a conversation, reply,
 * close it, or pause the assistant on a thread it should not be answering.
 *
 * Tenant scope comes from the admin's own id: $get_logged_admin_details['id'] is the vendor_id in
 * this codebase (one admin account per tenant), and every query below is filtered by it.
 */

$vid = (int)$get_logged_admin_details['id'];
$agent_name = trim((string)($get_logged_admin_details['username'] ?? $get_logged_admin_details['email'] ?? 'agent'));

function bc_chat_redirect($msg, $type = 'info') {
    bc_chat_flash($msg, $type);
    header("Location: SupportChat.php");
    exit();
}

// ── Actions (POST only, with a redirect afterwards so a refresh cannot replay them) ──────────
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['action'])) {
    $action    = (string)$_POST['action'];
    $thread_id = (int)($_POST['thread_id'] ?? 0);
    $thread    = $thread_id > 0 ? bc_chat_thread_get($vid, $thread_id) : null;

    if (!$thread) bc_chat_redirect('That conversation was not found.', 'danger');

    if ($action === 'claim') {
        $ok = bc_chat_claim_thread($vid, $thread_id, $vid, $agent_name);
        bc_chat_redirect($ok ? 'You are now handling this conversation.' : 'Someone else already claimed it.',
            $ok ? 'success' : 'warning');
    }

    if ($action === 'reply') {
        $body = trim((string)($_POST['body'] ?? ''));
        if ($body === '') bc_chat_redirect('Write a reply first.', 'warning');
        $id = bc_chat_post_message($vid, $thread_id, 'agent', $agent_name, $body);
        bc_chat_redirect($id ? 'Reply sent.' : 'Reply was empty or too long.', $id ? 'success' : 'danger');
    }

    if ($action === 'close') {
        bc_chat_close_thread($vid, $thread_id, $agent_name);
        bc_chat_redirect('Conversation closed.', 'success');
    }

    if ($action === 'pause_ai') {
        // $connection_server is already in scope at file level; a `global` here would be redundant.
        mysqli_query($connection_server, "UPDATE sas_chat_threads SET ai_enabled=0
            WHERE id='$thread_id' AND vendor_id='$vid'");
        bc_chat_event($vid, $thread_id, 'ai_paused', $agent_name, '');
        bc_chat_redirect('The assistant is muted on this conversation.', 'success');
    }

    bc_chat_redirect('Unknown action.', 'danger');
}

$queue     = bc_chat_agent_queue($vid);
$flash     = bc_chat_flash();
$view_id   = (int)($_GET['thread'] ?? 0);
$view      = $view_id > 0 ? bc_chat_thread_get($vid, $view_id) : null;
$view_msgs = $view ? bc_chat_messages($vid, (int)$view['id'], 0, 300) : [];
?>
<?php include_once(__DIR__ . "/../func/bc-plate-admin.php");
bc_adm_head("Support Chat | " . ($get_all_super_admin_site_details["site_title"] ?? ''), array("area" => "admin"));
?>
<?php bc_adm_sidebar(array("area" => "admin")); ?>
<?php bc_adm_topbar(array("area" => "admin", "title" => "SUPPORT CHAT", "crumb" => "Home / Support Chat")); ?>

<section class="section dashboard">
    <?php if ($flash): ?>
        <div class="alert alert-<?php echo htmlspecialchars($flash['type']); ?> py-2 small" role="alert">
            <?php echo htmlspecialchars($flash['message']); ?>
        </div>
    <?php endif; ?>

    <div class="row g-3">
        <!-- Queue -->
        <div class="col-lg-4">
            <div class="card">
                <div class="card-body">
                    <h5 class="card-title">Waiting for a person (<span id="bcPendingCount"><?php echo count($queue['pending']); ?></span>)</h5>
                    <div id="bcPendingList">
                    <?php if (!$queue['pending']): ?>
                        <p class="text-muted small mb-0">Nothing is waiting. The assistant is handling new questions.</p>
                    <?php else: foreach ($queue['pending'] as $t): ?>
                        <a class="d-block text-decoration-none border rounded p-2 mb-2 <?php echo $view_id === (int)$t['id'] ? 'border-primary' : ''; ?>"
                           href="SupportChat.php?thread=<?php echo (int)$t['id']; ?>">
                            <div class="d-flex justify-content-between">
                                <strong class="small"><?php echo htmlspecialchars((string)$t['username']); ?></strong>
                                <?php if (!empty($t['ticket_no'])): ?><span class="badge bg-dark ms-1"><?php echo htmlspecialchars((string)$t['ticket_no']); ?></span><?php endif; ?>
                                <?php if ((int)$t['unread_for_agent'] > 0): ?>
                                    <span class="badge bg-danger"><?php echo (int)$t['unread_for_agent']; ?></span>
                                <?php endif; ?>
                            </div>
                            <div class="text-muted small"><?php echo htmlspecialchars((string)($t['last_message_at'] ?: 'Just now')); ?></div>
                        </a>
                    <?php endforeach; endif; ?>
                    </div>
                </div>
            </div>

            <div class="card mt-3">
                <div class="card-body">
                    <h5 class="card-title">In progress (<span id="bcActiveCount"><?php echo count($queue['active']); ?></span>)</h5>
                    <div id="bcActiveList">
                    <?php if (!$queue['active']): ?>
                        <p class="text-muted small mb-0">No conversations are open with an agent.</p>
                    <?php else: foreach ($queue['active'] as $t): ?>
                        <a class="d-block text-decoration-none border rounded p-2 mb-2 <?php echo $view_id === (int)$t['id'] ? 'border-primary' : ''; ?>"
                           href="SupportChat.php?thread=<?php echo (int)$t['id']; ?>">
                            <strong class="small d-block"><?php echo htmlspecialchars((string)$t['username']); ?></strong>
                            <?php if (!empty($t['ticket_no'])): ?><span class="badge bg-dark"><?php echo htmlspecialchars((string)$t['ticket_no']); ?></span><?php endif; ?>
                            <span class="text-muted small"><?php echo htmlspecialchars((string)($t['last_message_at'] ?: '')); ?></span>
                        </a>
                    <?php endforeach; endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Transcript -->
        <div class="col-lg-8">
            <div class="card">
                <div class="card-body">
                    <?php if (!$view): ?>
                        <p class="text-muted mb-0">Pick a conversation on the left to read it.</p>
                    <?php else: ?>
                        <div class="d-flex justify-content-between align-items-start mb-3">
                            <div>
                                <h5 class="card-title mb-1"><?php echo htmlspecialchars((string)$view['username']); ?></h5>
                                <?php if (!empty($view['ticket_no'])): ?><span class="badge bg-dark"><?php echo htmlspecialchars((string)$view['ticket_no']); ?></span><?php endif; ?>
                                <span class="badge bg-secondary"><?php echo htmlspecialchars((string)$view['status']); ?></span>
                                <?php if ((int)$view['ai_enabled'] === 1): ?>
                                    <span class="badge bg-info">assistant active</span>
                                <?php else: ?>
                                    <span class="badge bg-light text-dark">assistant muted</span>
                                <?php endif; ?>
                            </div>
                            <div class="d-flex gap-2 flex-wrap justify-content-end">
                                <?php if ((string)$view['assigned_admin'] === ''): ?>
                                    <form method="post" class="d-inline">
                                        <input type="hidden" name="thread_id" value="<?php echo (int)$view['id']; ?>">
                                        <input type="hidden" name="action" value="claim">
                                        <button class="btn btn-sm btn-primary" type="submit">Claim</button>
                                    </form>
                                <?php endif; ?>
                                <?php if ((int)$view['ai_enabled'] === 1): ?>
                                    <form method="post" class="d-inline">
                                        <input type="hidden" name="thread_id" value="<?php echo (int)$view['id']; ?>">
                                        <input type="hidden" name="action" value="pause_ai">
                                        <button class="btn btn-sm btn-outline-secondary" type="submit">Pause assistant</button>
                                    </form>
                                <?php endif; ?>
                                <form method="post" class="d-inline"
                                      onsubmit="return confirm('Close this conversation?');">
                                    <input type="hidden" name="thread_id" value="<?php echo (int)$view['id']; ?>">
                                    <input type="hidden" name="action" value="close">
                                    <button class="btn btn-sm btn-outline-danger" type="submit">Close</button>
                                </form>
                            </div>
                        </div>

                        <div class="border rounded p-3 mb-3" id="bcLiveMsgs" data-thread="<?php echo $view ? (int)$view['id'] : 0; ?>" data-last="<?php echo $view_msgs ? (int)end($view_msgs)['id'] : 0; ?>" style="max-height:420px; overflow-y:auto; background:#f8f9fa;">
                            <?php if (!$view_msgs): ?>
                                <p class="text-muted small mb-0">No messages yet.</p>
                            <?php else: foreach ($view_msgs as $m): ?>
                                <div class="mb-2">
                                    <div class="small text-muted">
                                        <strong><?php echo htmlspecialchars((string)($m['author_name'] ?: $m['author_type'])); ?></strong>
                                        <span class="ms-1"><?php echo htmlspecialchars((string)$m['created_at']); ?></span>
                                    </div>
                                    <div class="small"><?php echo htmlspecialchars((string)$m['body']); ?></div>
                                </div>
                            <?php endforeach; endif; ?>
                        </div>

                        <?php if ((string)$view['status'] !== 'closed'): ?>
                            <form method="post" id="bcReplyForm">
                                <input type="hidden" name="thread_id" value="<?php echo (int)$view['id']; ?>">
                                <input type="hidden" name="action" value="reply">
                                <label class="form-label small" for="bc-agent-reply">Reply as <?php echo htmlspecialchars($agent_name); ?></label>
                                <textarea class="form-control mb-2" id="bc-agent-reply" name="body" rows="3"
                                          maxlength="4000" required></textarea>
                                <button class="btn btn-sm btn-primary" type="submit">Send reply</button>
                            </form>
                        <?php else: ?>
                            <p class="text-muted small mb-0">This conversation is closed.</p>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</section>

    




<script>
// §41 live agent chat: poll the queue + open thread, send replies without a
// page reload. The classic POST forms above remain as the no-JS fallback.
(function () {
    var pane = document.getElementById('bcLiveMsgs');
    var threadId = pane ? parseInt(pane.getAttribute('data-thread') || '0', 10) : 0;
    var lastId = pane ? parseInt(pane.getAttribute('data-last') || '0', 10) : 0;
    var pendingList = document.getElementById('bcPendingList');
    var activeList = document.getElementById('bcActiveList');

    function esc(t) { var d = document.createElement('div'); d.textContent = t == null ? '' : String(t); return d.innerHTML; }
    function post(payload) {
        return fetch('ajax-support-chat.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        }).then(function (r) { return r.json(); });
    }
    function paintMessage(m) {
        var wrap = document.createElement('div');
        wrap.className = 'mb-2';
        wrap.setAttribute('data-mid', m.id);
        var meta = document.createElement('div');
        meta.className = 'small text-muted';
        meta.innerHTML = '<strong></strong><span class="ms-1"></span>';
        meta.querySelector('strong').textContent = m.author_name || m.author_type;
        meta.querySelector('span').textContent = m.created_at || '';
        var body = document.createElement('div');
        body.className = 'small';
        body.textContent = m.body;
        wrap.appendChild(meta); wrap.appendChild(body);
        return wrap;
    }
    function nearBottom() {
        return pane.scrollHeight - pane.scrollTop - pane.clientHeight < 120;
    }
    function appendMessages(messages, stick) {
        if (!messages || !messages.length) return;
        var wasNear = nearBottom();
        messages.forEach(function (m) {
            if (pane.querySelector('[data-mid="' + m.id + '"]')) { lastId = Math.max(lastId, m.id); return; }
            pane.appendChild(paintMessage(m));
            lastId = Math.max(lastId, m.id);
        });
        if (stick || wasNear) pane.scrollTop = pane.scrollHeight;
    }
    function paintQueue(el, rows, emptyText) {
        if (!el) return;
        el.innerHTML = '';
        if (!rows.length) {
            var p = document.createElement('p');
            p.className = 'text-muted small mb-0';
            p.textContent = emptyText;
            el.appendChild(p);
            return;
        }
        rows.forEach(function (t) {
            var a = document.createElement('a');
            a.className = 'd-block text-decoration-none border rounded p-2 mb-2' + (t.id === threadId ? ' border-primary' : '');
            a.href = 'SupportChat.php?thread=' + t.id;
            var top = document.createElement('div');
            top.className = 'd-flex justify-content-between';
            var name = document.createElement('strong');
            name.className = 'small';
            name.textContent = t.username || 'Customer';
            top.appendChild(name);
            if (t.ticket_no) {
                var b = document.createElement('span');
                b.className = 'badge bg-dark ms-1';
                b.textContent = t.ticket_no;
                top.appendChild(b);
            }
            if (t.unread_for_agent > 0) {
                var u = document.createElement('span');
                u.className = 'badge bg-danger';
                u.textContent = t.unread_for_agent;
                top.appendChild(u);
            }
            var when = document.createElement('div');
            when.className = 'text-muted small';
            when.textContent = t.last_message_at || '';
            a.appendChild(top); a.appendChild(when);
            el.appendChild(a);
        });
    }
    function poll() {
        post({ action: 'queue' }).then(function (res) {
            if (!res || !res.ok) return;
            var pc = document.getElementById('bcPendingCount');
            var ac = document.getElementById('bcActiveCount');
            if (pc) pc.textContent = res.pending.length;
            if (ac) ac.textContent = res.active.length;
            paintQueue(pendingList, res.pending, 'Nothing is waiting. The assistant is handling new questions.');
            paintQueue(activeList, res.active, 'No conversations are open with an agent.');
        }).catch(function () {});
        if (threadId > 0) {
            post({ action: 'thread', thread_id: threadId, after_id: lastId }).then(function (res) {
                if (res && res.ok) appendMessages(res.messages, false);
            }).catch(function () {});
        }
    }

    var form = document.getElementById('bcReplyForm');
    if (form && threadId > 0) {
        form.addEventListener('submit', function (ev) {
            ev.preventDefault();
            var box = document.getElementById('bc-agent-reply');
            var body = box.value.trim();
            if (!body) return;
            post({ action: 'reply', thread_id: threadId, body: body }).then(function (res) {
                if (res && res.ok) {
                    box.value = '';
                    appendMessages(res.messages, true);
                    poll();
                } else {
                    form.submit();
                }
            }).catch(function () { form.submit(); });
        });
    }

    if (pane) pane.scrollTop = pane.scrollHeight;
    setInterval(poll, 4000);
    poll();
})();
</script>

<?php bc_adm_foot(array("area" => "admin")); ?>
