<?php
require_once __DIR__ . '/../func/bc-secure-session.php';

bc_secure_session_start();
include("../func/bc-config.php");

$hub_slug = isset($_GET['g']) ? (string)$_GET['g'] : '';

$sup_thread = null;
$sup_chat_on = false;
if ($hub_slug === 'settings-help') {
    require_once(__DIR__ . "/../func/bc-support-chat-schema.php");
    require_once(__DIR__ . "/../func/bc-support-chat.php");
    if (function_exists('bc_chat_schema_ready') && !bc_chat_schema_ready() && function_exists('bc_chat_ensure_schema')) {
        bc_chat_ensure_schema();
    }
    $sup_vendor = (int)($get_logged_user_details['vendor_id'] ?? 0);
    $sup_uid    = (int)($get_logged_user_details['id'] ?? 0);
    if (function_exists('bc_chat_thread_for_user')) {
        $sup_thread = bc_chat_thread_for_user($sup_vendor, $sup_uid);
    }
    if (function_exists('bc_chat_widget_enabled')) {
        $sup_chat_on = bc_chat_widget_enabled($sup_vendor);
    }
}
?>
<?php
// Plate app shell: section landing pages. The sidebar lists main items only;
// each one lands here and lists its sub-pages as an embossed dark matrix.
include_once(__DIR__ . "/../func/bc-plate-app.php");
$hub_group = $hub_slug !== '' ? bc_app_hub_group($hub_slug) : null;
bc_app_head(($hub_group ? $hub_group['label'] : 'Explore') . " | " . ($get_all_site_details["site_title"] ?? 'Platform'));
?>
<?php bc_app_sidebar(); ?>
<?php bc_app_topbar(array("title" => $hub_group ? strtoupper($hub_group['label']) : 'EXPLORE', "crumb" => "Home / " . ($hub_group ? $hub_group['label'] : 'Explore'))); ?>

<div class="pk-hub__hero">
    <h1><?php echo bc_app_icon($hub_group && !empty($hub_group['icon']) ? $hub_group['icon'] : 'grid-3x3-gap'); ?> <?php echo htmlspecialchars($hub_group ? $hub_group['label'] : 'Explore'); ?></h1>
    <p><?php echo $hub_group ? 'Everything in this section, one tap away.' : 'Pick a section to see everything inside it.'; ?></p>
</div>

<?php if ($hub_group): ?>
    <?php bc_app_hub_render($hub_group); ?>
<?php else: ?>
    <div class="pk-matrix">
        <?php foreach (bc_app_menu() as $group): ?>
            <?php if (empty($group['label']) || empty($group['id'])) continue; ?>
            <a class="pk-matrix__card" href="<?php echo htmlspecialchars(bc_app_url('Hub.php?g=' . urlencode($group['id']))); ?>">
                <span class="pk-matrix__icon"><?php echo bc_app_icon(!empty($group['icon']) ? $group['icon'] : 'grid'); ?></span>
                <span class="pk-matrix__label"><?php echo htmlspecialchars($group['label']); ?></span>
                <span class="pk-matrix__go"><?php echo bc_app_icon('arrow-right-circle'); ?></span>
            </a>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php if ($hub_slug === 'settings-help'): ?>
    <section id="bcSupportSection" class="section dashboard mt-4">
        <div class="card border-0 rounded-4 shadow-sm overflow-hidden mb-4" style="background: linear-gradient(135deg, #0b1220 0%, #1e3a8a 100%);">
            <div class="card-body p-4 p-md-5 text-white">
                <span class="badge bg-success mb-3">FREE SUPPORT · AI + HUMAN</span>
                <h3 class="fw-bold mb-2">How can we help?</h3>
                <p class="opacity-75 mb-4" style="max-width: 640px;">Chat with support right here. Our AI assistant replies instantly, can look into your recent transactions, save and send your support request, and bring in a member of our team whenever a human is needed. You never pay for support.</p>
                <?php if ($sup_chat_on): ?>
                    <button type="button" class="btn btn-light rounded-pill px-4 fw-bold" data-bc-open-chat>
                        <i class="bi bi-chat-dots-fill me-2"></i>Start Support Chat
                    </button>
                <?php else: ?>
                    <div class="alert alert-warning border-0 rounded-3 small mb-0" style="max-width: 560px;">
                        <i class="bi bi-info-circle-fill me-1"></i>Live support chat is currently turned off by the site administrator. Please enable it in <strong>Chat Settings</strong> or try again later.
                    </div>
                <?php endif; ?>
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
                        <?php if ($sup_chat_on): ?>
                            <button type="button" class="btn btn-primary w-100 rounded-pill fw-bold mt-3" data-bc-open-chat>Open Conversation</button>
                        <?php endif; ?>
                    <?php else: ?>
                        <p class="small text-muted">You have no open support ticket. Start a chat and your first ticket number will appear here.</p>
                        <?php if ($sup_chat_on): ?>
                            <button type="button" class="btn btn-primary w-100 rounded-pill fw-bold" data-bc-open-chat>Start Your First Chat</button>
                        <?php endif; ?>
                    <?php endif; ?>
                    <div class="alert alert-success border-0 rounded-3 small mt-4 mb-0"><i class="bi bi-patch-check-fill me-1"></i>Support is always free. If anyone asks you to pay for help, please report it in chat.</div>
                </div></div>
            </div>
        </div>
    </section>
    <script>
    (function () {
        document.addEventListener('click', function (ev) {
            var btn = ev.target && ev.target.closest ? ev.target.closest('[data-bc-open-chat]') : null;
            if (!btn) return;
            ev.preventDefault();
            if (typeof window.bcChatOpen === 'function') {
                window.bcChatOpen();
                return;
            }
            var launcher = document.getElementById('bcChat-launcher');
            if (launcher) launcher.click();
        });
    })();
    </script>
<?php endif; ?>

<?php bc_app_foot(); ?>
