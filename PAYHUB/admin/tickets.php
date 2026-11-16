<?php
// Admin support-ticket inbox.
require_once '../includes/functions.php';

if (!isLoggedIn() || !isAdmin()) redirect('../login.php');

$user = getAuthUser();
$db = Database::connect();
$pageTitle = 'Support Inbox - PayHub';
$success_msg = '';
$error_msg = '';
$allowedStatuses = ['open', 'pending', 'resolved', 'closed'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $error_msg = 'Your security token expired. Refresh the page and try again.';
    } else {
        $action = (string)$_POST['action'];
        $ticketId = filter_var($_POST['ticket_id'] ?? null, FILTER_VALIDATE_INT);
        if (!$ticketId || $ticketId < 1) {
            $error_msg = 'Invalid ticket. Refresh the inbox and try again.';
        } elseif ($action === 'update_status') {
            $status = (string)($_POST['status'] ?? '');
            if (!in_array($status, $allowedStatuses, true)) {
                $error_msg = 'Choose a valid ticket status.';
            } else {
                $stmt = $db->prepare('UPDATE tickets SET status = ? WHERE id = ?');
                $stmt->execute([$status, $ticketId]);
                $success_msg = $stmt->rowCount() ? 'Ticket status updated.' : 'No ticket status changed.';
            }
        } elseif ($action === 'reply_ticket') {
            $message = trim((string)($_POST['message'] ?? ''));
            $message = function_exists('mb_substr') ? mb_substr($message, 0, 10000) : substr($message, 0, 10000);
            if ($message === '') {
                $error_msg = 'Write a reply before sending.';
            } else {
                $stmt = $db->prepare("SELECT t.subject, t.guest_email, t.is_registered, u.email AS user_email FROM tickets t LEFT JOIN users u ON u.id = t.user_id WHERE t.id = ?");
                $stmt->execute([$ticketId]);
                $ticket = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$ticket) {
                    $error_msg = 'Ticket not found.';
                } else {
                    $stmt = $db->prepare('INSERT INTO ticket_messages (ticket_id, user_id, message, is_admin) VALUES (?, ?, ?, 1)');
                    $stmt->execute([$ticketId, $user['id'], $message]);
                    $to = !empty($ticket['is_registered']) ? ($ticket['user_email'] ?? '') : ($ticket['guest_email'] ?? '');
                    if ($to !== '') {
                        $safeMessage = nl2br(htmlspecialchars($message, ENT_QUOTES, 'UTF-8'));
                        sendEmail($to, 'Re: ' . $ticket['subject'], '<h2>Support Reply</h2><p>' . $safeMessage . '</p><hr><p>You can manage this ticket in your PayHub dashboard.</p>');
                    }
                    $success_msg = 'Your reply was added to the ticket.';
                }
            }
        } elseif ($action === 'delete_ticket') {
            $db->beginTransaction();
            try {
                $lock = $db->prepare('SELECT id FROM tickets WHERE id = ? FOR UPDATE');
                $lock->execute([$ticketId]);
                if (!$lock->fetchColumn()) {
                    throw new RuntimeException('Ticket not found; it may already have been removed.');
                }
                // Delete the conversation explicitly because older installations may not have
                // the ticket_messages foreign-key cascade after their support-schema migration.
                $db->prepare('DELETE FROM ticket_messages WHERE ticket_id = ?')->execute([$ticketId]);
                $db->prepare('DELETE FROM tickets WHERE id = ?')->execute([$ticketId]);
                $db->commit();
                $success_msg = 'Ticket and its conversation were deleted.';
            } catch (Throwable $e) {
                if ($db->inTransaction()) $db->rollBack();
                $error_msg = $e->getMessage();
            }
        }
    }
}

// Private message endpoint: this page is admin-only, and returns data rather than HTML.
if (($_GET['action'] ?? '') === 'get_messages') {
    header('Content-Type: application/json; charset=utf-8');
    $ticketId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
    if (!$ticketId || $ticketId < 1) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid ticket id.']);
        exit;
    }
    $stmt = $db->prepare('SELECT id, ticket_id, user_id, message, is_admin, created_at FROM ticket_messages WHERE ticket_id = ? ORDER BY created_at ASC, id ASC');
    $stmt->execute([$ticketId]);
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
    exit;
}

