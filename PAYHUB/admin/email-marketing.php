<?php
// php-version/admin/email-marketing.php
require_once '../includes/functions.php';

if (!isLoggedIn() || !isAdmin()) redirect('../login.php');

$user = getAuthUser();
$pageTitle = 'Email Marketing - Admin Hub';

$db = Database::connect();

// Handle Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'save_template') {
        $name = sanitize($_POST['name']);
        $subject = sanitize($_POST['subject']);
        $body = $_POST['body'];
        $stmt = $db->prepare("INSERT INTO email_templates (name, subject, body) VALUES (?, ?, ?)");
        $stmt->execute([$name, $subject, $body]);
        $success_msg = "Email template saved.";
    } elseif ($_POST['action'] === 'edit_template') {
        $id = (int)$_POST['template_id'];
        $name = sanitize($_POST['name']);
        $subject = sanitize($_POST['subject']);
        $body = $_POST['body'];
        $stmt = $db->prepare("UPDATE email_templates SET name = ?, subject = ?, body = ? WHERE id = ?");
        $stmt->execute([$name, $subject, $body, $id]);
        $success_msg = "Email template updated.";
    } elseif ($_POST['action'] === 'delete_template') {
        $id = (int)$_POST['template_id'];
        $stmt = $db->prepare("DELETE FROM email_templates WHERE id = ?");
        $stmt->execute([$id]);
        $success_msg = "Template deleted.";
    } elseif ($_POST['action'] === 'delete_contact') {
        $id = (int)$_POST['contact_id'];
        $stmt = $db->prepare("DELETE FROM marketing_contacts WHERE id = ?");
        $stmt->execute([$id]);
        $success_msg = "Contact deleted.";
    } elseif ($_POST['action'] === 'delete_group') {
        $id = (int)$_POST['group_id'];
        $stmt = $db->prepare("DELETE FROM marketing_groups WHERE id = ?");
        $stmt->execute([$id]);
        $success_msg = "Group deleted.";
    } elseif ($_POST['action'] === 'add_contact') {
        $email = sanitize($_POST['email']);
        $name = sanitize($_POST['full_name']);
        $group_id = (int)$_POST['group_id'];
        $stmt = $db->prepare("INSERT INTO marketing_contacts (email, full_name, group_id) VALUES (?, ?, ?)");
        $stmt->execute([$email, $name, $group_id]);
        $success_msg = "Marketing contact added.";
    } elseif ($_POST['action'] === 'create_group') {
        $name = sanitize($_POST['group_name']);
        $stmt = $db->prepare("INSERT INTO marketing_groups (name) VALUES (?)");
        $stmt->execute([$name]);
        $groupId = $db->lastInsertId();

        if (!empty($_POST['emails'])) {
            $emails = preg_split("/[\s,]+/", $_POST['emails']);
            foreach ($emails as $email) {
                $email = trim($email);
                if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $stmt = $db->prepare("INSERT IGNORE INTO marketing_contacts (email, group_id) VALUES (?, ?)");
                    $stmt->execute([$email, $groupId]);
                }
            }
        }
        $success_msg = "Campaign group created with contacts.";
    } elseif ($_POST['action'] === 'send_bulk') {
        $template_id = (int)$_POST['template_id'];
        $target = $_POST['target']; // 'merchants' or 'external'

        $stmt = $db->prepare("SELECT subject, body FROM email_templates WHERE id = ?");
        $stmt->execute([$template_id]);
        $tpl = $stmt->fetch();

        $recipients = [];
        if ($target === 'merchants') {
            $recipients = $db->query("SELECT email, full_name FROM users WHERE role = 'merchant'")->fetchAll();
        } elseif (strpos($target, 'group_') === 0) {
            $groupId = (int)str_replace('group_', '', $target);
            $stmt = $db->prepare("SELECT email, full_name FROM marketing_contacts WHERE group_id = ?");
            $stmt->execute([$groupId]);
            $recipients = $stmt->fetchAll();
        }

        $sent_count = 0;
        foreach ($recipients as $r) {
            if (sendEmail($r['email'], $tpl['subject'], $tpl['body'])) {
                $sent_count++;
            }
        }
        $success_msg = "Bulk email sent to $sent_count recipients.";
    }
}

$templates = $db->query("SELECT * FROM email_templates ORDER BY created_at DESC")->fetchAll();
$contacts = $db->query("SELECT * FROM marketing_contacts ORDER BY created_at DESC")->fetchAll();

