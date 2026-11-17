<?php
// php-version/admin/compliance.php
require_once '../includes/functions.php';

if (!isLoggedIn() || !isAdmin()) redirect('../login.php');

$user = getAuthUser();
$pageTitle = 'Compliance Queue - Admin Hub';

$db = Database::connect();

// Handle Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $error_msg = "Invalid security token.";
    } elseif ($_POST['action'] === 'process_kyc') {
        $merchantId = (int)$_POST['merchant_id'];
        $status = (int)$_POST['status'];
        $notes = sanitize($_POST['notes']);
        $pin = $_POST['security_pin'] ?? '';

        if (empty($user['security_pin'])) {
            $error_msg = "Security PIN not set. Please set it in Security Settings.";
        } elseif (!password_verify($pin, $user['security_pin'])) {
            $error_msg = "Invalid Security PIN.";
        } else {

        $db->beginTransaction();
        try {
            // 1. Update KYC status and notes
            // If approved (status = 1), also turn off test mode
            $test_mode_sql = ($status == 1) ? ", is_test_mode = 0" : "";
            $stmt = $db->prepare("UPDATE users SET is_kyc_verified = ?, kyc_notes = ? $test_mode_sql WHERE id = ?");
            $stmt->execute([$status, $notes, $merchantId]);

            // 2. Fetch merchant details for notification
            $stmt = $db->prepare("SELECT email, business_name FROM users WHERE id = ?");
            $stmt->execute([$merchantId]);
            $m = $stmt->fetch();

            if (!$m) throw new Exception("Merchant not found during processing.");

            $db->commit();

            // 3. Notify Merchant (Outside transaction to prevent long holds if SMTP is slow)
            $status_text = $status == 1 ? 'Approved' : 'Rejected';
            try {
                // Ensure email exists before trying to send
                if (!empty($m['email'])) {
                    sendEmail($m['email'], "KYC Status: $status_text", "
                        <div style='font-family: sans-serif; max-width: 600px;'>
                            <h2 style='color: #4f46e5;'>KYC Update from Payhub</h2>
                            <p>Hello <strong>{$m['business_name']}</strong>,</p>
                            <p>Your business compliance verification has been <strong>$status_text</strong>.</p>
                            <div style='background: #f8fafc; padding: 20px; border-radius: 12px; margin: 20px 0;'>
                                <p style='margin: 0; font-size: 12px; color: #64748b; text-transform: uppercase; font-weight: bold;'>Admin Notes:</p>
                                <p style='margin-top: 10px; color: #1e293b;'>" . nl2br($notes) . "</p>
                            </div>
                            " . ($status == 1 ? "<p>Your account is now active and in <strong>Live Mode</strong>. You can start receiving real payments.</p>" : "<p>Please address the issues noted above and resubmit your documents.</p>") . "
                        </div>
                    ");
                }
            } catch (\Throwable $emailErr) {
                error_log("KYC Email Notification Failure (ID: $merchantId): " . $emailErr->getMessage());
            }

            header("Location: compliance.php?success=1");
            exit;
        } catch (Exception $e) {
            if ($db->inTransaction()) $db->rollBack();
            error_log("KYC Processing Crash (ID: $merchantId): " . $e->getMessage());
            $error_msg = "Critical error during processing: " . $e->getMessage();
        }
        }
    }
}

$success_msg = isset($_GET['success']) ? "Compliance processed successfully." : "";

// Pagination
$limit = 20;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $limit;

$status_filter = isset($_GET['status_filter']) ? (int)$_GET['status_filter'] : 2; // Default to '2' (Submitted / Pending Review)

if ($status_filter === 1) {
    $where_sql = "WHERE role = 'merchant' AND is_kyc_verified = 1 AND is_deleted = 0";
} elseif ($status_filter === 0) {
    $where_sql = "WHERE role = 'merchant' AND is_kyc_verified = 0 AND is_deleted = 0";
} elseif ($status_filter === 3) {
    $where_sql = "WHERE role = 'merchant' AND is_kyc_verified = 3 AND is_deleted = 0";
} else {
    // Default: Show Submitted applications needing review (is_kyc_verified = 2)
    $where_sql = "WHERE role = 'merchant' AND is_kyc_verified = 2 AND is_deleted = 0";
}

$total_stmt = $db->query("SELECT COUNT(*) FROM users $where_sql");
$total_rows = $total_stmt->fetchColumn();
$total_pages = ceil($total_rows / $limit);

