<?php session_start();
include("../func/bc-admin-config.php");
require_once(__DIR__ . "/../func/bc-support-chat.php");

/**
 * DGV7 phase 2 — support chat settings.
 *
 * Where the tenant switches chat on and configures the assistant.
 *
 * The API key is the sensitive part, and the rules are:
 *   - it is WRITE-ONLY through this page: the value is never echoed back into the form;
 *   - leaving the field blank keeps the stored key, so an admin editing the welcome text does not
 *     silently wipe the credential;
 *   - clearing it takes a deliberate checkbox, because an accidental wipe breaks the assistant
 *     with no error anywhere;
 *   - only the last four characters are ever shown, and only as a hint that a key exists.
 */

$vid = (int)$get_logged_admin_details['id'];

if (bc_chat_schema_ready() === false) {
    bc_chat_ensure_schema();
}

$settings = bc_chat_settings($vid);

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['save-chat-settings'])) {
    $enabled = isset($_POST['chat_enabled']) ? '1' : '0';
    $ai      = isset($_POST['chat_ai_enabled']) ? '1' : '0';

    $provider = strtolower(trim((string)($_POST['chat_ai_provider'] ?? 'gemini')));
    if (!in_array($provider, ['gemini', 'deepseek'], true)) $provider = 'gemini';

    $model = trim((string)($_POST['chat_ai_model'] ?? ''));
    // Model names are short and alphanumeric-ish; refuse anything that looks like an injection.
    if (!preg_match('/^[A-Za-z0-9._:-]{0,64}$/', $model)) $model = '';

    $welcome = trim((string)($_POST['chat_welcome'] ?? ''));
    $handoff = trim((string)($_POST['chat_handoff'] ?? ''));

    bc_chat_setting_save($vid, 'chat_enabled', $enabled);
    bc_chat_setting_save($vid, 'chat_ai_enabled', $ai);
    bc_chat_setting_save($vid, 'chat_ai_provider', $provider);
    bc_chat_setting_save($vid, 'chat_ai_model', $model);
    if ($welcome !== '') bc_chat_setting_save($vid, 'chat_welcome', $welcome);
    if ($handoff !== '') bc_chat_setting_save($vid, 'chat_handoff', $handoff);

    // Key: blank keeps, checkbox clears, anything else replaces.
    if (isset($_POST['chat_ai_key_clear'])) {
        bc_chat_setting_save($vid, 'chat_ai_key', '');
    } else {
        $new_key = trim((string)($_POST['chat_ai_key'] ?? ''));
        if ($new_key !== '') {
            if (strlen($new_key) < 16) {
                bc_chat_flash('That key looks too short to be valid. It was saved anyway — check it before enabling the assistant.', 'warning');
            }
            bc_chat_setting_save($vid, 'chat_ai_key', $new_key);
        }
    }

    bc_chat_flash('Chat settings saved.', 'success');
    header("Location: ChatSettings.php");
    exit();
}

$settings = bc_chat_settings($vid);
$has_key  = trim((string)$settings['chat_ai_key']) !== '';
$key_tail = $has_key ? substr(trim((string)$settings['chat_ai_key']), -4) : '';
$flash    = bc_chat_flash();

// The single most common misconfiguration, stated plainly rather than left for the customer to find.
$warn_ai_without_key = ($settings['chat_ai_enabled'] === '1' && !$has_key);
$warn_chat_off       = ($settings['chat_enabled'] !== '1');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>Chat Settings | <?php echo htmlspecialchars($get_all_super_admin_site_details["site_title"] ?? ''); ?></title>
    <?php include("../func/bc-admin-header-link.php"); ?>
</head>
<body>
    <?php include("../func/bc-admin-header.php"); ?>

<div class="pagetitle">
    <h1>CHAT SETTINGS</h1>
    <nav><ol class="breadcrumb">
        <li class="breadcrumb-item"><a href="Dashboard.php">Home</a></li>
        <li class="breadcrumb-item active">Chat Settings</li>
    </ol></nav>
</div>