include '../includes/dashboard-head.php';
?>
<body class="bg-slate-50 text-slate-900 flex h-screen overflow-hidden" x-data>
    <?php include '../includes/sidebar.php'; ?>
    <main class="flex-1 flex flex-col min-w-0 overflow-hidden" x-data="{ showTpl: false, showContact: false, showSend: false, showGroup: false, showEditTpl: false, editingTpl: {id:null, name:'', subject:'', body:''} }">
        <?php include '../includes/topbar.php'; ?>
        <div class="flex-1 overflow-y-auto p-8">
            <?php if (isset($success_msg)): ?>
                <div class="mb-6 p-4 bg-emerald-50 text-emerald-700 rounded-2xl border border-emerald-100 font-medium"><?php echo $success_msg; ?></div>
            <?php endif; ?>

            <div class="mb-8 flex justify-between items-center">
                <div>
                    <h1 class="text-2xl font-bold text-slate-900 mb-2">Email Marketing</h1>
                    <p class="text-slate-500">Design templates and manage campaigns for merchants and external leads</p>
                </div>
                <div class="flex gap-3">
                    <button @click="showGroup = true" class="bg-white border border-slate-200 text-slate-700 px-6 py-2.5 rounded-xl text-sm font-bold flex items-center gap-2 shadow-sm"><i data-lucide="users" class="w-4 h-4"></i> Create Group</button>
                    <button @click="showSend = true" class="bg-indigo-600 text-white px-6 py-2.5 rounded-xl text-sm font-bold flex items-center gap-2 shadow-lg shadow-indigo-100"><i data-lucide="send" class="w-4 h-4"></i> Send Campaign</button>
                </div>
            </div>

            <div class="grid lg:grid-cols-3 gap-8">
                <div class="lg:col-span-2 space-y-8">
                    <div class="bg-white rounded-3xl border border-slate-200 shadow-sm overflow-hidden">
                        <div class="p-6 border-b border-slate-100 flex justify-between items-center bg-slate-50/50">
                            <h3 class="font-bold text-slate-900">Email Templates</h3>
                            <button @click="showTpl = true" class="text-xs font-bold text-indigo-600 hover:underline">+ New Template</button>
                        </div>
                        <div class="p-6 grid md:grid-cols-2 gap-4">
                            <?php foreach ($templates as $t): ?>
                                <div class="p-4 bg-slate-50 rounded-2xl border border-slate-100 hover:border-indigo-200 transition-all">
                                    <p class="font-bold text-slate-900 text-sm mb-1"><?php echo $t['name']; ?></p>
                                    <p class="text-xs text-slate-500 mb-4"><?php echo $t['subject']; ?></p>
                                    <div class="flex gap-2">
                                        <button @click="editingTpl = <?php echo htmlspecialchars(json_encode($t)); ?>; showEditTpl = true;" class="text-[10px] font-bold text-indigo-600 bg-white border border-slate-200 px-3 py-1 rounded-lg">Edit</button>
                                        <form method="POST" onsubmit="return confirm('Delete template?');" class="inline">
                                            <input type="hidden" name="action" value="delete_template">
                                            <input type="hidden" name="template_id" value="<?php echo $t['id']; ?>">
                                            <button type="submit" class="text-[10px] font-bold text-red-400">Delete</button>
                                        </form>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>

                <div class="lg:col-span-1 space-y-8">
                    <div class="bg-white rounded-3xl border border-slate-200 shadow-sm overflow-hidden">
                        <div class="p-6 border-b border-slate-100 flex justify-between items-center bg-slate-50/50">
                            <h3 class="font-bold text-slate-900">Campaign Groups</h3>
                        </div>
                        <div class="p-6 space-y-4">
                            <?php
                            $groups = $db->query("SELECT g.*, (SELECT COUNT(*) FROM marketing_contacts WHERE group_id = g.id) as contact_count FROM marketing_groups g ORDER BY created_at DESC")->fetchAll();
                            foreach ($groups as $g): ?>
                                <div class="p-4 bg-slate-50 rounded-2xl border border-slate-100 flex justify-between items-center group">
                                    <div>
                                        <p class="font-bold text-slate-900 text-sm"><?php echo $g['name']; ?></p>
                                        <p class="text-[10px] text-slate-400 font-medium"><?php echo $g['contact_count']; ?> contacts</p>
                                    </div>
                                    <form method="POST" onsubmit="return confirm('Delete group?');" class="opacity-0 group-hover:opacity-100 transition-all">
                                        <input type="hidden" name="action" value="delete_group">
                                        <input type="hidden" name="group_id" value="<?php echo $g['id']; ?>">
                                        <button type="submit" class="text-slate-300 hover:text-red-500"><i data-lucide="trash-2" class="w-3 h-3"></i></button>
                                    </form>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div class="bg-white rounded-3xl border border-slate-200 shadow-sm overflow-hidden">
                        <div class="p-6 border-b border-slate-100 flex justify-between items-center bg-slate-50/50">
                            <h3 class="font-bold text-slate-900">Recent Contacts</h3>
                            <button @click="showContact = true" class="text-xs font-bold text-indigo-600 hover:underline">+ Add</button>
                        </div>
                        <div class="p-6 space-y-3">
                            <?php foreach (array_slice($contacts, 0, 10) as $c): ?>
                                <div class="flex items-center justify-between">
                                    <div class="min-w-0">
                                        <p class="text-xs font-bold text-slate-900 truncate"><?php echo $c['full_name']; ?></p>
                                        <p class="text-[10px] text-slate-400 truncate"><?php echo $c['email']; ?></p>
                                    </div>
                                    <form method="POST" onsubmit="return confirm('Delete contact?');" class="inline">
                                        <input type="hidden" name="action" value="delete_contact">
                                        <input type="hidden" name="contact_id" value="<?php echo $c['id']; ?>">
                                        <button type="submit" class="text-slate-300 hover:text-red-500"><i data-lucide="trash-2" class="w-3 h-3"></i></button>
                                    </form>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Modals -->
        <div x-show="showEditTpl" x-cloak class="fixed inset-0 bg-slate-900/50 backdrop-blur-sm flex items-center justify-center z-[100] p-4">
            <div class="bg-white rounded-[2rem] w-full max-w-2xl overflow-hidden shadow-2xl border border-slate-200">
                <div class="p-6 border-b border-slate-100 flex justify-between items-center">
                    <h3 class="font-bold text-slate-900">Edit Template</h3>
                    <button @click="showEditTpl = false" class="p-2 text-slate-500 hover:text-slate-900 hover:bg-slate-100 rounded-full transition-all">
                        <i data-lucide="x" class="w-5 h-5"></i>
                    </button>
                </div>
                <div class="p-8">
                    <form method="POST" class="space-y-6">
                        <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                        <input type="hidden" name="action" value="edit_template">
                        <input type="hidden" name="template_id" :value="editingTpl.id">
                        <input type="text" name="name" x-model="editingTpl.name" required placeholder="Internal Template Name" class="w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-xl outline-none focus:ring-2 focus:ring-indigo-500/20 font-bold">
                        <input type="text" name="subject" x-model="editingTpl.subject" required placeholder="Email Subject Line" class="w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-xl outline-none focus:ring-2 focus:ring-indigo-500/20">
                        <textarea name="body" x-model="editingTpl.body" required rows="10" placeholder="HTML or Plain Text Message Body" class="w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-xl outline-none focus:ring-2 focus:ring-indigo-500/20 font-mono text-sm"></textarea>
                        <button type="submit" class="w-full bg-indigo-600 text-white py-4 rounded-xl font-bold hover:bg-indigo-700 transition-all">Update Template</button>
                    </form>
                </div>
            </div>
        </div>
        <div x-show="showTpl" x-cloak class="fixed inset-0 bg-slate-900/50 backdrop-blur-sm flex items-center justify-center z-[100] p-4">
            <div class="bg-white rounded-[2rem] w-full max-w-2xl overflow-hidden shadow-2xl border border-slate-200">
                <div class="p-6 border-b border-slate-100 flex justify-between items-center">
                    <h3 class="font-bold text-slate-900">Design Template</h3>
                    <button @click="showTpl = false" class="p-2 text-slate-500 hover:text-slate-900 hover:bg-slate-100 rounded-full transition-all">
                        <i data-lucide="x" class="w-5 h-5"></i>
                    </button>
                </div>
                <div class="p-8">
                    <form method="POST" class="space-y-6">
                        <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                        <input type="hidden" name="action" value="save_template">
                        <input type="text" name="name" required placeholder="Internal Template Name" class="w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-xl outline-none focus:ring-2 focus:ring-indigo-500/20 font-bold">
                        <input type="text" name="subject" required placeholder="Email Subject Line" class="w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-xl outline-none focus:ring-2 focus:ring-indigo-500/20">
                        <textarea name="body" required rows="10" placeholder="HTML or Plain Text Message Body" class="w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-xl outline-none focus:ring-2 focus:ring-indigo-500/20 font-mono text-sm"></textarea>
                        <button type="submit" class="w-full bg-indigo-600 text-white py-4 rounded-xl font-bold hover:bg-indigo-700 transition-all">Save Template</button>
                    </form>
                </div>
            </div>
        </div>

        <div x-show="showGroup" x-cloak class="fixed inset-0 bg-slate-900/50 backdrop-blur-sm flex items-center justify-center z-[100] p-4">
            <div class="bg-white rounded-[2rem] w-full max-w-md overflow-hidden shadow-2xl border border-slate-200">
                <div class="p-6 border-b border-slate-100 flex justify-between items-center">
                    <h3 class="font-bold text-slate-900">New Campaign Group</h3>
                    <button @click="showGroup = false" class="p-2 text-slate-500 hover:text-slate-900 hover:bg-slate-100 rounded-full transition-all">
                        <i data-lucide="x" class="w-5 h-5"></i>
                    </button>
                </div>
                <div class="p-8">
                    <form method="POST" class="space-y-6">
                        <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                        <input type="hidden" name="action" value="create_group">
                        <div>
                            <label class="block text-xs font-bold text-slate-500 uppercase mb-2">Group Name</label>
                            <input type="text" name="group_name" required placeholder="e.g. Q1 Newsletter Leads" class="w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-xl outline-none focus:ring-2 focus:ring-indigo-500/20 font-bold">
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-slate-500 uppercase mb-2">Emails (Comma or Newline separated)</label>
                            <textarea name="emails" rows="6" placeholder="john@example.com, jane@example.com" class="w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-xl outline-none focus:ring-2 focus:ring-indigo-500/20 font-mono text-xs"></textarea>
                        </div>
                        <button type="submit" class="w-full bg-indigo-600 text-white py-4 rounded-xl font-bold hover:bg-indigo-700 transition-all">Create Group & Import</button>
                    </form>
                </div>
            </div>
        </div>

        <div x-show="showContact" x-cloak class="fixed inset-0 bg-slate-900/50 backdrop-blur-sm flex items-center justify-center z-[100] p-4">
            <div class="bg-white rounded-[2rem] w-full max-w-sm overflow-hidden shadow-2xl border border-slate-200">
                <div class="p-6 border-b border-slate-100 flex justify-between items-center">
                    <h3 class="font-bold text-slate-900">Add Contact</h3>
                    <button @click="showContact = false" class="p-2 text-slate-500 hover:text-slate-900 hover:bg-slate-100 rounded-full transition-all">
                        <i data-lucide="x" class="w-5 h-5"></i>
                    </button>
                </div>
                <div class="p-8">
                    <form method="POST" class="space-y-6">
                        <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                        <input type="hidden" name="action" value="add_contact">
                        <input type="text" name="full_name" required placeholder="Contact Name" class="w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-xl outline-none focus:ring-2 focus:ring-indigo-500/20">
                        <input type="email" name="email" required placeholder="Email Address" class="w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-xl outline-none focus:ring-2 focus:ring-indigo-500/20">
                        <div>
                            <label class="block text-xs font-bold text-slate-500 uppercase mb-2">Select Group</label>
                            <select name="group_id" class="w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-xl outline-none focus:ring-2 focus:ring-indigo-500/20">
                                <?php foreach($groups as $g): ?>
                                    <option value="<?php echo $g['id']; ?>"><?php echo $g['name']; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <button type="submit" class="w-full bg-indigo-600 text-white py-4 rounded-xl font-bold hover:bg-indigo-700 transition-all">Save Contact</button>
                    </form>
                </div>
            </div>
        </div>

        <div x-show="showSend" x-cloak class="fixed inset-0 bg-slate-900/50 backdrop-blur-sm flex items-center justify-center z-[100] p-4">
            <div class="bg-white rounded-[2rem] w-full max-w-md overflow-hidden shadow-2xl border border-slate-200">
                <div class="p-6 border-b border-slate-100 flex justify-between items-center">
                    <h3 class="font-bold text-slate-900">Send Campaign</h3>
                    <button @click="showSend = false" class="p-2 text-slate-500 hover:text-slate-900 hover:bg-slate-100 rounded-full transition-all">
                        <i data-lucide="x" class="w-5 h-5"></i>
                    </button>
                </div>
                <div class="p-8">
                    <form method="POST" class="space-y-6">
                        <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                        <input type="hidden" name="action" value="send_bulk">
                        <div>
                            <label class="block text-xs font-bold text-slate-500 uppercase mb-2">Select Template</label>
                            <select name="template_id" required class="w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-xl focus:ring-2 focus:ring-indigo-500/20 outline-none">
                                <?php foreach($templates as $t): ?>
                                    <option value="<?php echo $t['id']; ?>"><?php echo $t['name']; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-slate-500 uppercase mb-2">Target Audience</label>
                            <select name="target" required class="w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-xl focus:ring-2 focus:ring-indigo-500/20 outline-none">
                                <option value="merchants">All Active Merchants</option>
                                <?php foreach($groups as $g): ?>
                                    <option value="group_<?php echo $g['id']; ?>"><?php echo $g['name']; ?> (<?php echo $g['contact_count']; ?>)</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="p-4 bg-amber-50 rounded-2xl border border-amber-100">
                            <p class="text-[10px] text-amber-700 font-medium">Emails will be delivered individually to respect recipient privacy.</p>
                        </div>
                        <button type="submit" class="w-full bg-indigo-600 text-white py-4 rounded-xl font-bold hover:bg-indigo-700 transition-all shadow-lg shadow-indigo-100">Launch Campaign</button>
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