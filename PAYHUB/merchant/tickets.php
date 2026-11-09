<?php
// php-version/merchant/tickets.php
require_once '../includes/functions.php';

if (!isLoggedIn()) redirect('../login.php');

$user = getAuthUser();
$pageTitle = 'Support Center - Payhub';

$db = Database::connect();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $error_msg = "Invalid security token.";
    } elseif ($_POST['action'] === 'new_ticket') {
        $subject = sanitize($_POST['subject']);
        $priority = sanitize($_POST['priority']);
        $message = sanitize($_POST['message']);

        $db->beginTransaction();
        try {
            $stmt = $db->prepare("INSERT INTO tickets (user_id, subject, priority, status) VALUES (?, ?, ?, 'open')");
            $stmt->execute([$user['id'], $subject, $priority]);
            $ticketId = $db->lastInsertId();

            $stmt = $db->prepare("INSERT INTO ticket_messages (ticket_id, user_id, message) VALUES (?, ?, ?)");
            $stmt->execute([$ticketId, $user['id'], $message]);

            $db->commit();
            $success_msg = "Ticket opened successfully!";

            // Notify admins when a merchant opens a new ticket
            if ($user['role'] !== 'admin') {
                notifyAdmins(
                    "New Support Ticket: " . $subject,
                    "<p><strong>Merchant:</strong> " . htmlspecialchars($user['business_name'] ?? $user['email']) . "<br>
                     <strong>Priority:</strong> " . htmlspecialchars($priority) . "<br>
                     <strong>Subject:</strong> " . htmlspecialchars($subject) . "<br>
                     <strong>Message:</strong><br>" . nl2br(htmlspecialchars($message)) . "</p>"
                );
            }
        } catch (Exception $e) {
            $db->rollBack();
            $error_msg = "Failed to open ticket: " . $e->getMessage();
        }
    } elseif ($_POST['action'] === 'new_ticket_message') {
        $ticketId = (int)$_POST['ticket_id'];
        $message = sanitize($_POST['message']);

        $stmt = $db->prepare("INSERT INTO ticket_messages (ticket_id, user_id, message) VALUES (?, ?, ?)");
        if ($stmt->execute([$ticketId, $user['id'], $message])) {
            $success_msg = "Message sent!";

            // Notify admins when a merchant replies to a ticket
            if ($user['role'] !== 'admin') {
                notifyAdmins(
                    "New Reply on Ticket #" . $ticketId,
                    "<p><strong>Merchant:</strong> " . htmlspecialchars($user['business_name'] ?? $user['email']) . "<br>
                     <strong>Ticket ID:</strong> #" . (int)$ticketId . "<br>
                     <strong>Message:</strong><br>" . nl2br(htmlspecialchars($message)) . "</p>"
                );
            }
        } else {
            $error_msg = "Failed to send message.";
        }
    }
}

$stmt = $db->prepare("SELECT * FROM tickets WHERE user_id = ? ORDER BY created_at DESC");
$stmt->execute([$user['id']]);
$tickets = $stmt->fetchAll();

// Handle AJAX for ticket messages
if (isset($_GET['action']) && $_GET['action'] === 'get_messages' && isset($_GET['id'])) {
    header('Content-Type: application/json');
    $tId = (int)$_GET['id'];
    $stmt = $db->prepare("SELECT * FROM ticket_messages WHERE ticket_id = ? ORDER BY created_at ASC");
    $stmt->execute([$tId]);
    echo json_encode($stmt->fetchAll());
    exit;
}

