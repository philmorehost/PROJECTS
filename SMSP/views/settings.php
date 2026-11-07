<?php
// System Settings View (General settings, user profile, and data cleanup tool)
if (!defined('PORTAL_PATH')) exit;

if ($_SESSION['user_role'] === 'teacher') {
    $stmtSec = $pdo->prepare("SELECT is_sub_admin FROM sm_teachers WHERE email = ? AND school_id_number = ? LIMIT 1");
    $stmtSec->execute([$_SESSION['user_session'], $school_id]);
    if ((int)$stmtSec->fetchColumn() === 1) {
        http_response_code(403);
        echo "<div style='padding:20px; color:red; font-family:sans-serif; text-align:center;'>⛔ Access Denied. As a teacher designated as a Sub-Admin, you are not authorized to access System Settings.</div>";
        exit;
    }
}

$msg = '';
$action = isset($_GET['action']) ? $_GET['action'] : 'list';

// Handle submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['save_general'])) {
        $motto = trim($_POST['motto']);
        $name = trim($_POST['name']);
        
        // Handle logo upload with compression conversion to WebP if GD is loaded
        $logo_url = '';
        if (is_uploaded_file($_FILES['logo']['tmp_name'])) {
            $temp = $_FILES['logo']['tmp_name'];
            $ext = pathinfo($_FILES['logo']['name'], PATHINFO_EXTENSION);
            $target = PORTAL_PATH . '/imgfile/logo_' . $school_id . '.webp';
            
            // Convert to webp if extension supported by gd
            if (function_exists('imagecreatefromstring')) {
                $img = imagecreatefromstring(file_get_contents($temp));
                if ($img !== false) {
                    imagewebp($img, $target, 85);
                    imagedestroy($img);
                    $logo_url = '/imgfile/logo_' . $school_id . '.webp';
                }
            }
            if (empty($logo_url)) {
                $target = PORTAL_PATH . '/imgfile/logo_' . $school_id . '.' . $ext;
                move_uploaded_file($temp, $target);
                $logo_url = '/imgfile/logo_' . $school_id . '.' . $ext;
            }
        }

        if (!empty($logo_url)) {
            $stmt = $pdo->prepare("UPDATE sm_schools SET name = ?, motto = ?, logo_url = ? WHERE id = ?");
            $stmt->execute([$name, $motto, $logo_url, $school_id]);
        } else {
            $stmt = $pdo->prepare("UPDATE sm_schools SET name = ?, motto = ? WHERE id = ?");
            $stmt->execute([$name, $motto, $school_id]);
        }
        $msg = "School configurations saved successfully!";
    } elseif (isset($_POST['save_domain'])) {
        $custom_domain = trim($_POST['custom_domain']);
        if (!empty($custom_domain)) {
            $custom_domain = strtolower($custom_domain);
            $custom_domain = preg_replace('/^https?:\/\//', '', $custom_domain);
            $custom_domain = trim($custom_domain, '/ ');

            $stmtCheck = $pdo->prepare("SELECT COUNT(*) FROM sm_schools WHERE custom_domain = ? AND id != ?");
            $stmtCheck->execute([$custom_domain, $school_id]);
            if ($stmtCheck->fetchColumn() > 0) {
                $msg = "⚠️ Error: The domain/subdomain '{$custom_domain}' is already mapped to another school portal.";
            } else {
                // Call cPanel UAPI to map the domain in background
                require_once PORTAL_PATH . '/include/cpanel_helper.php';
                $cpanel = CPanelDomainHelper::addAddonDomain($custom_domain);
                
                if ($cpanel['status']) {
                    $stmt = $pdo->prepare("UPDATE sm_schools SET custom_domain = ? WHERE id = ?");
                    $stmt->execute([$custom_domain, $school_id]);
                    $msg = "Custom domain mapping saved successfully! " . htmlspecialchars($cpanel['message']);
                } else {
                    $msg = "⚠️ Saved locally, but failed cPanel integration: " . htmlspecialchars($cpanel['message']);
                }
            }
        } else {
            $stmt = $pdo->prepare("UPDATE sm_schools SET custom_domain = NULL WHERE id = ?");
            $stmt->execute([$school_id]);
            $msg = "Custom domain mapping removed successfully!";
        }
    } elseif (isset($_POST['save_lock_settings'])) {
        $lock_print = isset($_POST['lock_print_on_fees_due']) ? '1' : '0';
        $stmt = $pdo->prepare("UPDATE sm_global_settings SET setting_value = ? WHERE setting_key = 'lock_print_on_fees_due'");
        $stmt->execute([$lock_print]);
        $msg = "Print restriction settings updated successfully!";
        // Also update the loaded constant if applicable
        if (defined('LOCK_PRINT_ON_FEES_DUE')) {
            // Constant cannot be redefined but db has been updated. Reloading will fix it.
        }
    } elseif (isset($_POST['cleanup_data'])) {
        // Safe database clean up resets
        if ($addons['Data Cleanup']) {
            $table = $_POST['table_scope'];
            if (in_array($table, ['sm_results', 'sm_student_attendances', 'sm_submitted_homework_lists', 'sm_cbt_submitted'])) {
                $stmt = $pdo->prepare("DELETE FROM `{$table}` WHERE school_id_number = ?");
                $stmt->execute([$school_id]);
                $msg = "Data reset complete for table: {$table}";
            } else {
                $msg = "Unauthorized table cleanup selected.";
            }
        } else {
            $msg = "Data Cleanup addon is not enabled.";
        }
    }
}