<section class="section dashboard">
    <?php if ($flash): ?>
        <div class="alert alert-<?php echo htmlspecialchars($flash['type']); ?> py-2 small" role="alert">
            <?php echo htmlspecialchars($flash['message']); ?>
        </div>
    <?php endif; ?>

    <?php if ($warn_chat_off): ?>
        <div class="alert alert-secondary py-2 small" role="alert">
            Support chat is switched off, so the widget does not appear to customers.
        </div>
    <?php endif; ?>

    <?php if ($warn_ai_without_key): ?>
        <div class="alert alert-warning py-2 small" role="alert">
            The assistant is switched on but no API key is stored, so it will stay silent and every
            conversation will wait for a person.
        </div>
    <?php endif; ?>

    <div class="row">
        <div class="col-lg-8">
            <div class="card">
                <div class="card-body">
                    <h5 class="card-title">Support chat</h5>
                    <form method="post">
                        <div class="form-check form-switch mb-3">
                            <input class="form-check-input" type="checkbox" id="chat_enabled" name="chat_enabled"
                                   <?php echo $settings['chat_enabled'] === '1' ? 'checked' : ''; ?>>
                            <label class="form-check-label" for="chat_enabled">
                                Show the chat widget to signed-in customers
                            </label>
                        </div>

                        <hr>
                        <h6 class="fw-bold">Automated assistant</h6>
                        <div class="form-check form-switch mb-3">
                            <input class="form-check-input" type="checkbox" id="chat_ai_enabled" name="chat_ai_enabled"
                                   <?php echo $settings['chat_ai_enabled'] === '1' ? 'checked' : ''; ?>>
                            <label class="form-check-label" for="chat_ai_enabled">
                                Let the assistant answer first-line questions
                            </label>
                        </div>
                        <p class="small text-muted">
                            The assistant answers questions about how the product works. It cannot see
                            balances, promise refunds or ask for credentials, and it hands the
                            conversation to a person when the answer needs account data.
                        </p>

                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label small fw-bold" for="chat_ai_provider">Provider</label>
                                <select class="form-select" id="chat_ai_provider" name="chat_ai_provider">
                                    <?php foreach (['gemini' => 'Google Gemini', 'deepseek' => 'DeepSeek'] as $slug => $label): ?>
                                        <option value="<?php echo $slug; ?>"
                                            <?php echo ($settings['chat_ai_provider'] === $slug) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($label); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label small fw-bold" for="chat_ai_model">Model (optional)</label>
                                <input class="form-control" id="chat_ai_model" name="chat_ai_model"
                                       value="<?php echo htmlspecialchars((string)$settings['chat_ai_model']); ?>"
                                       placeholder="Leave blank for the provider default">
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label small fw-bold" for="chat_ai_key">API key</label>
                            <input class="form-control" type="password" id="chat_ai_key" name="chat_ai_key"
                                   autocomplete="new-password"
                                   placeholder="<?php echo $has_key ? 'A key is stored — leave blank to keep it' : 'Paste the provider API key'; ?>">
                            <div class="form-text">
                                <?php if ($has_key): ?>
                                    A key ending in <code><?php echo htmlspecialchars($key_tail); ?></code> is stored.
                                    It is never shown in full. Pasting a new key replaces it.
                                <?php else: ?>
                                    No key is stored.
                                <?php endif; ?>
                            </div>
                            <?php if ($has_key): ?>
                                <div class="form-check mt-2">
                                    <input class="form-check-input" type="checkbox" id="chat_ai_key_clear" name="chat_ai_key_clear">
                                    <label class="form-check-label small text-danger" for="chat_ai_key_clear">
                                        Delete the stored key
                                    </label>
                                </div>
                            <?php endif; ?>
                        </div>

                        <hr>
                        <div class="mb-3">
                            <label class="form-label small fw-bold" for="chat_welcome">Welcome message</label>
                            <textarea class="form-control" id="chat_welcome" name="chat_welcome" rows="2"><?php echo htmlspecialchars((string)$settings['chat_welcome']); ?></textarea>
                        </div>
                        <div class="mb-3">
                            <label class="form-label small fw-bold" for="chat_handoff">Handoff message</label>
                            <textarea class="form-control" id="chat_handoff" name="chat_handoff" rows="2"><?php echo htmlspecialchars((string)$settings['chat_handoff']); ?></textarea>
                            <div class="form-text">Shown to the customer when a person takes over.</div>
                        </div>

                        <button class="btn btn-primary" type="submit" name="save-chat-settings" value="1">Save settings</button>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="card">
                <div class="card-body">
                    <h5 class="card-title">How it works</h5>
                    <ol class="small text-muted ps-3 mb-0">
                        <li class="mb-2">A customer asks a question. If the assistant is on and a key is
                            stored, it answers first.</li>
                        <li class="mb-2">"Talk to a person" hands the conversation to your queue on the
                            Support Chat page, and the assistant stops replying.</li>
                        <li class="mb-2">Claim the conversation there to reply as yourself.</li>
                        <li>Closing it ends that thread; the customer's next question starts a new one.</li>
                    </ol>
                </div>
            </div>
        </div>
    </div>
</section>

    <?php include("../func/bc-admin-footer.php"); ?>
</body>
</html>
