<?php
// php-version/merchant/compliance.php
require_once '../includes/functions.php';

if (!isLoggedIn()) redirect('../login.php');

$user = getAuthUser();
$db = Database::connect();

$success_msg = '';
$error_msg = '';

$compliance_notice = $_SESSION['compliance_notice'] ?? null;
unset($_SESSION['compliance_notice']);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_compliance') {
    // CSRF Validation
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $error_msg = "Security token mismatch. Please refresh and try again.";
    } else {
        $id_type = sanitize($_POST['id_type']);
        $id_expiry = sanitize($_POST['id_expiry_date'] ?? '');
        $bvn = sanitize($_POST['bvn'] ?? '');
        $address = sanitize($_POST['residential_address'] ?? '');
        $country = sanitize($_POST['country'] ?? 'Nigeria');

        $needs_expiry = in_array($id_type, ["Drivers License", "International Passport"]);
        
        $uploads = [];
        $files_to_handle = ['utility_bill', 'id_card'];
        $upload_errors = [];

        $allowed_mime_map = [
            'image/jpeg' => 'jpg',
            'image/jpg'  => 'jpg',
            'image/png'  => 'png',
            'image/webp' => 'webp',
            'application/pdf' => 'pdf'
        ];

        foreach ($files_to_handle as $field) {
            if (isset($_FILES[$field]) && $_FILES[$field]['error'] === UPLOAD_ERR_OK) {
                $tmp_name = $_FILES[$field]['tmp_name'];
                $file_size = $_FILES[$field]['size'];

                // Max file size: 5MB
                if ($file_size > 5 * 1024 * 1024) {
                    $upload_errors[] = ucfirst(str_replace('_', ' ', $field)) . " exceeds maximum allowed size of 5MB.";
                    continue;
                }

                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                $mime = finfo_file($finfo, $tmp_name);
                finfo_close($finfo);

                if (!array_key_exists($mime, $allowed_mime_map)) {
                    $upload_errors[] = ucfirst(str_replace('_', ' ', $field)) . " file format is invalid. Only JPG, PNG, WEBP and PDF files are allowed.";
                    continue;
                }

                $ext = $allowed_mime_map[$mime];
                $filename = $field . '_' . $user['id'] . '_' . bin2hex(random_bytes(8)) . '.' . $ext;

                if (!is_dir('../uploads')) {
                    mkdir('../uploads', 0755, true);
                }

                $target = '../uploads/' . $filename;
                if (strpos($mime, 'image/') === 0 && $ext !== 'pdf') {
                    resize_and_optimize_image($tmp_name, $target);
                } else {
                    move_uploaded_file($tmp_name, $target);
                }
                $uploads[$field . '_path'] = $filename;
            } elseif (empty($user[$field . '_path'])) {
                $upload_errors[] = ucfirst(str_replace('_', ' ', $field)) . " is required.";
            }
        }

        // Handle Base64 Liveliness Snapshot
        if (!empty($_POST['liveliness_image'])) {
            $data = $_POST['liveliness_image'];
            if (preg_match('/^data:image\/(jpeg|jpg|png|webp);base64,/', $data, $type)) {
                $data = substr($data, strpos($data, ',') + 1);
                $ext = strtolower($type[1]) === 'jpeg' ? 'jpg' : strtolower($type[1]);
                $data = base64_decode($data);
                if ($data !== false) {
                    $filename = 'liveliness_' . $user['id'] . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
                    if (!is_dir('../uploads')) mkdir('../uploads', 0755, true);
                    file_put_contents('../uploads/' . $filename, $data);
                    $uploads['liveliness_path'] = $filename;
                }
            }
        }

        if (!empty($upload_errors)) {
            $error_msg = implode('<br>', $upload_errors);
        } else {
            try {
                $sql = "UPDATE users SET
                    business_type = 'Starter',
                    id_type = ?,
                    id_expiry_date = ?,
                    bvn = ?,
                    residential_address = ?,
                    country = ?,
                    is_kyc_verified = 2";

                $params = [$id_type, $needs_expiry ? $id_expiry : null, $bvn, $address, $country];

                foreach ($uploads as $col => $val) {
                    $sql .= ", $col = ?";
                    $params[] = $val;
                }

                $sql .= " WHERE id = ?";
                $params[] = $user['id'];

                $stmt = $db->prepare($sql);
                $stmt->execute($params);
                $success_msg = "Compliance documents submitted successfully! Our compliance team will review your application.";
                $user = getAuthUser();
            } catch (\Throwable $e) {
                error_log("Compliance submission error (user {$user['id']}): " . $e->getMessage());
                $error_msg = "Submission failed. Please try again or contact support.";
            }
        }
    }
}