// Fetch current school details (may be false on fresh install before migration)
$stmtSch = $pdo->prepare("SELECT * FROM sm_schools WHERE id = ?");
$stmtSch->execute([$school_id]);
$school_details = $stmtSch->fetch() ?: [];
?>

<div class="space-y-6">
    <div class="flex flex-col md:flex-row md:justify-between md:items-center gap-4 pb-2">
        <h2 class="text-2xl font-extrabold bg-gradient-to-r from-blue-400 to-indigo-400 bg-clip-text text-transparent">⚙️ System Settings</h2>
        <div class="flex overflow-x-auto gap-2 items-center w-full md:w-auto p-1 scrollbar-thin">
            <a href="?page=settings&action=list" class="flex-shrink-0 whitespace-nowrap px-4 py-2 bg-white/5 border border-white/10 text-white rounded-xl hover:bg-white/10 transition text-xs sm:text-sm font-semibold shadow-md">Dashboard Overview</a>
            <a href="?page=settings&action=general" class="flex-shrink-0 whitespace-nowrap px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-xl transition text-xs sm:text-sm font-semibold shadow-md shadow-blue-600/25">General Settings</a>
            <?php if ($addons['Data Cleanup']) : ?>
                <a href="?page=settings&action=cleanup" class="flex-shrink-0 whitespace-nowrap px-4 py-2 bg-red-600 hover:bg-red-700 text-white rounded-xl transition text-xs sm:text-sm font-semibold shadow-md shadow-red-600/25">🧹 Data Cleanup</a>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($msg) : ?>
        <div class="p-4 bg-blue-500/10 border border-blue-500/20 text-blue-400 rounded-xl text-sm">
            <?php echo htmlspecialchars($msg); ?>
        </div>
    <?php endif; ?>

    <?php if ($action === 'list') : ?>
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
            <!-- Details Preview -->
            <div class="bg-white/5 border border-white/10 rounded-2xl p-6 shadow-xl backdrop-blur-md">
                <h3 class="text-lg font-semibold mb-4 text-blue-300">Identity Details</h3>
                <div class="space-y-4">
                    <?php if (!empty($school_details['logo_url'])) : ?>
                        <div class="flex items-center space-x-4">
                            <img src="<?php echo htmlspecialchars($school_details['logo_url'] ?? ''); ?>" class="w-16 h-16 rounded-xl border border-white/10 object-contain bg-white/5 p-1" alt="Logo" />
                            <div>
                                <h4 class="font-bold text-white"><?php echo htmlspecialchars($school_details['name'] ?? ''); ?></h4>
                                <p class="text-xs text-gray-400">Slug ID: <?php echo htmlspecialchars($school_details['slug'] ?? ''); ?></p>
                            </div>
                        </div>
                    <?php else : ?>
                        <h4 class="font-bold text-white"><?php echo htmlspecialchars($school_details['name'] ?? 'School not set up yet'); ?></h4>
                    <?php endif; ?>
                    <p class="text-sm text-gray-300 italic">"<?php echo htmlspecialchars($school_details['motto'] ?? ''); ?>"</p>
                </div>
            </div>

            <!-- Translate Elements -->
            <div class="bg-white/5 border border-white/10 rounded-2xl p-6 shadow-xl backdrop-blur-md">
                <h3 class="text-lg font-semibold mb-4 text-indigo-300">Portal Translations</h3>
                <p class="text-sm text-gray-400 mb-6">Select your target local language. The system binds the Translate localization elements seamlessly.</p>
                <div id="google_translate_element" class="bg-white/5 border border-white/10 p-3 rounded-xl inline-block"></div>
                <script type="text/javascript">
                    function googleTranslateElementInit() {
                        new google.translate.TranslateElement({pageLanguage: 'en', layout: google.translate.TranslateElement.InlineLayout.SIMPLE}, 'google_translate_element');
                    }
                </script>
                <script type="text/javascript" src="//translate.google.com/translate_a/element.js?cb=googleTranslateElementInit" defer></script>
            </div>
        </div>

    <?php elseif ($action === 'general') : ?>
        <div class="max-w-xl bg-white/5 border border-white/10 rounded-2xl p-8 shadow-2xl mx-auto">
            <h3 class="text-lg font-semibold mb-6 text-blue-300">General Identity Preferences</h3>
            <form method="post" enctype="multipart/form-data" class="space-y-4">
                <div>
                    <label class="block text-sm font-medium text-gray-400 mb-1">School Name</label>
                    <input type="text" name="name" value="<?php echo htmlspecialchars($school_details['name'] ?? ''); ?>" required class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-blue-500" />
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-400 mb-1">Motto</label>
                    <input type="text" name="motto" value="<?php echo htmlspecialchars($school_details['motto'] ?? ''); ?>" required class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-blue-500" />
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-400 mb-1">School Logo (Upload to convert to WebP)</label>
                    <input type="file" name="logo" accept="image/*" class="w-full text-sm text-gray-400 file:mr-4 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-sm file:font-semibold file:bg-blue-600 file:text-white hover:file:bg-blue-700 cursor-pointer" />
                </div>
                <button type="submit" name="save_general" class="w-full py-2.5 bg-blue-600 hover:bg-blue-700 text-white rounded-lg font-semibold transition">
                    Save Configurations
                </button>
            </form>
        </div>

        <div class="max-w-xl bg-white/5 border border-white/10 rounded-2xl p-8 shadow-2xl mx-auto mt-6">
            <h3 class="text-lg font-semibold mb-2 text-amber-300">🔒 Report Card Access Policy</h3>
            <p class="text-xs text-gray-400 mb-6">Configure access rules for report cards when students have unpaid invoices/dues.</p>
            
            <form method="post" class="space-y-4">
                <?php
                // Fetch latest state from database directly to ensure accuracy
                $stmtLockVal = $pdo->prepare("SELECT setting_value FROM sm_global_settings WHERE setting_key = 'lock_print_on_fees_due' LIMIT 1");
                $stmtLockVal->execute();
                $lockVal = $stmtLockVal->fetchColumn() ?: '0';
                ?>
                <div class="flex items-center justify-between p-4 bg-white/5 border border-white/5 rounded-xl">
                    <div>
                        <p class="text-white text-sm font-semibold">Lock Results/Report Card on Unpaid Fees</p>
                        <p class="text-xs text-gray-400">If enabled, students and parents must pay all outstanding school fee invoices to view or print terminal report cards.</p>
                    </div>
                    <label class="relative inline-flex items-center cursor-pointer">
                        <input type="checkbox" name="lock_print_on_fees_due" value="1" <?php echo ($lockVal === '1') ? 'checked' : ''; ?> class="sr-only peer">
                        <div class="w-11 h-6 bg-gray-700 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-amber-500"></div>
                    </label>
                </div>
                <button type="submit" name="save_lock_settings" class="w-full py-2.5 bg-amber-600 hover:bg-amber-700 text-white rounded-lg font-semibold transition">
                    Save Policy Settings
                </button>
            </form>
        </div>

        <div class="max-w-xl bg-white/5 border border-white/10 rounded-2xl p-8 shadow-2xl mx-auto mt-6">
            <h3 class="text-lg font-semibold mb-2 text-indigo-300">🔗 Custom Domain Mapping</h3>
            <p class="text-xs text-gray-400 mb-6">Point your own primary domain (e.g. <code class="text-white">school.edu</code>) or subdomain (e.g. <code class="text-white">portal.school.edu</code>) directly to your portal dashboard.</p>
            
            <form method="post" class="space-y-4">
                <div>
                    <label class="block text-sm font-medium text-gray-400 mb-1">Custom Domain or Subdomain</label>
                    <input type="text" name="custom_domain" value="<?php echo htmlspecialchars($school_details['custom_domain'] ?? ''); ?>" placeholder="e.g. portal.greenfield.edu" class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-blue-500 font-mono text-sm" />
                </div>
                <button type="submit" name="save_domain" class="w-full py-2.5 bg-indigo-600 hover:bg-indigo-700 text-white rounded-lg font-semibold transition">
                    Update Domain Mapping
                </button>
            </form>

            <div class="mt-6 border-t border-white/10 pt-6 space-y-4 text-xs text-gray-300">
                <h4 class="font-bold text-white uppercase tracking-wider text-xs">DNS Setup Guide:</h4>
                
                <div class="space-y-3">
                    <div>
                        <p class="font-semibold text-blue-300">Option A: Using Subdomain (Recommended)</p>
                        <p class="mt-1">Add a DNS record in your domain host's DNS settings panel pointing to our server IP:</p>
                        <ul class="list-disc pl-5 mt-1 space-y-1 font-mono text-white text-[11px]">
                            <li>Record Type: <strong class="text-gray-400 font-sans">A Record</strong> | IP Address: <strong class="bg-white/5 px-1 rounded"><?php echo defined('PLATFORM_IP') && PLATFORM_IP ? htmlspecialchars(PLATFORM_IP) : '123.45.67.89'; ?></strong></li>
                            <li>Record Type: <strong class="text-gray-400 font-sans">CNAME Record</strong> | Destination: <strong class="bg-white/5 px-1 rounded">schoolapp.ng</strong></li>
                        </ul>
                    </div>
                    
                    <div>
                        <p class="font-semibold text-indigo-300">Option B: Using Primary Domain</p>
                        <p class="mt-1">Point your domain registrar's Nameserver (NS) records to our primary nameservers:</p>
                        <ul class="list-disc pl-5 mt-1 space-y-1 font-mono text-white text-[11px]">
                            <li>Nameserver 1: <strong class="bg-white/5 px-1.5 py-0.5 rounded"><?php echo defined('PLATFORM_NS1') && PLATFORM_NS1 ? htmlspecialchars(PLATFORM_NS1) : 'ns1.schoolapp.ng'; ?></strong></li>
                            <li>Nameserver 2: <strong class="bg-white/5 px-1.5 py-0.5 rounded"><?php echo defined('PLATFORM_NS2') && PLATFORM_NS2 ? htmlspecialchars(PLATFORM_NS2) : 'ns2.schoolapp.ng'; ?></strong></li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>

    <?php elseif ($action === 'cleanup' && $addons['Data Cleanup']) : ?>
        <div class="max-w-xl bg-white/5 border border-red-500/20 rounded-2xl p-8 shadow-2xl mx-auto">
            <h3 class="text-lg font-semibold mb-2 text-red-400">🧹 Data Cleanup Tool</h3>
            <p class="text-xs text-gray-400 mb-6">Select a category to reset database records securely. <strong class="text-red-400">Warning: This action is irreversible.</strong></p>
            <form method="post" class="space-y-4">
                <div>
                    <label class="block text-sm font-medium text-gray-400 mb-1">Data Scope to Reset</label>
                    <select name="table_scope" class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-red-500">
                        <option value="sm_results">Term Exam Results Records</option>
                        <option value="sm_student_attendances">Student Attendance Logs</option>
                        <option value="sm_submitted_homework_lists">Student Homework Uploads</option>
                        <option value="sm_cbt_submitted">CBT Submitted Exam Logs</option>
                    </select>
                </div>
                <button type="submit" name="cleanup_data" onclick="return confirm('Are you absolutely sure you want to delete all selected records?');" class="w-full py-2.5 bg-red-600 hover:bg-red-700 text-white rounded-lg font-semibold transition">
                    Wipe Selected Data
                </button>
            </form>
        </div>
    <?php endif; ?>
</div>
