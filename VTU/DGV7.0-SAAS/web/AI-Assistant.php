<?php
require_once __DIR__ . '/../func/bc-secure-session.php';
 bc_secure_session_start();
include("../func/bc-config.php");

if(!isset($_SESSION["user_session"])){
    header("Location: Login.php");
    exit();
}

/**
 * DGV7 §25 — Support (formerly the AI terminal).
 *
 * Users were not buying VTU through the AI terminal, so the paid AI-purchase
 * view is retired. This page is now the dedicated, FREE support home:
 * the AI assistant answers first (WhatsApp-Meta-AI style), can save and send
 * support requests, and hands off to a human agent in bc-admin whenever it
 * cannot resolve something. Users are never charged for support — the chat
 * runs on the admin's AI configuration (bc-admin → Chat Settings).
 */
require_once(__DIR__ . "/../func/bc-support-chat-schema.php");
require_once(__DIR__ . "/../func/bc-support-chat.php");
if (function_exists('bc_chat_ensure_schema')) bc_chat_ensure_schema();

$sup_vendor = (int)($get_logged_user_details['vendor_id'] ?? 0);
$sup_thread = null;
if (function_exists('bc_chat_thread_for_user')) {
    $sup_thread = bc_chat_thread_for_user($sup_vendor, (int)($get_logged_user_details['id'] ?? 0));
}
?>
<?php
include_once(__DIR__ . "/../func/bc-plate-app.php");
bc_app_head("Support | " . ($get_all_site_details["site_title"] ?? 'Platform'));
?>
<?php bc_app_sidebar(); ?>
<?php bc_app_topbar(array("title" => "SUPPORT", "crumb" => "Home / Support")); ?>

    <section class="section dashboard">
        <div class="card border-0 rounded-4 shadow-sm overflow-hidden mb-4" style="background: linear-gradient(135deg, #0b1220 0%, #1e3a8a 100%);">
            <div class="card-body p-4 p-md-5 text-white">
                <span class="badge bg-success mb-3">FREE SUPPORT · AI + HUMAN</span>
                <h3 class="fw-bold mb-2">How can we help?</h3>
                <p class="opacity-75 mb-4" style="max-width: 640px;">Chat with support right here. Our AI assistant replies instantly, can look into your recent transactions, save and send your support request, and bring in a member of our team whenever a human is needed. You never pay for support.</p>
                <button type="button" class="btn btn-light rounded-pill px-4 fw-bold" onclick="var b=document.getElementById('bcChat-launcher'); if(b) b.click();">
                    <i class="bi bi-chat-dots-fill me-2"></i>Start Support Chat
                </button>
            </div>
        </div>

        <div class="row g-4">
            <div class="col-lg-7">
                <div class="card border-0 shadow-sm rounded-4 h-100"><div class="card-body p-4">
                    <h6 class="fw-bold mb-3">How support works</h6>
                    <div class="d-flex gap-3 mb-3">
                        <span class="badge bg-primary rounded-circle p-2" style="height: fit-content;"><i class="bi bi-robot"></i></span>
                        <div><strong class="d-block">1 · AI answers first</strong><span class="small text-muted">Instant answers about wallets, transfers, bills, verification and finding your way around — it can check your recent transaction activity to guide you.</span></div>
                    </div>
                    <div class="d-flex gap-3 mb-3">
                        <span class="badge bg-primary rounded-circle p-2" style="height: fit-content;"><i class="bi bi-ticket-perforated"></i></span>
                        <div><strong class="d-block">2 · Every chat is a ticket</strong><span class="small text-muted">Your conversation is saved as a support ticket with its own ticket number, so nothing gets lost and any agent can pick up where the last one stopped.</span></div>
                    </div>
                    <div class="d-flex gap-3">
                        <span class="badge bg-primary rounded-circle p-2" style="height: fit-content;"><i class="bi bi-person-headset"></i></span>
                        <div><strong class="d-block">3 · Humans take over when needed</strong><span class="small text-muted">Anything the AI cannot resolve is handed to our support team in the same conversation — tap “Talk to a person” in the chat at any time.</span></div>
                    </div>
                </div></div>
            </div>
            <div class="col-lg-5">
                <div class="card border-0 shadow-sm rounded-4 h-100"><div class="card-body p-4">
                    <h6 class="fw-bold mb-3">Your support ticket</h6>
                    <?php if ($sup_thread): ?>
                        <div class="d-flex justify-content-between border-bottom py-2"><span class="text-muted small">Ticket number</span><strong><?php echo htmlspecialchars($sup_thread['ticket_no'] ?: ('SUP-' . str_pad((string)$sup_thread['id'], 6, '0', STR_PAD_LEFT))); ?></strong></div>
                        <div class="d-flex justify-content-between border-bottom py-2"><span class="text-muted small">Status</span><span class="badge bg-primary"><?php echo htmlspecialchars($sup_thread['status']); ?></span></div>
                        <div class="d-flex justify-content-between py-2"><span class="text-muted small">Last activity</span><span><?php echo htmlspecialchars($sup_thread['last_message_at'] ?? ''); ?></span></div>
                        <button type="button" class="btn btn-primary w-100 rounded-pill fw-bold mt-3" onclick="var b=document.getElementById('bcChat-launcher'); if(b) b.click();">Open Conversation</button>
                    <?php else: ?>
                        <p class="small text-muted">You have no open support ticket. Start a chat and your first ticket number will appear here.</p>
                        <button type="button" class="btn btn-primary w-100 rounded-pill fw-bold" onclick="var b=document.getElementById('bcChat-launcher'); if(b) b.click();">Start Your First Chat</button>
                    <?php endif; ?>
                    <div class="alert alert-success border-0 rounded-3 small mt-4 mb-0"><i class="bi bi-patch-check-fill me-1"></i>Support is always free. If anyone asks you to pay for help, please report it in chat.</div>
                </div></div>
            </div>
        </div>
    </section>

<?php bc_app_foot(); ?>
