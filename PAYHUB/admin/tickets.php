<?php
// php-version/admin/tickets.php
require_once '../includes/functions.php';

if (!isLoggedIn() || !isAdmin()) redirect('../login.php');

$user = getAuthUser();
$pageTitle = 'Support Tickets - Admin Hub';

$db = Database::connect();

// Handle Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $error_msg = "Invalid security token.";
    } elseif ($_POST['action'] === 'update_status') {
        $ticketId = (int)$_POST['ticket_id'];
        $status = sanitize($_POST['status']);
        $stmt = $db->prepare("UPDATE tickets SET status = ? WHERE id = ?");
        $stmt->execute([$status, $ticketId]);
        $success_msg = "Ticket status updated to $status.";
    } elseif ($_POST['action'] === 'reply_ticket') {
        $ticketId = (int)$_POST['ticket_id'];
        $message = sanitize($_POST['message']);

        $stmt = $db->prepare("INSERT INTO ticket_messages (ticket_id, user_id, message, is_admin) VALUES (?, ?, ?, 1)");
        $stmt->execute([$ticketId, $user['id'], $message]);

        // Get recipient
        $stmt = $db->prepare("SELECT t.guest_email, u.email as user_email, t.is_registered, t.subject FROM tickets t LEFT JOIN users u ON t.user_id = u.id WHERE t.id = ?");
        $stmt->execute([$ticketId]);
        $ticket = $stmt->fetch();

        $to = $ticket['is_registered'] ? $ticket['user_email'] : $ticket['guest_email'];
        sendEmail($to, "Re: " . $ticket['subject'], "<h2>Support Reply</h2><p>$message</p><hr><p>This is a reply to your support ticket. You can manage your tickets in your dashboard.</p>");

        $success_msg = "Reply sent to $to";
    }
}

$stmt = $db->query("SELECT t.*, u.business_name, u.email as user_email FROM tickets t LEFT JOIN users u ON t.user_id = u.id ORDER BY t.created_at DESC");
$tickets = $stmt->fetchAll();