// Fetch compliance submissions matching filter
$stmt = $db->prepare("SELECT id, email, business_name, business_type, country, is_kyc_verified, created_at,
                      registration_number, rc_number, bn_number, tin, id_type, id_expiry_date, bvn,
                      residential_address, id_card_path, utility_bill_path, liveliness_path,
                      cac_cert_path, cac_form_path, memart_path, bn_cert_path, bn_form_path,
                      ngo_form_path, ngo_constitution_path, gov_auth_letter_path, gov_gazette_path,
                      business_address_proof_path, kyc_notes
                      FROM users $where_sql
                      ORDER BY created_at DESC LIMIT ? OFFSET ?");
$stmt->bindValue(1, $limit, PDO::PARAM_INT);
$stmt->bindValue(2, $offset, PDO::PARAM_INT);
$stmt->execute();
$pending = $stmt->fetchAll();

include '../includes/dashboard-head.php';
?>
<body class="bg-slate-50 text-slate-900 flex h-screen overflow-hidden" x-data>
    <?php include '../includes/sidebar.php'; ?>
    <main class="flex-1 flex flex-col min-w-0 overflow-hidden"
          x-data="{
              showReview: false,
              processing: false,
              merchant: {},
              maskValue(val) {
                  if (!val) return 'Not provided';
                  const s = String(val);
                  if (s.length <= 4) return s;
                  return '*'.repeat(s.length - 4) + s.slice(-4);
              },
              openReview(m) {
                  this.merchant = m;
                  this.showReview = true;
                  this.$nextTick(() => { if (typeof lucide !== 'undefined') lucide.createIcons(); });
              },
              submitKYC(status) {
                  this.processing = true;
                  document.getElementById('kycStatus').value = status;
                  document.getElementById('kycForm').submit();
              }
          }">
        <?php include '../includes/topbar.php'; ?>
        <div class="flex-1 overflow-y-auto p-4 sm:p-8">
            <?php if (isset($error_msg) && $error_msg): ?>
                <div class="mb-6 p-4 bg-red-50 text-red-700 rounded-2xl border border-red-100 font-medium">
                    <?php echo htmlspecialchars($error_msg); ?>
                </div>
            <?php endif; ?>
            <?php if (isset($success_msg) && $success_msg): ?>
                <div class="mb-6 p-4 bg-emerald-50 text-emerald-700 rounded-2xl border border-emerald-100 font-medium">
                    <?php echo $success_msg; ?>
                </div>
            <?php endif; ?>

            <div class="mb-8 flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                <div>
                    <h1 class="text-2xl font-bold text-slate-900 mb-2">Compliance Queue</h1>
                    <p class="text-slate-500">Review uploaded KYC documents and approve or reject applications</p>
                </div>
                <div class="flex items-center gap-2 bg-white p-1.5 rounded-2xl border border-slate-200 shadow-sm text-xs font-bold shrink-0">
                    <a href="?status_filter=2" class="px-3 py-1.5 rounded-xl transition-all <?php echo $status_filter === 2 ? 'bg-indigo-600 text-white shadow-sm' : 'text-slate-500 hover:text-slate-900'; ?>">Submitted (Review Queue)</a>
                    <a href="?status_filter=3" class="px-3 py-1.5 rounded-xl transition-all <?php echo $status_filter === 3 ? 'bg-rose-600 text-white shadow-sm' : 'text-slate-500 hover:text-slate-900'; ?>">Rejected</a>
                    <a href="?status_filter=1" class="px-3 py-1.5 rounded-xl transition-all <?php echo $status_filter === 1 ? 'bg-emerald-600 text-white shadow-sm' : 'text-slate-500 hover:text-slate-900'; ?>">Approved</a>
                    <a href="?status_filter=0" class="px-3 py-1.5 rounded-xl transition-all <?php echo $status_filter === 0 ? 'bg-slate-800 text-white shadow-sm' : 'text-slate-500 hover:text-slate-900'; ?>">Unsubmitted</a>
                </div>
            </div>

            <div class="bg-white rounded-3xl border border-slate-200 shadow-sm overflow-hidden">
                <div class="p-6 border-b border-slate-100 font-bold flex justify-between items-center">
                    <div>
                        <span>KYC Applications</span>
                        <p class="text-[10px] text-slate-400 font-normal">Page <?php echo $page; ?> of <?php echo max(1, $total_pages); ?></p>
                    </div>
                    <span class="text-xs font-normal text-slate-500"><?php echo $total_rows; ?> applications in current view</span>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-left">
                        <thead>
                            <tr class="bg-slate-50/50">
                                <th class="px-4 sm:px-6 py-4 text-xs font-bold text-slate-500 uppercase">Merchant</th>
                                <th class="hidden sm:table-cell px-6 py-4 text-xs font-bold text-slate-500 uppercase">Business Type</th>
                                <th class="hidden md:table-cell px-6 py-4 text-xs font-bold text-slate-500 uppercase">Status</th>
                                <th class="px-4 sm:px-6 py-4 text-xs font-bold text-slate-500 uppercase">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <?php if (empty($pending)): ?>
                                <tr>
                                    <td colspan="4" class="px-6 py-12 text-center text-slate-400 text-sm">
                                        No applications found for this filter status.
                                    </td>
                                </tr>
                            <?php endif; ?>
                            <?php foreach ($pending as $m): ?>
                                <tr class="hover:bg-slate-50/30 transition-colors">
                                    <td class="px-4 sm:px-6 py-4">
                                        <div class="font-bold text-slate-900 truncate max-w-[150px] sm:max-w-none"><?php echo htmlspecialchars($m['business_name'] ?: 'N/A'); ?></div>
                                        <div class="text-xs text-slate-500 truncate max-w-[150px] sm:max-w-none"><?php echo htmlspecialchars($m['email']); ?></div>
                                    </td>
                                    <td class="hidden sm:table-cell px-6 py-4 text-sm font-medium text-slate-700"><?php echo htmlspecialchars($m['business_type'] ?: 'Not selected'); ?></td>
                                    <td class="hidden md:table-cell px-6 py-4">
                                        <?php
                                            $st = (int)$m['is_kyc_verified'];
                                            $badge_class = 'bg-slate-100 text-slate-600';
                                            $badge_text = 'Pending';
                                            if ($st === 1) {
                                                $badge_class = 'bg-emerald-100 text-emerald-700';
                                                $badge_text = 'Approved';
                                            } elseif ($st === 2) {
                                                $badge_class = 'bg-indigo-100 text-indigo-700';
                                                $badge_text = 'Submitted';
                                            } elseif ($st === 3) {
                                                $badge_class = 'bg-rose-100 text-rose-700';
                                                $badge_text = 'Rejected';
                                            }
                                        ?>
                                        <span class="px-2 py-1 rounded-full text-[10px] font-bold uppercase tracking-wider <?php echo $badge_class; ?>">
                                            <?php echo $badge_text; ?>
                                        </span>
                                    </td>
                                    <td class="px-6 py-4">
                                        <button @click="openReview(<?php echo htmlspecialchars(json_encode($m), ENT_QUOTES, 'UTF-8'); ?>)" class="bg-indigo-600 text-white px-4 py-2 rounded-xl text-xs font-bold hover:bg-indigo-700 transition-colors">Review Docs</button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination UI -->
                <?php if ($total_pages > 1): ?>
                    <div class="p-6 border-t border-slate-100 flex items-center justify-between bg-slate-50/30">
                        <p class="text-xs text-slate-500">Showing <?php echo $offset + 1; ?> to <?php echo min($offset + $limit, $total_rows); ?> of <?php echo $total_rows; ?> entries</p>
                        <div class="flex gap-2">
                            <a href="?page=<?php echo max(1, $page - 1); ?>" class="px-4 py-2 bg-white border border-slate-200 rounded-xl text-xs font-bold hover:bg-slate-50 transition-all <?php echo $page <= 1 ? 'opacity-50 pointer-events-none' : ''; ?>">Previous</a>
                            <a href="?page=<?php echo min($total_pages, $page + 1); ?>" class="px-4 py-2 bg-white border border-slate-200 rounded-xl text-xs font-bold hover:bg-slate-50 transition-all <?php echo $page >= $total_pages ? 'opacity-50 pointer-events-none' : ''; ?>">Next</a>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Review Modal -->
        <div x-show="showReview" x-cloak class="fixed inset-0 bg-slate-900/50 backdrop-blur-sm flex items-center justify-center z-[100] p-4">
            <div class="bg-white rounded-[2rem] w-full max-w-5xl overflow-hidden shadow-2xl border border-slate-200 flex flex-col h-[90vh]">
                <div class="p-6 border-b border-slate-100 flex justify-between items-center bg-slate-50/50">
                    <div>
                        <h3 class="font-bold text-slate-900 text-lg">KYC Document Review</h3>
                        <p class="text-xs text-slate-500" x-text="merchant.business_name"></p>
                    </div>
                    <button @click="showReview = false" class="p-2 text-slate-500 hover:text-slate-900 hover:bg-slate-100 rounded-full transition-all">
                        <i data-lucide="x" class="w-5 h-5"></i>
                    </button>
                </div>
                <div class="flex-1 overflow-y-auto p-8">
                    <div class="grid lg:grid-cols-3 gap-12">
                        <div class="lg:col-span-1 space-y-8">
                            <div>
                                <h4 class="text-[10px] font-bold text-slate-400 uppercase mb-4 tracking-widest">Business Information</h4>
                                <div class="space-y-4">
                                    <div>
                                        <p class="text-[10px] font-bold text-slate-400 uppercase">Business Type</p>
                                        <p class="text-sm font-bold text-slate-900" x-text="merchant.business_type || 'N/A'"></p>
                                    </div>
                                    <div>
                                        <p class="text-[10px] font-bold text-slate-400 uppercase">Country</p>
                                        <p class="text-sm font-bold text-slate-900" x-text="merchant.country || 'Nigeria'"></p>
                                    </div>
                                    <div>
                                        <p class="text-[10px] font-bold text-slate-400 uppercase">Registration / RC Number</p>
                                        <p class="text-sm font-bold text-slate-900" x-text="merchant.registration_number || merchant.rc_number || 'N/A'"></p>
                                    </div>
                                    <div>
                                        <p class="text-[10px] font-bold text-slate-400 uppercase">BN Number</p>
                                        <p class="text-sm font-bold text-slate-900" x-text="merchant.bn_number || 'N/A'"></p>
                                    </div>
                                    <div>
                                        <p class="text-[10px] font-bold text-slate-400 uppercase">TIN (Tax ID)</p>
                                        <p class="text-sm font-bold text-slate-900" x-text="merchant.tin || 'N/A'"></p>
                                    </div>
                                </div>
                            </div>

                            <div>
                                <h4 class="text-[10px] font-bold text-slate-400 uppercase mb-4 tracking-widest">Identification</h4>
                                <div class="space-y-4">
                                    <div>
                                        <p class="text-[10px] font-bold text-slate-400 uppercase">ID Type</p>
                                        <p class="text-sm font-bold text-slate-900" x-text="merchant.id_type || 'N/A'"></p>
                                    </div>
                                    <div x-show="merchant.id_expiry_date">
                                        <p class="text-[10px] font-bold text-slate-400 uppercase">ID Expiry</p>
                                        <p class="text-sm font-bold text-slate-900" x-text="merchant.id_expiry_date"></p>
                                    </div>
                                    <div>
                                        <p class="text-[10px] font-bold text-slate-400 uppercase">BVN/NIN</p>
                                        <p class="text-sm font-bold text-slate-900" x-text="maskValue(merchant.bvn)"></p>
                                    </div>
                                </div>
                            </div>

                            <div>
                                <h4 class="text-[10px] font-bold text-slate-400 uppercase mb-4 tracking-widest">Address</h4>
                                <p class="text-sm font-bold text-slate-900" x-text="merchant.residential_address || 'N/A'"></p>
                            </div>
                        </div>

                        <div class="lg:col-span-2 space-y-8" x-data="{ previewUrl: null }">
                            <h4 class="text-[10px] font-bold text-slate-400 uppercase mb-4 tracking-widest">Uploaded Documents</h4>
                            <div class="grid grid-cols-2 md:grid-cols-3 gap-6">
                                <template x-for="(doc, key) in {
                                    id_card_path: {label: 'Gov\'t ID', icon: 'credit-card'},
                                    utility_bill_path: {label: 'Utility Bill', icon: 'file-text'},
                                    liveliness_path: {label: 'Liveliness', icon: 'user'},
                                    cac_cert_path: {label: 'CAC Cert', icon: 'award'},
                                    cac_form_path: {label: 'CAC Form', icon: 'file-check'},
                                    memart_path: {label: 'MEMART', icon: 'book-open'},
                                    bn_cert_path: {label: 'BN Cert', icon: 'award'},
                                    bn_form_path: {label: 'BN Form', icon: 'file-check'},
                                    ngo_form_path: {label: 'NGO Form', icon: 'file-check'},
                                    ngo_constitution_path: {label: 'Constitution', icon: 'book-open'},
                                    gov_auth_letter_path: {label: 'Auth Letter', icon: 'mail'}
                                }" :key="key">
                                    <template x-if="merchant[key]">
                                        <div class="space-y-2">
                                            <div class="w-full aspect-square bg-slate-100 rounded-2xl border border-slate-200 overflow-hidden hover:ring-2 hover:ring-indigo-500 transition-all group relative shadow-sm flex flex-col items-center justify-center">
                                                <!-- Image Thumbnail -->
                                                <template x-if="merchant[key] && (merchant[key].match(/\.(jpg|jpeg|png|webp|jfif|pjpeg|pjp)$/i) || merchant[key].includes('liveliness'))">
                                                    <img :src="'../uploads/' + merchant[key]" class="w-full h-full object-cover cursor-pointer"
                                                         @click="previewUrl = '../uploads/' + merchant[key]"
                                                         @error="$event.target.src='https://placehold.co/400?text=Document+Error'">
                                                </template>
                                                <!-- PDF Icon -->
                                                <template x-if="merchant[key].match(/\.pdf$/i)">
                                                    <div class="flex flex-col items-center gap-2 cursor-pointer" @click="window.open('../uploads/' + merchant[key], '_blank')">
                                                        <i data-lucide="file-text" class="text-red-500 w-8 h-8"></i>
                                                        <span class="text-[8px] font-bold text-slate-400">PDF Document</span>
                                                    </div>
                                                </template>

                                                <div class="absolute inset-0 bg-indigo-900/40 opacity-0 group-hover:opacity-100 transition-opacity flex items-center justify-center pointer-events-none">
                                                    <span class="text-[10px] font-bold text-white uppercase">Click to Preview</span>
                                                </div>
                                            </div>
                                            <p class="text-[10px] font-bold text-slate-500 uppercase text-center" x-text="doc.label"></p>
                                        </div>
                                    </template>
                                </template>
                            </div>

                            <!-- Document Preview Modal (Inner) -->
                            <div x-show="previewUrl" x-cloak class="fixed inset-0 bg-slate-900/90 z-[110] flex items-center justify-center p-4 md:p-12">
                                <button @click="previewUrl = null" class="absolute top-6 right-6 text-white hover:text-slate-300">
                                    <i data-lucide="x" class="w-8 h-8"></i>
                                </button>
                                <img :src="previewUrl" class="max-w-full max-h-full object-contain rounded-xl shadow-2xl">
                            </div>
                        </div>
                    </div>
                </div>
                <div class="p-8 border-t border-slate-100 bg-slate-50/50">
                    <form method="POST" id="kycForm">
                        <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                        <input type="hidden" name="action" value="process_kyc">
                        <input type="hidden" name="merchant_id" :value="merchant.id">
                        <input type="hidden" name="status" id="kycStatus">
                        <div class="flex flex-col md:flex-row gap-6 items-end">
                            <div class="w-full md:w-32">
                                <label class="block text-[10px] font-bold text-slate-400 uppercase mb-2 tracking-widest">Security PIN</label>
                                <input type="password" name="security_pin" maxlength="4" pattern="\d{4}" required placeholder="0000" class="w-full px-4 py-3 bg-white border border-slate-200 rounded-xl focus:ring-2 focus:ring-indigo-500/20 outline-none text-center text-lg font-bold tracking-widest">
                            </div>
                            <div class="flex-1 w-full">
                                <label class="block text-[10px] font-bold text-slate-400 uppercase mb-2 tracking-widest">Admin Decision Notes</label>
                                <textarea name="notes" placeholder="Enter rejection reason or approval notes..." class="w-full px-4 py-3 bg-white border border-slate-200 rounded-xl focus:ring-2 focus:ring-indigo-500/20 outline-none h-20 text-sm"></textarea>
                            </div>
                            <div class="flex gap-3 shrink-0">
                                <button type="button"
                                        :disabled="processing"
                                        @click="submitKYC(3)"
                                        class="px-8 py-3 bg-red-50 text-red-600 border border-red-200 rounded-xl font-bold hover:bg-red-100 disabled:opacity-50 transition-all text-sm flex items-center gap-2">
                                    <span x-show="processing && document.getElementById('kycStatus').value == 3" class="w-3 h-3 border-2 border-red-600 border-t-transparent rounded-full animate-spin"></span>
                                    Reject Application
                                </button>
                                <button type="button"
                                        :disabled="processing"
                                        @click="submitKYC(1)"
                                        class="px-8 py-3 bg-indigo-600 text-white rounded-xl font-bold hover:bg-indigo-700 disabled:opacity-50 transition-all shadow-lg shadow-indigo-100 text-sm flex items-center gap-2">
                                    <span x-show="processing && document.getElementById('kycStatus').value == 1" class="w-3 h-3 border-2 border-white border-t-transparent rounded-full animate-spin"></span>
                                    Approve KYC
                                </button>
                            </div>
                        </div>
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