$search = trim((string)($_GET['q'] ?? ''));
$statusFilter = (string)($_GET['status'] ?? '');
if (!in_array($statusFilter, array_merge([''], $allowedStatuses, ['processing']), true)) $statusFilter = '';
$params = [];
$where = [];
if ($statusFilter !== '') {
    $where[] = 't.status = ?';
    $params[] = $statusFilter;
}
if ($search !== '') {
    $where[] = '(t.subject LIKE ? OR u.business_name LIKE ? OR u.email LIKE ? OR t.guest_email LIKE ?)';
    $like = '%' . $search . '%';
    array_push($params, $like, $like, $like, $like);
}
$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';
$stats = $db->query("SELECT
    COUNT(*) AS total,
    SUM(CASE WHEN status = 'open' THEN 1 ELSE 0 END) AS open_count,
    SUM(CASE WHEN status IN ('pending','processing') THEN 1 ELSE 0 END) AS in_progress_count,
    SUM(CASE WHEN status = 'resolved' THEN 1 ELSE 0 END) AS resolved_count,
    SUM(CASE WHEN status = 'closed' THEN 1 ELSE 0 END) AS closed_count
    FROM tickets")->fetch(PDO::FETCH_ASSOC) ?: [];
$stmt = $db->prepare("SELECT t.*, u.business_name, u.email AS user_email,
    (SELECT tm.message FROM ticket_messages tm WHERE tm.ticket_id = t.id ORDER BY tm.created_at DESC, tm.id DESC LIMIT 1) AS latest_message,
    (SELECT COUNT(*) FROM ticket_messages tc WHERE tc.ticket_id = t.id) AS message_count
    FROM tickets t LEFT JOIN users u ON u.id = t.user_id $whereSql
    ORDER BY FIELD(t.status, 'open', 'pending', 'processing', 'resolved', 'closed'), t.created_at DESC, t.id DESC LIMIT 200");
$stmt->execute($params);
$tickets = $stmt->fetchAll(PDO::FETCH_ASSOC);
$csrfToken = csrf_token();

function admin_ticket_status_style($status) {
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
<body class="bg-slate-50 text-slate-900 flex h-screen overflow-hidden" x-data="adminTicketInbox()">
    <?php include '../includes/sidebar.php'; ?>
    <main class="flex-1 flex flex-col min-w-0 overflow-hidden">
        <?php include '../includes/topbar.php'; ?>
        <div class="flex-1 overflow-y-auto p-4 sm:p-8">
            <div class="mx-auto max-w-7xl space-y-6">
                <header class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
                    <div>
                        <p class="text-xs font-extrabold uppercase tracking-[0.18em] text-indigo-600">Customer care · Operations</p>
                        <h1 class="mt-2 text-3xl font-extrabold tracking-tight text-slate-950">Support inbox</h1>
                        <p class="mt-2 text-sm text-slate-500">Triage merchant requests, keep conversations together, and close the loop.</p>
                    </div>
                    <div class="flex items-center gap-2 rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-600 shadow-sm">
                        <i data-lucide="inbox" class="h-4 w-4 text-indigo-600"></i>
                        <span><strong class="text-slate-900"><?php echo (int)($stats['open_count'] ?? 0) + (int)($stats['in_progress_count'] ?? 0); ?></strong> tickets need attention</span>
                    </div>
                </header>

                <?php if ($success_msg !== ''): ?><div class="rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-800" role="status"><?php echo htmlspecialchars($success_msg, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>
                <?php if ($error_msg !== ''): ?><div class="rounded-2xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-semibold text-rose-800" role="alert"><?php echo htmlspecialchars($error_msg, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>

                <section class="grid grid-cols-2 gap-3 xl:grid-cols-5">
                    <?php foreach ([['All tickets', (int)($stats['total'] ?? 0), 'layers', 'text-slate-900'], ['Open', (int)($stats['open_count'] ?? 0), 'circle-dot', 'text-sky-700'], ['In progress', (int)($stats['in_progress_count'] ?? 0), 'clock-3', 'text-amber-700'], ['Resolved', (int)($stats['resolved_count'] ?? 0), 'circle-check', 'text-emerald-700'], ['Closed', (int)($stats['closed_count'] ?? 0), 'archive', 'text-slate-600']] as $card): ?>
                        <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm sm:p-5">
                            <div class="flex items-center justify-between"><span class="text-xs font-bold uppercase tracking-wider text-slate-400"><?php echo htmlspecialchars($card[0], ENT_QUOTES, 'UTF-8'); ?></span><i data-lucide="<?php echo htmlspecialchars($card[2], ENT_QUOTES, 'UTF-8'); ?>" class="h-4 w-4 text-slate-400"></i></div>
                            <p class="mt-3 text-2xl font-extrabold <?php echo $card[3]; ?>"><?php echo number_format($card[1]); ?></p>
                        </div>
                    <?php endforeach; ?>
                </section>

                <section class="overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm">
                    <form method="GET" class="flex flex-col gap-3 border-b border-slate-100 bg-slate-50/70 p-4 sm:flex-row sm:items-center">
                        <label class="relative flex-1">
                            <span class="sr-only">Search tickets</span><i data-lucide="search" class="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400"></i>
                            <input type="search" name="q" value="<?php echo htmlspecialchars($search, ENT_QUOTES, 'UTF-8'); ?>" placeholder="Search subject, merchant, or email" class="w-full rounded-xl border border-slate-200 bg-white py-3 pl-10 pr-4 text-sm outline-none transition focus:border-indigo-400 focus:ring-4 focus:ring-indigo-100">
                        </label>
                        <select name="status" class="rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm font-semibold text-slate-700 outline-none focus:border-indigo-400">
                            <option value="">All statuses</option>
                            <option value="open" <?php echo $statusFilter === 'open' ? 'selected' : ''; ?>>Open</option>
                            <option value="pending" <?php echo $statusFilter === 'pending' ? 'selected' : ''; ?>>In progress</option>
                            <option value="processing" <?php echo $statusFilter === 'processing' ? 'selected' : ''; ?>>In progress (legacy)</option>
                            <option value="resolved" <?php echo $statusFilter === 'resolved' ? 'selected' : ''; ?>>Resolved</option>
                            <option value="closed" <?php echo $statusFilter === 'closed' ? 'selected' : ''; ?>>Closed</option>
                        </select>
                        <button class="rounded-xl bg-slate-900 px-5 py-3 text-sm font-bold text-white hover:bg-slate-700">Filter</button>
                        <a href="tickets.php" class="rounded-xl border border-slate-200 bg-white px-5 py-3 text-center text-sm font-bold text-slate-600 hover:bg-slate-50">Reset</a>
                    </form>

                    <div class="flex items-center justify-between gap-3 px-5 py-4 sm:px-6">
                        <div><h2 class="font-bold text-slate-900">Ticket queue</h2><p class="mt-1 text-xs text-slate-500">Showing up to 200 newest matching requests.</p></div>
                        <span class="rounded-full bg-indigo-50 px-3 py-1 text-xs font-bold text-indigo-700"><?php echo count($tickets); ?> shown</span>
                    </div>
                    <?php if ($tickets): ?>
                        <div class="overflow-x-auto">
                            <table class="w-full min-w-[850px] text-left">
                                <thead class="border-y border-slate-100 bg-slate-50 text-[10px] font-extrabold uppercase tracking-wider text-slate-400"><tr><th class="px-5 py-3">Request</th><th class="px-5 py-3">Merchant</th><th class="px-5 py-3">Priority</th><th class="px-5 py-3">Status</th><th class="px-5 py-3">Updated</th><th class="px-5 py-3 text-right">Actions</th></tr></thead>
                                <tbody class="divide-y divide-slate-100">
                                    <?php foreach ($tickets as $ticket): ?>
                                        <?php $ticketJson = htmlspecialchars(json_encode($ticket, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP), ENT_QUOTES, 'UTF-8'); ?>
                                        <tr class="transition hover:bg-indigo-50/30">
                                            <td class="max-w-[300px] px-5 py-4"><button type="button" @click="openTicket(<?php echo $ticketJson; ?>)" class="block max-w-full text-left"><span class="block truncate text-sm font-bold text-slate-900 hover:text-indigo-700"><?php echo htmlspecialchars($ticket['subject'] ?? '(No subject)', ENT_QUOTES, 'UTF-8'); ?></span><span class="mt-1 block text-[11px] text-slate-400">#<?php echo (int)$ticket['id']; ?> · <?php echo (int)$ticket['message_count']; ?> messages</span><span class="mt-2 block truncate text-xs text-slate-500"><?php echo htmlspecialchars((string)($ticket['latest_message'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></span></button></td>
                                            <td class="px-5 py-4"><p class="text-sm font-semibold text-slate-800"><?php echo htmlspecialchars((string)($ticket['business_name'] ?: ($ticket['is_registered'] ? 'Registered merchant' : 'Guest')), ENT_QUOTES, 'UTF-8'); ?></p><p class="mt-1 text-xs text-slate-500"><?php echo htmlspecialchars((string)($ticket['is_registered'] ? $ticket['user_email'] : ($ticket['guest_email'] ?? '')), ENT_QUOTES, 'UTF-8'); ?></p></td>
                                            <td class="px-5 py-4"><span class="inline-flex rounded-full px-2.5 py-1 text-[10px] font-extrabold uppercase ring-1 <?php echo in_array($ticket['priority'], ['critical', 'high'], true) ? 'bg-rose-50 text-rose-700 ring-rose-200' : ($ticket['priority'] === 'medium' ? 'bg-amber-50 text-amber-700 ring-amber-200' : 'bg-slate-50 text-slate-600 ring-slate-200'); ?>"><?php echo htmlspecialchars((string)$ticket['priority'], ENT_QUOTES, 'UTF-8'); ?></span></td>
                                            <td class="px-5 py-4"><span class="inline-flex rounded-full px-2.5 py-1 text-[10px] font-extrabold uppercase ring-1 <?php echo admin_ticket_status_style($ticket['status']); ?>"><?php echo htmlspecialchars($ticket['status'] === 'pending' || $ticket['status'] === 'processing' ? 'in progress' : (string)$ticket['status'], ENT_QUOTES, 'UTF-8'); ?></span></td>
                                            <td class="whitespace-nowrap px-5 py-4 text-xs text-slate-500"><?php echo htmlspecialchars(date('M j, Y · H:i', strtotime($ticket['created_at'])), ENT_QUOTES, 'UTF-8'); ?></td>
                                            <td class="px-5 py-4"><div class="flex justify-end gap-2"><button type="button" @click="openTicket(<?php echo $ticketJson; ?>)" class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-xs font-bold text-indigo-700 hover:border-indigo-200 hover:bg-indigo-50">Open</button><form method="POST" onsubmit="return confirm('Permanently delete ticket #<?php echo (int)$ticket['id']; ?> and its entire message history?');"><input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>"><input type="hidden" name="action" value="delete_ticket"><input type="hidden" name="ticket_id" value="<?php echo (int)$ticket['id']; ?>"><button type="submit" aria-label="Delete ticket" title="Delete ticket" class="rounded-lg border border-rose-200 bg-white p-2 text-rose-600 hover:bg-rose-50"><i data-lucide="trash-2" class="h-4 w-4"></i></button></form></div></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <div class="px-6 py-20 text-center"><div class="mx-auto flex h-14 w-14 items-center justify-center rounded-2xl bg-slate-100 text-slate-400"><i data-lucide="inbox" class="h-7 w-7"></i></div><h3 class="mt-4 font-bold text-slate-900">No tickets match this view</h3><p class="mt-1 text-sm text-slate-500">Try a different search or status filter.</p></div>
                    <?php endif; ?>
                </section>
            </div>
        </div>

        <div x-show="ticketOpen" x-cloak @keydown.escape.window="ticketOpen = false" @click.self="ticketOpen = false" class="fixed inset-0 z-[100] flex items-center justify-center bg-slate-950/50 p-3 backdrop-blur-sm sm:p-6">
            <section class="flex max-h-[94vh] w-full max-w-3xl flex-col overflow-hidden rounded-3xl bg-white shadow-2xl" role="dialog" aria-modal="true" aria-label="Support ticket conversation">
                <header class="flex items-start justify-between border-b border-slate-100 bg-slate-50 px-5 py-5 sm:px-7">
                    <div class="min-w-0"><p class="text-[10px] font-extrabold uppercase tracking-[0.16em] text-indigo-600">Ticket <span x-text="ticket?.id ? '#' + ticket.id : ''"></span></p><h2 class="mt-1 truncate text-lg font-extrabold text-slate-950" x-text="ticket?.subject"></h2><p class="mt-1 text-xs text-slate-500" x-text="ticket?.business_name || ticket?.user_email || ticket?.guest_email || 'Merchant' "></p></div>
                    <button type="button" @click="ticketOpen = false" class="rounded-xl p-2 text-slate-500 hover:bg-slate-200" aria-label="Close"><i data-lucide="x" class="h-5 w-5"></i></button>
                </header>
                <div class="flex-1 space-y-4 overflow-y-auto bg-slate-50/60 p-5 sm:p-7">
                    <div x-show="messagesLoading" class="py-10 text-center text-sm text-slate-400">Loading conversation…</div>
                    <template x-for="message in messages" :key="message.id"><div :class="Number(message.is_admin) === 1 ? 'flex justify-end' : 'flex justify-start'"><div class="max-w-[88%] rounded-2xl p-4 shadow-sm" :class="Number(message.is_admin) === 1 ? 'bg-indigo-600 text-white' : 'border border-slate-200 bg-white text-slate-800'"><p class="mb-1 text-[10px] font-extrabold uppercase opacity-70" x-text="Number(message.is_admin) === 1 ? 'Support team' : 'Merchant'"></p><p class="whitespace-pre-wrap break-words text-sm" x-text="message.message"></p><p class="mt-2 text-[10px] opacity-60" x-text="new Date(message.created_at.replace(' ', 'T') + 'Z').toLocaleString()"></p></div></div></template>
                </div>
                <footer class="space-y-4 border-t border-slate-100 bg-white p-5 sm:p-6">
                    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                        <form method="POST" class="flex items-center gap-2"><input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>"><input type="hidden" name="action" value="update_status"><input type="hidden" name="ticket_id" :value="ticket?.id"><label for="ticket-status" class="text-xs font-bold text-slate-500">Status</label><select id="ticket-status" name="status" x-model="statusDraft" class="rounded-lg border border-slate-200 px-3 py-2 text-xs font-semibold"><option value="open">Open</option><option value="pending">In progress</option><option value="resolved">Resolved</option><option value="closed">Closed</option></select><button class="rounded-lg bg-slate-900 px-3 py-2 text-xs font-bold text-white hover:bg-slate-700">Update</button></form>
                        <form method="POST" onsubmit="return confirm('Permanently delete this ticket and its message history?');"><input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>"><input type="hidden" name="action" value="delete_ticket"><input type="hidden" name="ticket_id" :value="ticket?.id"><button class="inline-flex items-center gap-2 rounded-lg border border-rose-200 px-3 py-2 text-xs font-bold text-rose-700 hover:bg-rose-50"><i data-lucide="trash-2" class="h-4 w-4"></i>Delete ticket</button></form>
                    </div>
                    <form method="POST" class="flex flex-col gap-2 sm:flex-row"><input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>"><input type="hidden" name="action" value="reply_ticket"><input type="hidden" name="ticket_id" :value="ticket?.id"><textarea name="message" required maxlength="10000" rows="2" placeholder="Write a helpful reply…" class="min-h-[48px] flex-1 resize-y rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm outline-none focus:border-indigo-400 focus:ring-4 focus:ring-indigo-100"></textarea><button class="inline-flex items-center justify-center gap-2 rounded-xl bg-indigo-600 px-5 py-3 text-sm font-bold text-white hover:bg-indigo-700"><i data-lucide="send" class="h-4 w-4"></i>Send reply</button></form>
                </footer>
            </section>
        </div>
    </main>
<script>
function adminTicketInbox() {
    return {
        ticketOpen: false, ticket: null, messages: [], messagesLoading: false, statusDraft: 'open',
        async openTicket(ticket) {
            this.ticket = ticket;
            this.statusDraft = ['pending', 'processing'].includes(ticket.status) ? 'pending' : ticket.status;
            this.ticketOpen = true;
            this.messagesLoading = true;
            this.messages = [];
            try {
                const response = await fetch('?action=get_messages&id=' + encodeURIComponent(ticket.id), { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
                if (!response.ok) throw new Error('Unable to load this conversation.');
                this.messages = await response.json();
            } catch (error) {
                this.messages = [{ id: 'error', is_admin: 1, message: error.message, created_at: new Date().toISOString() }];
            } finally { this.messagesLoading = false; }
        }
    };
}
document.addEventListener('DOMContentLoaded', () => { if (typeof lucide !== 'undefined') lucide.createIcons(); });
</script>
</body>
</html>
