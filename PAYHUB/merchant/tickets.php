<?php
// Merchant support center.
require_once '../includes/functions.php';

if (!isLoggedIn()) redirect('../login.php');

$user = getAuthUser();
$db = Database::connect();
$pageTitle = 'Support Center - PayHub';
$success_msg = '';
$error_msg = '';
$allowedPriorities = ['low', 'medium', 'high', 'critical'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $error_msg = 'Your security token expired. Refresh the page and try again.';
    } elseif ($_POST['action'] === 'new_ticket') {
        $subject = trim((string)($_POST['subject'] ?? ''));
        $priority = (string)($_POST['priority'] ?? 'medium');
        $message = trim((string)($_POST['message'] ?? ''));
        if (function_exists('mb_substr')) {
            $subject = mb_substr($subject, 0, 255);
            $message = mb_substr($message, 0, 10000);
        } else {
            $subject = substr($subject, 0, 255);
            $message = substr($message, 0, 10000);
        }
        if ($subject === '' || $message === '' || !in_array($priority, $allowedPriorities, true)) {
            $error_msg = 'Add a subject and message, then select a valid priority.';
        } else {
            $db->beginTransaction();
            try {
                $stmt = $db->prepare("INSERT INTO tickets (user_id, subject, priority, status) VALUES (?, ?, ?, 'open')");
                $stmt->execute([$user['id'], $subject, $priority]);
                $ticketId = (int)$db->lastInsertId();
                $stmt = $db->prepare('INSERT INTO ticket_messages (ticket_id, user_id, message, is_admin) VALUES (?, ?, ?, 0)');
                $stmt->execute([$ticketId, $user['id'], $message]);
                $db->commit();
                $success_msg = 'Your request was sent. Support will reply in this ticket thread.';
                if (($user['role'] ?? '') !== 'admin') {
                    notifyAdmins(
                        'New Support Ticket: ' . $subject,
                        '<p><strong>Merchant:</strong> ' . htmlspecialchars($user['business_name'] ?? $user['email'], ENT_QUOTES, 'UTF-8') . '<br>' .
                        '<strong>Priority:</strong> ' . htmlspecialchars($priority, ENT_QUOTES, 'UTF-8') . '<br>' .
                        '<strong>Subject:</strong> ' . htmlspecialchars($subject, ENT_QUOTES, 'UTF-8') . '<br>' .
                        '<strong>Message:</strong><br>' . nl2br(htmlspecialchars($message, ENT_QUOTES, 'UTF-8')) . '</p>'
                    );
                }
            } catch (Throwable $e) {
                if ($db->inTransaction()) $db->rollBack();
                error_log('[PayHub tickets] Merchant could not create ticket: ' . $e->getMessage());
                $error_msg = 'Could not create your ticket. Please try again.';
            }
        }
    } elseif ($_POST['action'] === 'new_ticket_message') {
        $ticketId = filter_var($_POST['ticket_id'] ?? null, FILTER_VALIDATE_INT);
        $message = trim((string)($_POST['message'] ?? ''));
        if (function_exists('mb_substr')) $message = mb_substr($message, 0, 10000);
        else $message = substr($message, 0, 10000);
        if (!$ticketId || $ticketId < 1 || $message === '') {
            $error_msg = 'Ticket or message is invalid.';
        } else {
            $stmt = $db->prepare("SELECT id, subject, status FROM tickets WHERE id = ? AND user_id = ?");
            $stmt->execute([$ticketId, $user['id']]);
            $ticket = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$ticket) {
                $error_msg = 'Ticket not found.';
            } elseif ($ticket['status'] === 'closed') {
                $error_msg = 'This ticket is closed. Open a new ticket if you still need help.';
            } else {
                $stmt = $db->prepare('INSERT INTO ticket_messages (ticket_id, user_id, message, is_admin) VALUES (?, ?, ?, 0)');
                if ($stmt->execute([$ticketId, $user['id'], $message])) {
                    $success_msg = 'Your reply was added to the ticket.';
                    notifyAdmins(
                        'New Reply on Ticket #' . (int)$ticketId,
                        '<p><strong>Merchant:</strong> ' . htmlspecialchars($user['business_name'] ?? $user['email'], ENT_QUOTES, 'UTF-8') . '<br>' .
                        '<strong>Ticket:</strong> ' . htmlspecialchars($ticket['subject'], ENT_QUOTES, 'UTF-8') . '<br>' .
                        '<strong>Message:</strong><br>' . nl2br(htmlspecialchars($message, ENT_QUOTES, 'UTF-8')) . '</p>'
                    );
                } else {
                    $error_msg = 'Could not send your reply. Please try again.';
                }
            }
        }
    }
}

