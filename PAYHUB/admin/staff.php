<?php
// php-version/admin/staff.php
require_once '../includes/functions.php';

if (!isLoggedIn() || !isAdmin()) redirect('../login.php');

$user = getAuthUser();
$pageTitle = 'Staff Management - Admin Hub';

$db = Database::connect();

// Handle Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'edit_role') {
        $id = (int)$_POST['role_id'];
        $name = sanitize($_POST['role_name']);
        $perms = json_encode($_POST['permissions'] ?? []);
        $stmt = $db->prepare("UPDATE staff_roles SET name = ?, permissions = ? WHERE id = ?");
        $stmt->execute([$name, $perms, $id]);
        $success_msg = "Staff role updated successfully.";
    } elseif ($_POST['action'] === 'delete_role') {
        $id = (int)$_POST['role_id'];
        // Check for assigned staff
        $stmt = $db->prepare("SELECT COUNT(*) as count FROM staff_users WHERE role_id = ?");
        $stmt->execute([$id]);
        if ($stmt->fetch()['count'] > 0) {
            $error_msg = "Cannot delete role: There are staff members assigned to this role.";
        } else {
            $stmt = $db->prepare("DELETE FROM staff_roles WHERE id = ?");
            $stmt->execute([$id]);
            $success_msg = "Staff role deleted.";
        }
    } elseif ($_POST['action'] === 'add_staff') {
        $email = sanitize($_POST['email']);
        $full_name = sanitize($_POST['full_name']);
        $password = password_hash($_POST['password'], PASSWORD_DEFAULT);
        $role_id = (int)$_POST['role_id'];

        $stmt = $db->prepare("INSERT INTO staff_users (email, full_name, password_hash, role_id) VALUES (?, ?, ?, ?)");
        $stmt->execute([$email, $full_name, $password, $role_id]);
        $success_msg = "Staff member added successfully.";
    } elseif ($_POST['action'] === 'edit_staff') {
        $id = (int)$_POST['staff_id'];
        $email = sanitize($_POST['email']);
        $full_name = sanitize($_POST['full_name']);
        $role_id = (int)$_POST['role_id'];

        if (!empty($_POST['password'])) {
            $password = password_hash($_POST['password'], PASSWORD_DEFAULT);
            $stmt = $db->prepare("UPDATE staff_users SET email = ?, full_name = ?, password_hash = ?, role_id = ? WHERE id = ?");
            $stmt->execute([$email, $full_name, $password, $role_id, $id]);
        } else {
            $stmt = $db->prepare("UPDATE staff_users SET email = ?, full_name = ?, role_id = ? WHERE id = ?");
            $stmt->execute([$email, $full_name, $role_id, $id]);
        }
        $success_msg = "Staff member updated successfully.";
    } elseif ($_POST['action'] === 'delete_staff') {
        $id = (int)$_POST['staff_id'];
        $stmt = $db->prepare("DELETE FROM staff_users WHERE id = ?");
        $stmt->execute([$id]);
        $success_msg = "Staff member deleted.";
    } elseif ($_POST['action'] === 'add_role') {
        $name = sanitize($_POST['role_name']);
        $perms = json_encode($_POST['permissions'] ?? []);
        $stmt = $db->prepare("INSERT INTO staff_roles (name, permissions) VALUES (?, ?)");
        $stmt->execute([$name, $perms]);
        $success_msg = "Staff role created successfully.";
    }
}

$stmt = $db->query("SELECT s.*, r.name as role_name FROM staff_users s LEFT JOIN staff_roles r ON s.role_id = r.id ORDER BY s.created_at DESC");
$staff = $stmt->fetchAll();

$stmt = $db->query("SELECT * FROM staff_roles ORDER BY name ASC");
$roles = $stmt->fetchAll();