include '../includes/dashboard-head.php';
?>
<body class="bg-slate-50 text-slate-900 flex h-screen overflow-hidden" x-data="{ mobileMenuOpen: false, selectedTicket: null, messages: [], loadingMessages: false }">
    <?php include '../includes/sidebar.php'; ?>
    <main class="flex-1 flex flex-col min-w-0 overflow-hidden">
        <?php include '../includes/topbar.php'; ?>
        <div class="flex-1 overflow-y-auto p-4 sm:p-8">
            <div class="max-w-6xl mx-auto">
                <div class="mb-8">
                    <h1 class="text-2xl sm:text-3xl font-bold text-slate-900 mb-2">Support Center</h1>
                    <p class="text-slate-500">Communicate directly with our support team to resolve any issues</p>
                </div>

                <div class="grid lg:grid-cols-3 gap-8">
                    <div class="lg:col-span-1 space-y-6">
                        <div class="bg-white p-8 rounded-[2rem] border border-slate-200 shadow-sm h-fit">
                            <h3 class="font-bold text-lg text-slate-900 mb-6 flex items-center gap-2">
                                <i data-lucide="plus-circle" class="text-indigo-600"></i> New Ticket
                            </h3>
                            <form method="POST" class="space-y-6">
                                <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                                <input type="hidden" name="action" value="new_ticket">
                                <div>
                                    <label class="block text-xs font-bold text-slate-500 uppercase mb-2">Subject</label>
                                    <input type="text" name="subject" required placeholder="What do you need help with?" class="w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-xl focus:ring-2 focus:ring-indigo-500/20 outline-none">
                                </div>
                                <div>
                                    <label class="block text-xs font-bold text-slate-500 uppercase mb-2">Priority</label>
                                    <select name="priority" required class="w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-xl outline-none focus:ring-2 focus:ring-indigo-500/20">
                                        <option value="low">Low</option>
                                        <option value="medium" selected>Medium</option>
                                        <option value="high">High</option>
                                        <option value="critical">Critical</option>
                                    </select>
                                </div>
                                <div>
                                    <label class="block text-xs font-bold text-slate-500 uppercase mb-2">Message</label>
                                    <textarea name="message" required rows="5" placeholder="Detailed description of your issue..." class="w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-xl focus:ring-2 focus:ring-indigo-500/20 outline-none"></textarea>
                                </div>
                                <button type="submit" class="w-full bg-indigo-600 text-white py-4 rounded-xl font-bold hover:bg-indigo-700 transition-all shadow-lg shadow-indigo-100">Send Ticket</button>
                            </form>
                        </div>

                        <div class="bg-indigo-600 p-8 rounded-[2rem] text-white shadow-xl shadow-indigo-200">
                            <i data-lucide="help-circle" size="32" class="mb-4 opacity-80"></i>
                            <h3 class="font-bold mb-2">Knowledge Base</h3>
                            <p class="text-sm text-indigo-100 mb-6">Find quick answers to common questions in our detailed documentation.</p>
                            <a href="../docs.php" class="inline-block bg-white/20 hover:bg-white/30 px-6 py-3 rounded-xl text-sm font-bold transition-all">Browse FAQs</a>
                        </div>
                    </div>

                    <div class="lg:col-span-2">
                        <div class="bg-white rounded-[2rem] border border-slate-200 shadow-sm overflow-hidden">
                            <div class="p-6 border-b border-slate-100 font-bold text-slate-900 bg-slate-50/50">My Support History</div>
                            <div class="overflow-x-auto">
                                <table class="w-full text-left">
                                    <thead>
                                        <tr class="bg-slate-50 border-b border-slate-100">
                                            <th class="px-4 sm:px-6 py-4 text-xs font-bold text-slate-500 uppercase tracking-wider">Subject</th>
                                            <th class="px-4 sm:px-6 py-4 text-xs font-bold text-slate-500 uppercase tracking-wider">Status</th>
                                            <th class="hidden md:table-cell px-6 py-4 text-xs font-bold text-slate-500 uppercase tracking-wider">Date</th>
                                            <th class="px-4 sm:px-6 py-4 text-xs font-bold text-slate-500 uppercase tracking-wider">Action</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-slate-100">
                                        <?php foreach ($tickets as $t): ?>
                                            <tr class="hover:bg-slate-50/50 transition-colors">
                                                <td class="px-4 sm:px-6 py-4 text-sm font-bold text-slate-900 truncate max-w-[150px] sm:max-w-none"><?php echo $t['subject']; ?></td>
                                                <td class="px-4 sm:px-6 py-4">
                                                    <span class="px-2 py-0.5 rounded-lg text-[10px] font-bold uppercase tracking-wider <?php echo $t['status'] === 'open' ? 'bg-indigo-50 text-indigo-700' : ($t['status'] === 'resolved' ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-600'); ?>"><?php echo $t['status']; ?></span>
                                                </td>
                                                <td class="hidden md:table-cell px-6 py-4 text-xs text-slate-500 font-medium"><?php echo date('M d, Y', strtotime($t['created_at'])); ?></td>
                                                <td class="px-4 sm:px-6 py-4">
                                                    <button
                                                        @click="selectedTicket = <?php echo htmlspecialchars(json_encode($t)); ?>; loadingMessages = true; fetch('?action=get_messages&id=' + selectedTicket.id).then(r => r.json()).then(data => { messages = data; loadingMessages = false; })"
                                                        class="text-indigo-600 hover:underline text-xs font-bold"
                                                    >View Thread</button>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                        <?php if (empty($tickets)): ?>
                                            <tr>
                                                <td colspan="4" class="px-6 py-20 text-center">
                                                    <div class="flex flex-col items-center gap-2 opacity-30">
                                                        <i data-lucide="ticket" class="w-12 h-12"></i>
                                                        <p class="font-bold">No support tickets found</p>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Ticket Messages Modal -->
        <div x-show="selectedTicket" x-cloak class="fixed inset-0 bg-slate-900/50 backdrop-blur-sm flex items-center justify-center z-[100] p-4">
            <div class="bg-white rounded-[2rem] w-full max-w-lg overflow-hidden shadow-2xl border border-slate-200 flex flex-col max-h-[80vh]">
                <div class="p-6 border-b border-slate-100 flex justify-between items-center bg-slate-50/50">
                    <div>
                        <h3 class="font-bold text-slate-900" x-text="selectedTicket?.subject"></h3>
                        <p class="text-xs text-slate-500 mt-1" x-text="'Status: ' + selectedTicket?.status"></p>
                    </div>
                    <button @click="selectedTicket = null" class="p-2 text-slate-500 hover:text-slate-900 hover:bg-slate-100 rounded-full transition-all">
                        <i data-lucide="x" class="w-5 h-5"></i>
                    </button>
                </div>
                <div class="flex-1 overflow-y-auto p-8 space-y-6 bg-slate-50/30">
                    <div x-show="loadingMessages" class="flex justify-center py-12">
                        <i data-lucide="refresh-ccw" class="animate-spin text-indigo-600 w-8 h-8"></i>
                    </div>
                    <template x-for="msg in messages" :key="msg.id">
                        <div :class="msg.is_admin == 1 ? 'flex flex-col items-start' : 'flex flex-col items-end'">
                            <div :class="msg.is_admin == 1 ? 'bg-white border border-slate-200 text-slate-700' : 'bg-indigo-600 text-white'" class="max-w-[80%] p-4 rounded-2xl shadow-sm">
                                <p class="text-sm" x-text="msg.message"></p>
                            </div>
                            <p class="text-[10px] text-slate-400 mt-1 font-medium" x-text="new Date(msg.created_at).toLocaleString()"></p>
                        </div>
                    </template>
                </div>
                <?php if ($user['role'] !== 'admin'): // Merchants can reply too ?>
                <div class="p-6 border-t border-slate-100 bg-white">
                    <form method="POST" class="flex gap-2">
                        <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                        <input type="hidden" name="action" value="new_ticket_message">
                        <input type="hidden" name="ticket_id" :value="selectedTicket?.id">
                        <input type="text" name="message" required placeholder="Type a reply..." class="flex-1 px-4 py-3 bg-slate-50 border border-slate-200 rounded-xl outline-none focus:ring-2 focus:ring-indigo-500/20 text-sm">
                        <button type="submit" class="bg-indigo-600 text-white p-3 rounded-xl hover:bg-indigo-700 transition-all">
                            <i data-lucide="send" class="w-5 h-5"></i>
                        </button>
                    </form>
                </div>
                <?php endif; ?>
            </div>
        </div>
    <?php include "../includes/merchant-quick-actions.php"; ?>
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