// Ticket owners can only read their own conversation. Never trust a ticket id from the browser.
if (($_GET['action'] ?? '') === 'get_messages') {
    header('Content-Type: application/json; charset=utf-8');
    $ticketId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
    if (!$ticketId || $ticketId < 1) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid ticket id.']);
        exit;
    }
    $owner = $db->prepare('SELECT id FROM tickets WHERE id = ? AND user_id = ?');
    $owner->execute([$ticketId, $user['id']]);
    if (!$owner->fetchColumn()) {
        http_response_code(404);
        echo json_encode(['error' => 'Ticket not found.']);
        exit;
    }
    $stmt = $db->prepare('SELECT id, ticket_id, user_id, message, is_admin, created_at FROM ticket_messages WHERE ticket_id = ? ORDER BY created_at ASC, id ASC');
    $stmt->execute([$ticketId]);
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
    exit;
}

$search = trim((string)($_GET['q'] ?? ''));
$statusFilter = (string)($_GET['status'] ?? '');
if (!in_array($statusFilter, ['', 'open', 'pending', 'processing', 'resolved', 'closed'], true)) $statusFilter = '';
$where = ['t.user_id = ?'];
$params = [$user['id']];
if ($statusFilter !== '') {
    $where[] = 't.status = ?';
    $params[] = $statusFilter;
}
if ($search !== '') {
    $where[] = '(t.subject LIKE ? OR latest.message LIKE ?)';
    $like = '%' . $search . '%';
    array_push($params, $like, $like);
}
$whereSql = implode(' AND ', $where);
$statsStmt = $db->prepare("SELECT COUNT(*) AS total,
    SUM(CASE WHEN status = 'open' THEN 1 ELSE 0 END) AS open_count,
    SUM(CASE WHEN status IN ('pending','processing') THEN 1 ELSE 0 END) AS in_progress_count,
    SUM(CASE WHEN status = 'resolved' THEN 1 ELSE 0 END) AS resolved_count,
    SUM(CASE WHEN status = 'closed' THEN 1 ELSE 0 END) AS closed_count
    FROM tickets WHERE user_id = ?");
$statsStmt->execute([$user['id']]);
$stats = $statsStmt->fetch(PDO::FETCH_ASSOC) ?: [];
$stmt = $db->prepare("SELECT t.*, latest.message AS latest_message,
    (SELECT COUNT(*) FROM ticket_messages c WHERE c.ticket_id = t.id) AS message_count
    FROM tickets t
    LEFT JOIN ticket_messages latest ON latest.id = (SELECT MAX(m.id) FROM ticket_messages m WHERE m.ticket_id = t.id)
    WHERE $whereSql
    ORDER BY FIELD(t.status, 'open', 'pending', 'processing', 'resolved', 'closed'), t.created_at DESC, t.id DESC LIMIT 100");
$stmt->execute($params);
$tickets = $stmt->fetchAll(PDO::FETCH_ASSOC);
$csrfToken = csrf_token();

function merchant_ticket_status_style($status) {
    switch ((string)$status) {
        case 'open': return 'bg-sky-50 text-sky-700 ring-sky-200';
        case 'pending':
        case 'processing': return 'bg-amber-50 text-amber-700 ring-amber-200';
        case 'resolved': return 'bg-emerald-50 text-emerald-700 ring-emerald-200';
        default: return 'bg-slate-100 text-slate-600 ring-slate-200';
    }
}

include '../includes/dashboard-head.php';
?>
<body class="bg-slate-50 text-slate-900 flex h-screen overflow-hidden" x-data="merchantTicketCenter()">
    <?php include '../includes/sidebar.php'; ?>
    <main class="flex-1 flex flex-col min-w-0 overflow-hidden">
        <?php include '../includes/topbar.php'; ?>
        <div class="flex-1 overflow-y-auto p-4 sm:p-8">
            <div class="mx-auto max-w-7xl space-y-6">
                <header class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
                    <div>
                        <p class="text-xs font-extrabold uppercase tracking-[0.18em] text-indigo-600">Help · Your account</p>
                        <h1 class="mt-2 text-3xl font-extrabold tracking-tight text-slate-950">Support center</h1>
                        <p class="mt-2 text-sm text-slate-500">Create a request and follow every reply in one place.</p>
                    </div>
                    <button type="button" @click="newTicketOpen = true" class="inline-flex items-center justify-center gap-2 rounded-xl bg-indigo-600 px-5 py-3 text-sm font-bold text-white shadow-sm hover:bg-indigo-700"><i data-lucide="plus" class="h-4 w-4"></i>New support request</button>
                </header>

                <?php if ($success_msg !== ''): ?><div class="rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-800" role="status"><?php echo htmlspecialchars($success_msg, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>
                <?php if ($error_msg !== ''): ?><div class="rounded-2xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-semibold text-rose-800" role="alert"><?php echo htmlspecialchars($error_msg, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>

                <section class="grid grid-cols-2 gap-3 xl:grid-cols-4">
                    <?php foreach ([['Total requests', (int)($stats['total'] ?? 0), 'messages-square', 'text-slate-900'], ['Open', (int)($stats['open_count'] ?? 0), 'circle-dot', 'text-sky-700'], ['In progress', (int)($stats['in_progress_count'] ?? 0), 'clock-3', 'text-amber-700'], ['Resolved / closed', (int)($stats['resolved_count'] ?? 0) + (int)($stats['closed_count'] ?? 0), 'circle-check', 'text-emerald-700']] as $card): ?>
                        <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm sm:p-5"><div class="flex items-center justify-between"><span class="text-xs font-bold uppercase tracking-wider text-slate-400"><?php echo htmlspecialchars($card[0], ENT_QUOTES, 'UTF-8'); ?></span><i data-lucide="<?php echo htmlspecialchars($card[2], ENT_QUOTES, 'UTF-8'); ?>" class="h-4 w-4 text-slate-400"></i></div><p class="mt-3 text-2xl font-extrabold <?php echo $card[3]; ?>"><?php echo number_format($card[1]); ?></p></div>
                    <?php endforeach; ?>
                </section>

                <section class="overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm">
                    <form method="GET" class="flex flex-col gap-3 border-b border-slate-100 bg-slate-50/70 p-4 sm:flex-row sm:items-center">
                        <label class="relative flex-1"><span class="sr-only">Search tickets</span><i data-lucide="search" class="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400"></i><input type="search" name="q" value="<?php echo htmlspecialchars($search, ENT_QUOTES, 'UTF-8'); ?>" placeholder="Search your requests" class="w-full rounded-xl border border-slate-200 bg-white py-3 pl-10 pr-4 text-sm outline-none focus:border-indigo-400 focus:ring-4 focus:ring-indigo-100"></label>
                        <select name="status" class="rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm font-semibold text-slate-700 outline-none"><option value="">All statuses</option><option value="open" <?php echo $statusFilter === 'open' ? 'selected' : ''; ?>>Open</option><option value="pending" <?php echo $statusFilter === 'pending' ? 'selected' : ''; ?>>In progress</option><option value="processing" <?php echo $statusFilter === 'processing' ? 'selected' : ''; ?>>In progress (legacy)</option><option value="resolved" <?php echo $statusFilter === 'resolved' ? 'selected' : ''; ?>>Resolved</option><option value="closed" <?php echo $statusFilter === 'closed' ? 'selected' : ''; ?>>Closed</option></select>
                        <button class="rounded-xl bg-slate-900 px-5 py-3 text-sm font-bold text-white hover:bg-slate-700">Filter</button><a href="tickets.php" class="rounded-xl border border-slate-200 bg-white px-5 py-3 text-center text-sm font-bold text-slate-600 hover:bg-slate-50">Reset</a>
                    </form>
                    <div class="flex items-center justify-between gap-3 px-5 py-4 sm:px-6"><div><h2 class="font-bold text-slate-900">Your requests</h2><p class="mt-1 text-xs text-slate-500">Your conversations are private to your account.</p></div><span class="rounded-full bg-indigo-50 px-3 py-1 text-xs font-bold text-indigo-700"><?php echo count($tickets); ?> shown</span></div>
                    <?php if ($tickets): ?>
                        <div class="divide-y divide-slate-100">
                            <?php foreach ($tickets as $ticket): ?>
                                <?php $ticketJson = htmlspecialchars(json_encode($ticket, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP), ENT_QUOTES, 'UTF-8'); ?>
                                <article class="flex flex-col gap-4 px-5 py-5 transition hover:bg-indigo-50/30 sm:flex-row sm:items-center sm:justify-between sm:px-6">
                                    <div class="min-w-0 flex-1"><div class="flex flex-wrap items-center gap-2"><h3 class="max-w-full truncate text-sm font-bold text-slate-900"><?php echo htmlspecialchars($ticket['subject'] ?? '(No subject)', ENT_QUOTES, 'UTF-8'); ?></h3><span class="inline-flex rounded-full px-2.5 py-1 text-[10px] font-extrabold uppercase ring-1 <?php echo merchant_ticket_status_style($ticket['status']); ?>"><?php echo htmlspecialchars($ticket['status'] === 'pending' || $ticket['status'] === 'processing' ? 'in progress' : (string)$ticket['status'], ENT_QUOTES, 'UTF-8'); ?></span><span class="rounded-full px-2.5 py-1 text-[10px] font-extrabold uppercase <?php echo in_array($ticket['priority'], ['critical', 'high'], true) ? 'bg-rose-50 text-rose-700' : 'bg-slate-100 text-slate-600'; ?>"><?php echo htmlspecialchars((string)$ticket['priority'], ENT_QUOTES, 'UTF-8'); ?> priority</span></div><p class="mt-1 text-[11px] text-slate-400">Ticket #<?php echo (int)$ticket['id']; ?> · opened <?php echo htmlspecialchars(date('M j, Y', strtotime($ticket['created_at'])), ENT_QUOTES, 'UTF-8'); ?> · <?php echo (int)$ticket['message_count']; ?> messages</p><p class="mt-2 truncate text-xs text-slate-500"><?php echo htmlspecialchars((string)($ticket['latest_message'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></p></div>
                                    <button type="button" @click="openTicket(<?php echo $ticketJson; ?>)" class="inline-flex shrink-0 items-center justify-center gap-2 rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-xs font-bold text-indigo-700 hover:border-indigo-200 hover:bg-indigo-50"><i data-lucide="messages-square" class="h-4 w-4"></i>Open conversation</button>
                                </article>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="px-6 py-20 text-center"><div class="mx-auto flex h-14 w-14 items-center justify-center rounded-2xl bg-slate-100 text-slate-400"><i data-lucide="inbox" class="h-7 w-7"></i></div><h3 class="mt-4 font-bold text-slate-900"><?php echo $search || $statusFilter ? 'No requests match your filters' : 'No support requests yet'; ?></h3><p class="mt-1 text-sm text-slate-500">Start a request and the support team will reply here.</p><button type="button" @click="newTicketOpen = true" class="mt-4 text-sm font-bold text-indigo-600 hover:text-indigo-800">Create a support request</button></div>
                    <?php endif; ?>
                </section>

                <a href="../docs.php" class="flex items-center justify-between gap-4 rounded-2xl border border-indigo-100 bg-indigo-50/70 p-5 text-sm hover:bg-indigo-50"><span class="flex items-center gap-3"><span class="flex h-10 w-10 items-center justify-center rounded-xl bg-white text-indigo-600"><i data-lucide="book-open-check" class="h-5 w-5"></i></span><span><strong class="block text-slate-900">Need a quick answer?</strong><span class="text-xs text-slate-500">Browse our guides and common questions.</span></span></span><i data-lucide="arrow-up-right" class="h-4 w-4 text-indigo-600"></i></a>
            </div>
        </div>

        <div x-show="newTicketOpen" x-cloak @keydown.escape.window="newTicketOpen = false" @click.self="newTicketOpen = false" class="fixed inset-0 z-[100] flex items-center justify-center bg-slate-950/50 p-3 backdrop-blur-sm sm:p-6">
            <section class="w-full max-w-xl overflow-hidden rounded-3xl bg-white shadow-2xl" role="dialog" aria-modal="true" aria-label="Create support request">
                <header class="flex items-center justify-between border-b border-slate-100 bg-slate-50 px-6 py-5"><div><p class="text-[10px] font-extrabold uppercase tracking-[0.16em] text-indigo-600">Support request</p><h2 class="mt-1 text-lg font-extrabold text-slate-950">How can we help?</h2></div><button type="button" @click="newTicketOpen = false" class="rounded-xl p-2 text-slate-500 hover:bg-slate-200"><i data-lucide="x" class="h-5 w-5"></i></button></header>
                <form method="POST" class="space-y-5 p-6"><input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>"><input type="hidden" name="action" value="new_ticket">
                    <label class="block"><span class="mb-2 block text-xs font-bold uppercase tracking-wide text-slate-500">Subject</span><input name="subject" required maxlength="255" placeholder="A short summary of your issue" class="w-full rounded-xl border border-slate-200 px-4 py-3 text-sm outline-none focus:border-indigo-400 focus:ring-4 focus:ring-indigo-100"></label>
                    <label class="block"><span class="mb-2 block text-xs font-bold uppercase tracking-wide text-slate-500">Priority</span><select name="priority" class="w-full rounded-xl border border-slate-200 px-4 py-3 text-sm outline-none focus:border-indigo-400"><option value="low">Low</option><option value="medium" selected>Medium</option><option value="high">High</option><option value="critical">Critical</option></select></label>
                    <label class="block"><span class="mb-2 block text-xs font-bold uppercase tracking-wide text-slate-500">Details</span><textarea name="message" required maxlength="10000" rows="6" placeholder="Describe what happened and what you need help with…" class="w-full resize-y rounded-xl border border-slate-200 px-4 py-3 text-sm outline-none focus:border-indigo-400 focus:ring-4 focus:ring-indigo-100"></textarea></label>
                    <div class="flex justify-end gap-2"><button type="button" @click="newTicketOpen = false" class="rounded-xl border border-slate-200 px-4 py-3 text-sm font-bold text-slate-600">Cancel</button><button class="inline-flex items-center gap-2 rounded-xl bg-indigo-600 px-5 py-3 text-sm font-bold text-white hover:bg-indigo-700"><i data-lucide="send" class="h-4 w-4"></i>Send request</button></div>
                </form>
            </section>
        </div>

        <div x-show="ticketOpen" x-cloak @keydown.escape.window="ticketOpen = false" @click.self="ticketOpen = false" class="fixed inset-0 z-[100] flex items-center justify-center bg-slate-950/50 p-3 backdrop-blur-sm sm:p-6">
            <section class="flex max-h-[94vh] w-full max-w-3xl flex-col overflow-hidden rounded-3xl bg-white shadow-2xl" role="dialog" aria-modal="true" aria-label="Ticket conversation">
                <header class="flex items-start justify-between border-b border-slate-100 bg-slate-50 px-5 py-5 sm:px-7"><div class="min-w-0"><p class="text-[10px] font-extrabold uppercase tracking-[0.16em] text-indigo-600">Ticket <span x-text="ticket?.id ? '#' + ticket.id : ''"></span></p><h2 class="mt-1 truncate text-lg font-extrabold text-slate-950" x-text="ticket?.subject"></h2><p class="mt-1 text-xs text-slate-500" x-text="'Status: ' + (ticket?.status === 'pending' || ticket?.status === 'processing' ? 'in progress' : (ticket?.status || ''))"></p></div><button type="button" @click="ticketOpen = false" class="rounded-xl p-2 text-slate-500 hover:bg-slate-200"><i data-lucide="x" class="h-5 w-5"></i></button></header>
                <div class="flex-1 space-y-4 overflow-y-auto bg-slate-50/60 p-5 sm:p-7"><div x-show="messagesLoading" class="py-10 text-center text-sm text-slate-400">Loading conversation…</div><div x-show="messageError" class="rounded-xl bg-rose-50 p-3 text-sm text-rose-700" x-text="messageError"></div>
                    <template x-for="message in messages" :key="message.id"><div :class="Number(message.is_admin) === 1 ? 'flex justify-start' : 'flex justify-end'"><div class="max-w-[88%] rounded-2xl p-4 shadow-sm" :class="Number(message.is_admin) === 1 ? 'border border-slate-200 bg-white text-slate-800' : 'bg-indigo-600 text-white'"><p class="mb-1 text-[10px] font-extrabold uppercase opacity-70" x-text="Number(message.is_admin) === 1 ? 'Support team' : 'You'"></p><p class="whitespace-pre-wrap break-words text-sm" x-text="message.message"></p><p class="mt-2 text-[10px] opacity-60" x-text="new Date(message.created_at.replace(' ', 'T') + 'Z').toLocaleString()"></p></div></div></template>
                </div>
                <footer class="border-t border-slate-100 bg-white p-5 sm:p-6"><template x-if="ticket?.status !== 'closed'"><form method="POST" class="flex flex-col gap-2 sm:flex-row"><input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>"><input type="hidden" name="action" value="new_ticket_message"><input type="hidden" name="ticket_id" :value="ticket?.id"><textarea name="message" required maxlength="10000" rows="2" placeholder="Add a reply to this conversation…" class="min-h-[48px] flex-1 resize-y rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm outline-none focus:border-indigo-400 focus:ring-4 focus:ring-indigo-100"></textarea><button class="inline-flex items-center justify-center gap-2 rounded-xl bg-indigo-600 px-5 py-3 text-sm font-bold text-white hover:bg-indigo-700"><i data-lucide="send" class="h-4 w-4"></i>Reply</button></form></template><p x-show="ticket?.status === 'closed'" class="text-center text-xs font-semibold text-slate-500">This ticket is closed. Open a new request if you still need help.</p></footer>
            </section>
        </div>
        <?php include '../includes/merchant-quick-actions.php'; ?>
    </main>
<script>
function merchantTicketCenter() {
    return {
        newTicketOpen: false, ticketOpen: false, ticket: null, messages: [], messagesLoading: false, messageError: '',
        async openTicket(ticket) {
            this.ticket = ticket;
            this.ticketOpen = true;
            this.messagesLoading = true;
            this.messageError = '';
            this.messages = [];
            try {
                const response = await fetch('?action=get_messages&id=' + encodeURIComponent(ticket.id), { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
                const data = await response.json();
                if (!response.ok) throw new Error(data.error || 'Unable to load this conversation.');
                this.messages = data;
            } catch (error) {
                this.messageError = error.message;
            } finally { this.messagesLoading = false; }
        }
    };
}
document.addEventListener('DOMContentLoaded', () => { if (typeof lucide !== 'undefined') lucide.createIcons(); });
</script>
</body>
</html>