include '../includes/dashboard-head.php';
?>
<body class="bg-slate-50 text-slate-900 flex h-screen overflow-hidden" x-data>
    <?php include '../includes/sidebar.php'; ?>
    <main class="flex-1 flex flex-col min-w-0 overflow-hidden" x-data="{ showReply: false, ticketId: null, ticketSubject: '' }">
        <?php include '../includes/topbar.php'; ?>
        <div class="flex-1 overflow-y-auto p-4 sm:p-8">
            <?php if (isset($success_msg)): ?>
                <div class="mb-6 p-4 bg-emerald-50 text-emerald-700 rounded-2xl border border-emerald-100 font-medium">
                    <?php echo $success_msg; ?>
                </div>
            <?php endif; ?>

            <div class="mb-8">
                <h1 class="text-2xl font-bold text-slate-900 mb-2">Support Tickets</h1>
                <p class="text-slate-500">Manage merchant inquiries and support requests</p>
            </div>

            <div class="bg-white rounded-3xl border border-slate-200 shadow-sm overflow-hidden">
                <div class="p-6 border-b border-slate-100 font-bold flex justify-between items-center">
                    <span>Active Tickets</span>
                    <span class="text-xs font-normal text-slate-500"><?php echo count(array_filter($tickets, fn($t) => $t['status'] !== 'closed')); ?> tickets need resolution</span>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-left">
                        <thead>
                            <tr class="bg-slate-50">
                                <th class="px-4 sm:px-6 py-4 text-xs font-bold text-slate-500 uppercase">Merchant</th>
                                <th class="px-4 sm:px-6 py-4 text-xs font-bold text-slate-500 uppercase">Subject</th>
                                <th class="hidden lg:table-cell px-6 py-4 text-xs font-bold text-slate-500 uppercase">Priority</th>
                                <th class="hidden sm:table-cell px-6 py-4 text-xs font-bold text-slate-500 uppercase">Status</th>
                                <th class="px-4 sm:px-6 py-4 text-xs font-bold text-slate-500 uppercase">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <?php foreach ($tickets as $t): ?>
                                <tr class="hover:bg-slate-50/50 transition-colors">
                                    <td class="px-4 sm:px-6 py-4">
                                        <div class="font-bold text-slate-900 truncate max-w-[120px] sm:max-w-none">
                                            <?php echo $t['is_registered'] ? ($t['business_name'] ?: 'Registered Merchant') : 'Guest'; ?>
                                        </div>
                                        <div class="hidden sm:block text-[10px] text-slate-500">
                                            <?php echo $t['is_registered'] ? $t['user_email'] : $t['guest_email']; ?>
                                        </div>
                                    </td>
                                    <td class="px-4 sm:px-6 py-4 text-sm font-medium text-slate-700 truncate max-w-[150px] sm:max-w-none"><?php echo $t['subject']; ?></td>
                                    <td class="hidden lg:table-cell px-6 py-4">
                                        <span class="px-2 py-0.5 rounded-lg text-[10px] font-bold uppercase tracking-wider <?php echo $t['priority'] === 'critical' || $t['priority'] === 'high' ? 'bg-red-50 text-red-600' : 'bg-amber-50 text-amber-600'; ?>"><?php echo $t['priority']; ?></span>
                                    </td>
                                    <td class="hidden sm:table-cell px-6 py-4">
                                        <span class="px-2 py-0.5 rounded-lg text-[10px] font-bold uppercase tracking-wider <?php echo $t['status'] === 'open' ? 'bg-indigo-50 text-indigo-700' : ($t['status'] === 'resolved' ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-600'); ?>"><?php echo $t['status']; ?></span>
                                    </td>
                                    <td class="px-4 sm:px-6 py-4">
                                        <div class="flex gap-2">
                                            <button @click="showReply = true; ticketId = <?php echo $t['id']; ?>; ticketSubject = '<?php echo addslashes($t['subject']); ?>'" class="text-indigo-600 hover:underline text-xs font-bold">Reply</button>
                                            <form method="POST" class="inline">
                                                <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                                                <input type="hidden" name="action" value="update_status">
                                                <input type="hidden" name="ticket_id" value="<?php echo $t['id']; ?>">
                                                <button type="submit" name="status" value="resolved" class="text-emerald-600 hover:underline text-xs font-bold">Resolve</button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Reply Modal -->
        <div x-show="showReply" x-cloak class="fixed inset-0 bg-slate-900/50 backdrop-blur-sm flex items-center justify-center z-[100] p-4">
            <div class="bg-white rounded-[2rem] w-full max-w-lg overflow-hidden shadow-2xl border border-slate-200">
                <div class="p-6 border-b border-slate-100 flex justify-between items-center bg-slate-50/50">
                    <h3 class="font-bold text-slate-900">Reply to Ticket</h3>
                    <button @click="showReply = false" class="p-2 text-slate-500 hover:text-slate-900 hover:bg-slate-100 rounded-full transition-all">
                        <i data-lucide="x" class="w-5 h-5"></i>
                    </button>
                </div>
                <div class="p-8">
                    <p class="text-xs font-bold text-slate-400 uppercase mb-2">Subject</p>
                    <p class="text-sm font-bold text-slate-900 mb-6" x-text="ticketSubject"></p>
                    <form method="POST" class="space-y-6">
                        <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                        <input type="hidden" name="action" value="reply_ticket">
                        <input type="hidden" name="ticket_id" :value="ticketId">
                        <textarea name="message" required rows="6" placeholder="Type your response here..." class="w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-xl focus:ring-2 focus:ring-indigo-500/20 outline-none"></textarea>
                        <button type="submit" class="w-full bg-indigo-600 text-white py-4 rounded-xl font-bold hover:bg-indigo-700 transition-all shadow-lg shadow-indigo-100">Send Response</button>
                    </form>
                </div>
            </div>
        </div>
    </main>
<script>
        document.addEventListener('DOMContentLoaded', () => {
            if (typeof lucide !== 'undefined') {
                lucide.createIcons();
            }
        });
    </script>
</body>
</html>