include '../includes/dashboard-head.php';
?>
<body class="bg-slate-50 text-slate-900 flex h-screen overflow-hidden" x-data>
    <?php include '../includes/sidebar.php'; ?>
    <main class="flex-1 flex flex-col min-w-0 overflow-hidden" x-data="{
        showAdd: false,
        showAddRole: false,
        showEditRole: false,
        showEditStaff: false,
        editingRole: {id:null, name:'', permissions:[]},
        editingStaff: {id:null, full_name:'', email:'', role_id:null}
    }">
        <?php include '../includes/topbar.php'; ?>
        <div class="flex-1 overflow-y-auto p-4 sm:p-8">
            <?php if (isset($success_msg)): ?>
                <div class="mb-6 p-4 bg-emerald-50 text-emerald-700 rounded-2xl border border-emerald-100 font-medium">
                    <?php echo $success_msg; ?>
                </div>
            <?php endif; ?>
            <?php if (isset($error_msg)): ?>
                <div class="mb-6 p-4 bg-red-50 text-red-700 rounded-2xl border border-red-100 font-medium">
                    <?php echo $error_msg; ?>
                </div>
            <?php endif; ?>

            <div class="mb-8 flex flex-col md:flex-row md:items-center justify-between gap-4">
                <div>
                    <h1 class="text-2xl font-bold text-slate-900 mb-2">Staff Management</h1>
                    <p class="text-slate-500">Create roles and manage staff access with granular permissions</p>
                </div>
                <div class="flex gap-3">
                    <button @click="showAddRole = true" class="bg-white border border-slate-200 text-slate-700 px-6 py-2.5 rounded-xl text-sm font-bold flex items-center gap-2 hover:bg-slate-50 transition-all shadow-sm">
                        <i data-lucide="shield" class="w-4 h-4"></i> Create Role
                    </button>
                    <button @click="showAdd = true" class="bg-indigo-600 text-white px-6 py-2.5 rounded-xl text-sm font-bold flex items-center gap-2 hover:bg-indigo-700 transition-all shadow-lg shadow-indigo-100">
                        <i data-lucide="user-plus" class="w-4 h-4"></i> Add Staff
                    </button>
                </div>
            </div>

            <div class="grid lg:grid-cols-3 gap-8 mb-8">
                <div class="lg:col-span-2 bg-white rounded-3xl border border-slate-200 shadow-sm overflow-hidden">
                <div class="p-6 border-b border-slate-100 font-bold">Staff Members</div>
                <div class="overflow-x-auto">
                    <table class="w-full text-left">
                        <thead>
                            <tr class="bg-slate-50">
                                <th class="px-4 sm:px-6 py-4 text-xs font-bold text-slate-500 uppercase">Name</th>
                                <th class="hidden sm:table-cell px-6 py-4 text-xs font-bold text-slate-500 uppercase">Email</th>
                                <th class="px-4 sm:px-6 py-4 text-xs font-bold text-slate-500 uppercase">Role</th>
                                <th class="px-4 sm:px-6 py-4 text-xs font-bold text-slate-500 uppercase">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <?php foreach ($staff as $s): ?>
                                <tr class="hover:bg-slate-50/50 transition-colors">
                                    <td class="px-4 sm:px-6 py-4 text-sm font-bold text-slate-900 truncate max-w-[120px] sm:max-w-none"><?php echo $s['full_name']; ?></td>
                                    <td class="hidden sm:table-cell px-6 py-4 text-sm text-slate-700"><?php echo $s['email']; ?></td>
                                    <td class="px-4 sm:px-6 py-4">
                                        <span class="px-2 py-0.5 rounded-lg text-[10px] font-bold uppercase tracking-wider bg-indigo-50 text-indigo-700">
                                            <?php echo $s['role_name'] ?: 'No Role'; ?>
                                        </span>
                                    </td>
                                    <td class="px-6 py-4">
                                        <div class="flex items-center gap-3">
                                            <button @click="editingStaff = {id:<?php echo $s['id']; ?>, full_name:'<?php echo addslashes($s['full_name']); ?>', email:'<?php echo addslashes($s['email']); ?>', role_id:<?php echo $s['role_id'] ?: 'null'; ?>}; showEditStaff = true;" class="text-slate-400 hover:text-indigo-600" title="Edit Staff">
                                                <i data-lucide="edit-2" class="w-4 h-4"></i>
                                            </button>
                                            <form method="POST" onsubmit="return confirm('Delete this staff member?');" class="inline">
                                                <input type="hidden" name="action" value="delete_staff">
                                                <input type="hidden" name="staff_id" value="<?php echo $s['id']; ?>">
                                                <button type="submit" class="text-slate-400 hover:text-red-600">
                                                    <i data-lucide="trash-2" class="w-4 h-4"></i>
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (empty($staff)): ?>
                                <tr><td colspan="4" class="px-6 py-12 text-center text-slate-500 font-medium">No staff members found.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="lg:col-span-1 bg-white rounded-3xl border border-slate-200 shadow-sm overflow-hidden h-fit">
                <div class="p-6 border-b border-slate-100 font-bold">Staff Roles</div>
                <div class="p-6 space-y-4">
                    <?php foreach ($roles as $r): ?>
                        <div class="p-4 bg-slate-50 rounded-2xl border border-slate-100 flex justify-between items-center group">
                            <div>
                                <p class="font-bold text-slate-900 text-sm"><?php echo $r['name']; ?></p>
                                <p class="text-[10px] text-slate-400 font-medium"><?php echo count(json_decode($r['permissions'] ?: '[]')); ?> permissions set</p>
                            </div>
                            <div class="flex items-center gap-2 opacity-0 group-hover:opacity-100 transition-all">
                                <button @click="editingRole = {id:<?php echo $r['id']; ?>, name:'<?php echo addslashes($r['name']); ?>', permissions:<?php echo $r['permissions'] ?: '[]'; ?>}; showEditRole = true;" class="text-slate-400 hover:text-indigo-600">
                                    <i data-lucide="edit-2" class="w-4 h-4"></i>
                                </button>
                                <form method="POST" onsubmit="return confirm('Delete this role?');" class="inline">
                                    <input type="hidden" name="action" value="delete_role">
                                    <input type="hidden" name="role_id" value="<?php echo $r['id']; ?>">
                                    <button type="submit" class="text-slate-400 hover:text-red-600">
                                        <i data-lucide="trash-2" class="w-4 h-4"></i>
                                    </button>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                    <?php if (empty($roles)): ?>
                        <p class="text-center text-xs text-slate-400 py-4 italic">No roles created yet.</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div x-show="showAddRole" x-cloak class="fixed inset-0 bg-slate-900/50 backdrop-blur-sm flex items-center justify-center z-[100] p-4">
            <div class="bg-white rounded-[2rem] w-full max-w-lg overflow-hidden shadow-2xl border border-slate-200">
                <div class="p-6 border-b border-slate-100 flex justify-between items-center bg-slate-50/50">
                    <h3 class="font-bold text-slate-900">Create Staff Role</h3>
                    <button @click="showAddRole = false" class="p-2 text-slate-500 hover:text-slate-900 hover:bg-slate-100 rounded-full transition-all">
                        <i data-lucide="x" class="w-5 h-5"></i>
                    </button>
                </div>
                <div class="p-8">
                    <form method="POST" class="space-y-6">
                        <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                        <input type="hidden" name="action" value="add_role">
                        <div>
                            <label class="block text-xs font-bold text-slate-500 uppercase mb-2">Role Name</label>
                            <input type="text" name="role_name" required placeholder="e.g. Support Manager" class="w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-xl outline-none focus:ring-2 focus:ring-indigo-500/20">
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-slate-500 uppercase mb-4 tracking-wider">Permissions</label>
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                <?php
                                $perms = ['Manage Merchants', 'Process Payouts', 'Review KYC', 'Manage Blog', 'System Settings', 'Support Desk', 'View Reports', 'Webhook Logs'];
                                foreach($perms as $p):
                                ?>
                                    <label class="flex items-center gap-3 p-3 bg-slate-50 rounded-xl border border-slate-100 cursor-pointer hover:border-indigo-200 transition-all">
                                        <input type="checkbox" name="permissions[]" value="<?php echo $p; ?>" class="rounded border-slate-300 text-indigo-600 focus:ring-indigo-500">
                                        <span class="text-xs font-medium text-slate-700"><?php echo $p; ?></span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <button type="submit" class="w-full bg-indigo-600 text-white py-4 rounded-xl font-bold hover:bg-indigo-700 transition-all shadow-lg shadow-indigo-100">Create Role</button>
                    </form>
                </div>
            </div>
        </div>

        <!-- Edit Role Modal -->
        <div x-show="showEditRole" x-cloak class="fixed inset-0 bg-slate-900/50 backdrop-blur-sm flex items-center justify-center z-[100] p-4">
            <div class="bg-white rounded-[2rem] w-full max-w-lg overflow-hidden shadow-2xl border border-slate-200">
                <div class="p-6 border-b border-slate-100 flex justify-between items-center bg-slate-50/50">
                    <h3 class="font-bold text-slate-900">Edit Staff Role</h3>
                    <button @click="showEditRole = false" class="p-2 text-slate-500 hover:text-slate-900 hover:bg-slate-100 rounded-full transition-all">
                        <i data-lucide="x" class="w-5 h-5"></i>
                    </button>
                </div>
                <div class="p-8">
                    <form method="POST" class="space-y-6">
                        <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                        <input type="hidden" name="action" value="edit_role">
                        <input type="hidden" name="role_id" :value="editingRole.id">
                        <div>
                            <label class="block text-xs font-bold text-slate-500 uppercase mb-2">Role Name</label>
                            <input type="text" name="role_name" x-model="editingRole.name" required class="w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-xl outline-none focus:ring-2 focus:ring-indigo-500/20">
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-slate-500 uppercase mb-4 tracking-wider">Permissions</label>
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                <?php
                                foreach($perms as $p):
                                ?>
                                    <label class="flex items-center gap-3 p-3 bg-slate-50 rounded-xl border border-slate-100 cursor-pointer hover:border-indigo-200 transition-all">
                                        <input type="checkbox" name="permissions[]" value="<?php echo $p; ?>" :checked="editingRole.permissions.includes('<?php echo $p; ?>')" class="rounded border-slate-300 text-indigo-600 focus:ring-indigo-500">
                                        <span class="text-xs font-medium text-slate-700"><?php echo $p; ?></span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <button type="submit" class="w-full bg-indigo-600 text-white py-4 rounded-xl font-bold hover:bg-indigo-700 transition-all shadow-lg shadow-indigo-100">Update Role</button>
                    </form>
                </div>
            </div>
        </div>

        <!-- Add Staff Modal -->
        <div x-show="showAdd" x-cloak class="fixed inset-0 bg-slate-900/50 backdrop-blur-sm flex items-center justify-center z-[100] p-4">
            <div class="bg-white rounded-[2rem] w-full max-w-md overflow-hidden shadow-2xl border border-slate-200">
                <div class="p-6 border-b border-slate-100 flex justify-between items-center bg-slate-50/50">
                    <h3 class="font-bold text-slate-900">Add New Staff Member</h3>
                    <button @click="showAdd = false" class="p-2 text-slate-500 hover:text-slate-900 hover:bg-slate-100 rounded-full transition-all">
                        <i data-lucide="x" class="w-5 h-5"></i>
                    </button>
                </div>
                <div class="p-8">
                    <form method="POST" class="space-y-6">
                        <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                        <input type="hidden" name="action" value="add_staff">
                        <div>
                            <label class="block text-xs font-bold text-slate-500 uppercase mb-2">Full Name</label>
                            <input type="text" name="full_name" required class="w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-xl outline-none focus:ring-2 focus:ring-indigo-500/20">
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-slate-500 uppercase mb-2">Email Address</label>
                            <input type="email" name="email" required class="w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-xl outline-none focus:ring-2 focus:ring-indigo-500/20">
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-slate-500 uppercase mb-2">Temporary Password</label>
                            <input type="password" name="password" required class="w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-xl outline-none focus:ring-2 focus:ring-indigo-500/20">
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-slate-500 uppercase mb-2">Assign Role</label>
                            <select name="role_id" required class="w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-xl outline-none focus:ring-2 focus:ring-indigo-500/20">
                                <option value="">Select a role...</option>
                                <?php foreach ($roles as $r): ?>
                                    <option value="<?php echo $r['id']; ?>"><?php echo $r['name']; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <button type="submit" class="w-full bg-indigo-600 text-white py-4 rounded-xl font-bold hover:bg-indigo-700 transition-all shadow-lg shadow-indigo-100">Create Account</button>
                    </form>
                </div>
            </div>
        </div>

        <!-- Edit Staff Modal -->
        <div x-show="showEditStaff" x-cloak class="fixed inset-0 bg-slate-900/50 backdrop-blur-sm flex items-center justify-center z-[100] p-4">
            <div class="bg-white rounded-[2rem] w-full max-w-md overflow-hidden shadow-2xl border border-slate-200">
                <div class="p-6 border-b border-slate-100 flex justify-between items-center bg-slate-50/50">
                    <h3 class="font-bold text-slate-900">Edit Staff Member</h3>
                    <button @click="showEditStaff = false" class="p-2 text-slate-500 hover:text-slate-900 hover:bg-slate-100 rounded-full transition-all">
                        <i data-lucide="x" class="w-5 h-5"></i>
                    </button>
                </div>
                <div class="p-8">
                    <form method="POST" class="space-y-6">
                        <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                        <input type="hidden" name="action" value="edit_staff">
                        <input type="hidden" name="staff_id" :value="editingStaff.id">
                        <div>
                            <label class="block text-xs font-bold text-slate-500 uppercase mb-2">Full Name</label>
                            <input type="text" name="full_name" x-model="editingStaff.full_name" required class="w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-xl outline-none focus:ring-2 focus:ring-indigo-500/20">
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-slate-500 uppercase mb-2">Email Address</label>
                            <input type="email" name="email" x-model="editingStaff.email" required class="w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-xl outline-none focus:ring-2 focus:ring-indigo-500/20">
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-slate-500 uppercase mb-2">New Password (leave blank to keep current)</label>
                            <input type="password" name="password" class="w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-xl outline-none focus:ring-2 focus:ring-indigo-500/20">
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-slate-500 uppercase mb-2">Assign Role</label>
                            <select name="role_id" x-model="editingStaff.role_id" required class="w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-xl outline-none focus:ring-2 focus:ring-indigo-500/20">
                                <option value="">Select a role...</option>
                                <?php foreach ($roles as $r): ?>
                                    <option value="<?php echo $r['id']; ?>"><?php echo $r['name']; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <button type="submit" class="w-full bg-indigo-600 text-white py-4 rounded-xl font-bold hover:bg-indigo-700 transition-all shadow-lg shadow-indigo-100">Update Account</button>
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