include '../includes/dashboard-head.php';
?>
<body class="bg-slate-50 text-slate-900 flex h-screen overflow-hidden" x-data>
    <?php include '../includes/sidebar.php'; ?>
    <main class="flex-1 flex flex-col min-w-0 overflow-hidden" x-data="{
        idType: '<?php echo $user['id_type']; ?>',
        country: '<?php echo $user['country'] ?: 'Nigeria'; ?>',
        snapshot: null,
        showCamera: false,
        get needsExpiry() { return ['Drivers License', 'International Passport'].includes(this.idType) },
        get isNigerian() { return this.country === 'Nigeria' },
        startCamera() {
            this.showCamera = true;
            navigator.mediaDevices.getUserMedia({ video: true }).then(stream => {
                this.$refs.video.srcObject = stream;
            }).catch(err => {
                alert('Camera access denied or unavailable.');
                this.showCamera = false;
            });
        },
        takeSnapshot() {
            const canvas = document.createElement('canvas');
            canvas.width = this.$refs.video.videoWidth;
            canvas.height = this.$refs.video.videoHeight;
            canvas.getContext('2d').drawImage(this.$refs.video, 0, 0);
            this.snapshot = canvas.toDataURL('image/jpeg');
            this.stopCamera();
        },
        stopCamera() {
            let stream = this.$refs.video.srcObject;
            if (stream) stream.getTracks().forEach(track => track.stop());
            this.showCamera = false;
        }
    }">
        <?php include '../includes/topbar.php'; ?>
        <div class="flex-1 overflow-y-auto p-4 sm:p-8">
            <div class="max-w-4xl mx-auto">
                <div class="mb-8 flex items-center justify-between">
                    <div>
                        <h1 class="text-2xl sm:text-3xl font-bold text-slate-900 mb-2">Compliance & KYC Verification</h1>
                        <p class="text-slate-500">Provide required legal documents to verify your business identity and unlock full portal access</p>
                    </div>
                </div>

                <?php if ($compliance_notice): ?>
                    <div class="mb-6 p-4 bg-amber-50 text-amber-800 rounded-2xl border border-amber-200 font-medium flex items-center gap-3">
                        <i data-lucide="shield-alert" class="w-5 h-5 text-amber-600 shrink-0"></i>
                        <span><?php echo htmlspecialchars($compliance_notice); ?></span>
                    </div>
                <?php endif; ?>

                <?php if ($success_msg): ?>
                    <div class="mb-6 p-4 bg-emerald-50 text-emerald-700 rounded-2xl border border-emerald-100 font-medium flex items-center gap-3">
                        <i data-lucide="check-circle-2" class="w-5 h-5 text-emerald-600 shrink-0"></i>
                        <span><?php echo $success_msg; ?></span>
                    </div>
                <?php endif; ?>
                <?php if ($error_msg): ?>
                    <div class="mb-6 p-4 bg-red-50 text-red-700 rounded-2xl border border-red-100 font-medium flex items-center gap-3">
                        <i data-lucide="alert-triangle" class="w-5 h-5 text-red-600 shrink-0"></i>
                        <span><?php echo $error_msg; ?></span>
                    </div>
                <?php endif; ?>

                <div class="grid lg:grid-cols-3 gap-8">
                    <div class="lg:col-span-2 space-y-6">
                        <div class="bg-white p-8 rounded-[2rem] border border-slate-200 shadow-sm">
                            <form method="POST" enctype="multipart/form-data" class="space-y-8">
                                <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                                <input type="hidden" name="action" value="update_compliance">
                                <input type="hidden" name="liveliness_image" :value="snapshot">

                                <div class="grid md:grid-cols-2 gap-6">
                                    <div>
                                        <label class="block text-xs font-bold text-slate-500 uppercase mb-2">Country of Operation</label>
                                        <select name="country" x-model="country" class="w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-xl outline-none focus:ring-2 focus:ring-indigo-500/20 font-medium">
                                            <option value="Nigeria">Nigeria</option>
                                            <option value="Ghana">Ghana</option>
                                            <option value="Kenya">Kenya</option>
                                            <option value="South Africa">South Africa</option>
                                            <option value="Other">Other International</option>
                                        </select>
                                    </div>
                                </div>

                                <div class="space-y-6">
                                    <h4 class="font-bold text-slate-900 border-b border-slate-100 pb-2 flex items-center gap-2">
                                        <span class="w-6 h-6 rounded-full bg-indigo-100 text-indigo-600 text-xs flex items-center justify-center font-bold">1</span>
                                        Identity Information
                                    </h4>
                                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                        <div>
                                            <label class="block text-xs font-bold text-slate-500 uppercase mb-2">Government ID Type</label>
                                            <select name="id_type" x-model="idType" required class="w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-xl outline-none focus:ring-2 focus:ring-indigo-500/20">
                                                <option value="">Select ID Type</option>
                                                <option value="NIN Slip" x-show="isNigerian">NIN Slip</option>
                                                <option value="Drivers License">Drivers License</option>
                                                <option value="Voters Card" x-show="isNigerian">Voters Card</option>
                                                <option value="International Passport">International Passport</option>
                                                <option value="National ID" x-show="!isNigerian">National ID Card</option>
                                            </select>
                                        </div>
                                        <div x-show="needsExpiry">
                                            <label class="block text-xs font-bold text-slate-500 uppercase mb-2">ID Expiry Date</label>
                                            <input type="date" name="id_expiry_date" value="<?php echo htmlspecialchars($user['id_expiry_date'] ?? ''); ?>" :required="needsExpiry" class="w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-xl outline-none">
                                        </div>
                                    </div>
                                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                        <div x-show="isNigerian">
                                            <label class="block text-xs font-bold text-slate-500 uppercase mb-2">BVN / NIN Number</label>
                                            <input type="text" name="bvn" value="<?php echo htmlspecialchars($user['bvn'] ?? ''); ?>" placeholder="222********" :required="isNigerian" class="w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-xl outline-none">
                                        </div>
                                    </div>
                                    <div>
                                        <label class="block text-xs font-bold text-slate-500 uppercase mb-2">Residential Address</label>
                                        <input type="text" name="residential_address" value="<?php echo htmlspecialchars($user['residential_address'] ?? ''); ?>" placeholder="123 Main St, City, State" required class="w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-xl outline-none">
                                    </div>
                                </div>

                                <div class="space-y-6">
                                    <h4 class="font-bold text-slate-900 border-b border-slate-100 pb-2 flex items-center gap-2">
                                        <span class="w-6 h-6 rounded-full bg-indigo-100 text-indigo-600 text-xs flex items-center justify-center font-bold">2</span>
                                        Document Uploads
                                    </h4>
                                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                        <div class="p-6 border-2 border-dashed border-slate-200 rounded-3xl text-center relative hover:border-indigo-400 transition-colors">
                                            <input type="file" name="id_card" accept="image/jpeg,image/png,image/webp,application/pdf" <?php echo empty($user['id_card_path']) ? 'required' : ''; ?> class="absolute inset-0 opacity-0 cursor-pointer">
                                            <i data-lucide="credit-card" class="text-slate-400 mb-2"></i>
                                            <p class="text-xs font-bold text-slate-900 uppercase">Government ID</p>
                                            <p class="text-[9px] text-slate-400 mt-1">Upload clear copy (JPG, PNG, PDF max 5MB)</p>
                                            <?php if (!empty($user['id_card_path'])): ?>
                                                <p class="text-[10px] text-emerald-600 font-semibold mt-2">✓ Previously uploaded</p>
                                            <?php endif; ?>
                                        </div>
                                        <div class="p-6 border-2 border-dashed border-slate-200 rounded-3xl text-center relative hover:border-indigo-400 transition-colors">
                                            <input type="file" name="utility_bill" accept="image/jpeg,image/png,image/webp,application/pdf" <?php echo empty($user['utility_bill_path']) ? 'required' : ''; ?> class="absolute inset-0 opacity-0 cursor-pointer">
                                            <i data-lucide="file-text" class="text-slate-400 mb-2"></i>
                                            <p class="text-xs font-bold text-slate-900 uppercase">Utility Bill</p>
                                            <p class="text-[9px] text-slate-400 mt-1">Proof of address issued within last 3 months</p>
                                            <?php if (!empty($user['utility_bill_path'])): ?>
                                                <p class="text-[10px] text-emerald-600 font-semibold mt-2">✓ Previously uploaded</p>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>

                                <div class="space-y-6">
                                    <h4 class="font-bold text-slate-900 border-b border-slate-100 pb-2 flex items-center gap-2">
                                        <span class="w-6 h-6 rounded-full bg-indigo-100 text-indigo-600 text-xs flex items-center justify-center font-bold">3</span>
                                        Liveliness Check
                                    </h4>
                                    <div class="flex flex-col items-center gap-4">
                                        <div class="w-full max-w-sm aspect-video bg-slate-100 rounded-3xl overflow-hidden relative border-2 border-slate-200">
                                            <video x-ref="video" autoplay playsinline class="w-full h-full object-cover" x-show="showCamera"></video>
                                            <img :src="snapshot" class="w-full h-full object-cover" x-show="snapshot && !showCamera">
                                            <?php if (!empty($user['liveliness_path'])): ?>
                                                <img src="../uploads/<?php echo htmlspecialchars($user['liveliness_path']); ?>" class="w-full h-full object-cover" x-show="!snapshot && !showCamera">
                                            <?php else: ?>
                                                <div class="absolute inset-0 flex items-center justify-center" x-show="!showCamera && !snapshot">
                                                    <i data-lucide="camera" class="text-slate-300 w-12 h-12"></i>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                        <div class="flex gap-3">
                                            <button type="button" @click="startCamera()" x-show="!showCamera" class="bg-slate-900 text-white px-6 py-2.5 rounded-xl font-bold text-sm">Start Camera</button>
                                            <button type="button" @click="takeSnapshot()" x-show="showCamera" class="bg-indigo-600 text-white px-6 py-2.5 rounded-xl font-bold text-sm">Capture Snapshot</button>
                                            <button type="button" @click="snapshot = null" x-show="snapshot" class="bg-rose-50 text-rose-600 px-6 py-2.5 rounded-xl font-bold text-sm">Retake</button>
                                        </div>
                                    </div>
                                </div>

                                <button type="submit" class="w-full bg-indigo-600 text-white py-4 rounded-xl font-bold shadow-lg shadow-indigo-100 hover:bg-indigo-700 transition-all flex items-center justify-center gap-2">
                                    <i data-lucide="shield-check" class="w-5 h-5"></i>
                                    Submit Compliance Documents
                                </button>
                            </form>
                        </div>
                    </div>

                    <div class="lg:col-span-1 space-y-6">
                        <div class="bg-indigo-900 p-6 rounded-[2rem] text-white shadow-xl relative overflow-hidden">
                            <div class="absolute top-0 right-0 w-32 h-32 bg-white/5 rounded-full -mr-16 -mt-16 blur-2xl"></div>
                            <h4 class="font-bold mb-4 relative z-10">Verification Status</h4>
                            <div class="space-y-4 relative z-10">
                                <div class="p-4 bg-white/10 rounded-2xl border border-white/10 text-center">
                                    <p class="text-[10px] font-bold text-indigo-300 uppercase tracking-widest mb-1">Status</p>
                                    <p class="text-sm font-bold capitalize">
                                        <?php 
                                            $st = (int)$user['is_kyc_verified'];
                                            echo $st == 1 ? 'Verified & Active' : ($st == 2 ? 'Under Review' : ($st == 3 ? 'Rejected / Revision Needed' : 'Action Required')); 
                                        ?>
                                    </p>
                                </div>
                                <p class="text-xs text-indigo-200 leading-relaxed text-center italic">
                                    <?php echo !empty($user['kyc_notes']) ? "Admin Note: " . htmlspecialchars($user['kyc_notes']) : "Your submitted documents are securely processed and reviewed by our compliance officer."; ?>
                                </p>
                            </div>
                        </div>

                        <div class="bg-white p-6 rounded-[2rem] border border-slate-200 shadow-sm space-y-3">
                            <h5 class="font-bold text-slate-900 text-sm">Need Help?</h5>
                            <p class="text-xs text-slate-500 leading-relaxed">If you have questions regarding document verification or requirements, contact our support team.</p>
                            <a href="../merchant/tickets.php" class="inline-flex items-center gap-2 text-xs font-bold text-indigo-600 hover:text-indigo-700">
                                <i data-lucide="ticket" class="w-4 h-4"></i>
                                Open Support Ticket &rarr;
                            </a>
                        </div>
                    </div>
                </div>
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