<?php
require_once __DIR__ . '/../func/bc-secure-session.php';

/**
 * DGV7 phase 2 — support chat JSON endpoint.
 *
 * The widget polls here. Everything privileged happens on this side: the API key never leaves the
 * server, and the user identity is taken from the session, never from the request.
 *
 * POST only. Actions: open | send | poll | escalate | close.
 */

bc_secure_session_start();
include_once("../func/bc-config.php");
require_once(__DIR__ . '/../func/bc-support-chat.php');

function bc_chat_json($payload, $status = 200) {
    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: application/json');
        // The transcript is private: no shared cache, any layer.
        header('Cache-Control: no-store, private');
        header('X-Content-Type-Options: nosniff');
    }
    echo json_encode($payload);
    exit();
}

// --- identity -------------------------------------------------------------
// Bound to the session. A user_id in the request body is ignored on purpose; CI greps for it.
if (!isset($get_logged_user_details) || !is_array($get_logged_user_details) || empty($get_logged_user_details['id'])) {
    bc_chat_json(['ok' => false, 'error' => 'Not signed in.'], 401);
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    bc_chat_json(['ok' => false, 'error' => 'POST required.'], 405);
}

$bc_chat_vendor_id = (int)($get_logged_user_details['vendor_id'] ?? 0);
$bc_chat_user_id   = (int)$get_logged_user_details['id'];
$bc_chat_username  = (string)($get_logged_user_details['username'] ?? '');

if (function_exists('bc_chat_schema_ready') && !bc_chat_schema_ready() && function_exists('bc_chat_ensure_schema')) {
    bc_chat_ensure_schema();
}
if ($bc_chat_vendor_id <= 0 || !bc_chat_widget_enabled($bc_chat_vendor_id)) {
    bc_chat_json(['ok' => false, 'error' => 'Chat is not available.'], 404);
}

$raw = file_get_contents('php://input');
$data = json_decode($raw, true);
if (!is_array($data)) $data = $_POST;

$action = strtolower(trim((string)($data['action'] ?? 'poll')));
$allowed = ['open', 'send', 'poll', 'escalate', 'close'];
if (!in_array($action, $allowed, true)) {
    bc_chat_json(['ok' => false, 'error' => 'Unknown action.'], 400);
}

// --- open / resume --------------------------------------------------------
if ($action === 'open') {
    $thread = bc_chat_open_thread($bc_chat_vendor_id, $bc_chat_user_id, $bc_chat_username);
    if (!$thread) bc_chat_json(['ok' => false, 'error' => 'Could not open a conversation.'], 500);

    $messages = bc_chat_messages($bc_chat_vendor_id, (int)$thread['id'], 0, 100);
    $settings = bc_chat_settings($bc_chat_vendor_id);

    // The welcome line is only sent for a brand-new thread, so a returning user does not get
    // greeted again on every page load.
    if (!$messages) {
        bc_chat_post_message($bc_chat_vendor_id, (int)$thread['id'], 'system', 'Support', (string)$settings['chat_welcome']);
        $messages = bc_chat_messages($bc_chat_vendor_id, (int)$thread['id'], 0, 100);
    }

    bc_chat_json([
        'ok'       => true,
        'thread'   => bc_chat_public_thread($thread),
        'messages' => bc_chat_public_messages($messages),
    ]);
}

// --- everything below needs an existing thread ----------------------------
$thread_id = (int)($data['thread_id'] ?? 0);
if ($thread_id <= 0) bc_chat_json(['ok' => false, 'error' => 'Missing conversation.'], 400);

// 404 for another tenant's thread or for someone else's conversation: a probing caller learns
// nothing about whether the id exists.
$thread = bc_chat_thread_get($bc_chat_vendor_id, $thread_id);
if (!$thread || (int)$thread['user_id'] !== $bc_chat_user_id) {
    bc_chat_json(['ok' => false, 'error' => 'Conversation not found.'], 404);
}

if ($action === 'send') {
    $body = trim((string)($data['message'] ?? ''));
    if ($body === '') bc_chat_json(['ok' => false, 'error' => 'Empty message.'], 400);
    if (mb_strlen_safe($body) > 4000) bc_chat_json(['ok' => false, 'error' => 'Message is too long.'], 400);
    if ($thread['status'] === 'closed') bc_chat_json(['ok' => false, 'error' => 'Conversation is closed.'], 409);

    $id = bc_chat_post_message($bc_chat_vendor_id, $thread_id, 'user', $bc_chat_username, $body);
    if (!$id) bc_chat_json(['ok' => false, 'error' => 'Could not send that message.'], 500);

    // Re-read: posting bumped last_message_at, and a reply may change status.
    $thread = bc_chat_thread_get($bc_chat_vendor_id, $thread_id);
    
// Secure read-only account, transaction & website context provider for the AI support
// assistant (never exposes passwords, PINs, or secrets). Registered as a callback so
// func/bc-support-chat.php stays strictly separated from user/ledger tables.
if (!function_exists('bc_chat_user_context')) {
    function bc_chat_user_context($vendor_id, $user_id, $latest_query = '') {
        global $connection_server;
        if (!($connection_server instanceof mysqli)) return '';
        $vendor_id = (int)$vendor_id; $user_id = (int)$user_id;
        if ($vendor_id <= 0 || $user_id <= 0) return '';

        $q = @mysqli_query($connection_server, "SELECT username, firstname, lastname, email, phone_number, balance, account_level, kyc_status, kyc_reject_reason, is_blocked, reg_date FROM sas_users WHERE vendor_id='$vendor_id' AND id='$user_id' LIMIT 1");
        $u = $q ? mysqli_fetch_assoc($q) : null;
        if (!$u) return '';

        $uname = mysqli_real_escape_string($connection_server, (string)$u['username']);
        $lvl_map = [1 => 'Smart Earner', 2 => 'Agent', 3 => 'API / Vendor'];
        $kyc_map = [0 => 'Unverified', 1 => 'Pending Admin Review', 2 => 'Verified', 3 => 'Rejected'];
        $kyc_st = (int)($u['kyc_status'] ?? 0);

        $out = [];
        // 1. Website / Brand info
        $vq = @mysqli_query($connection_server, "SELECT company_name, website_url, email, phone_number FROM sas_vendors WHERE id='$vendor_id' LIMIT 1");
        $vrow = $vq ? mysqli_fetch_assoc($vq) : null;
        $sq = @mysqli_query($connection_server, "SELECT site_title FROM sas_site_details WHERE vendor_id='$vendor_id' LIMIT 1");
        $srow = $sq ? mysqli_fetch_assoc($sq) : null;
        $brand = trim((string)($srow['site_title'] ?? ($vrow['company_name'] ?? 'Platform')));
        $out[] = "Website: {$brand}"
            . (!empty($vrow['website_url']) ? " ({$vrow['website_url']})" : '')
            . (!empty($vrow['email']) ? " | Support Email: {$vrow['email']}" : '')
            . (!empty($vrow['phone_number']) ? " | Support Phone: {$vrow['phone_number']}" : '');

        // 2. User account summary (strictly non-credential fields)
        $full_name = trim((string)($u['firstname'] ?? '') . ' ' . (string)($u['lastname'] ?? ''));
        $out[] = "Customer: " . ($full_name !== '' ? $full_name . " (@{$u['username']})" : "@{$u['username']}")
            . " | Tier: " . ($lvl_map[(int)($u['account_level'] ?? 1)] ?? 'Smart')
            . " | Wallet Balance: NGN " . number_format((float)($u['balance'] ?? 0), 2)
            . " | Account Status: " . ((int)($u['is_blocked'] ?? 0) === 1 ? 'Blocked/Restricted' : 'Active');
        $out[] = "KYC Status: " . ($kyc_map[$kyc_st] ?? 'Unknown')
            . ($kyc_st === 3 && !empty($u['kyc_reject_reason']) ? " (Reason: " . substr((string)$u['kyc_reject_reason'], 0, 180) . ")" : '');

        // 3. Specific reference lookup if user mentioned a ref in their message
        $latest_query = (string)$latest_query;
        if ($latest_query !== '' && preg_match('/\b([A-Za-z0-9_-]{6,40})\b/', $latest_query)) {
            preg_match_all('/\b([A-Za-z0-9_-]{6,40})\b/', $latest_query, $rm);
            $cands = array_slice(array_unique($rm[1] ?? []), 0, 3);
            foreach ($cands as $cand) {
                if (!preg_match('/[0-9]/', $cand)) continue;
                $cand_esc = mysqli_real_escape_string($connection_server, $cand);
                $rq = @mysqli_query($connection_server, "SELECT reference, type_alternative, description, amount, discounted_amount, status, date FROM sas_transactions WHERE vendor_id='$vendor_id' AND username='$uname' AND reference LIKE '%$cand_esc%' ORDER BY id DESC LIMIT 2");
                if ($rq && mysqli_num_rows($rq) > 0) {
                    $out[] = "Matched Transaction(s) for '{$cand}':";
                    while ($tr = mysqli_fetch_assoc($rq)) {
                        $st = (int)$tr['status'] === 1 ? 'Successful' : ((int)$tr['status'] === 2 ? 'Pending' : 'Failed');
                        $out[] = "  - Ref {$tr['reference']}: {$tr['type_alternative']} | NGN " . number_format((float)($tr['discounted_amount'] ?: $tr['amount']), 2) . " | {$st} | {$tr['date']} | " . substr((string)$tr['description'], 0, 100);
                    }
                }
            }
        }

        // 4. Recent transactions (last 8)
        $q2 = @mysqli_query($connection_server, "SELECT reference, type_alternative, description, amount, discounted_amount, status, date FROM sas_transactions WHERE vendor_id='$vendor_id' AND username='$uname' ORDER BY id DESC LIMIT 8");
        if ($q2 && mysqli_num_rows($q2) > 0) {
            $out[] = "Recent Transactions:";
            while ($r = mysqli_fetch_assoc($q2)) {
                $st = (int)$r['status'] === 1 ? 'Successful' : ((int)$r['status'] === 2 ? 'Pending' : 'Failed');
                $amt = (float)($r['discounted_amount'] ?: $r['amount']);
                $out[] = "  - [{$r['reference']}] " . ($r['type_alternative'] ?: 'service') . " · NGN " . number_format($amt, 2) . " · {$st} · {$r['date']}" . (!empty($r['description']) ? " (" . substr((string)$r['description'], 0, 70) . ")" : "");
            }
        } else {
            $out[] = "Recent Transactions: None yet.";
        }

        // 5. Recent manual/submitted payment orders (last 4)
        $q3 = @mysqli_query($connection_server, "SELECT reference, amount, status, date FROM sas_submitted_payments WHERE vendor_id='$vendor_id' AND username='$uname' ORDER BY id DESC LIMIT 4");
        if ($q3 && mysqli_num_rows($q3) > 0) {
            $out[] = "Recent Payment / Funding Orders:";
            while ($p = mysqli_fetch_assoc($q3)) {
                $pst = (int)$p['status'] === 1 ? 'Approved' : ((int)$p['status'] === 2 ? 'Pending Review' : 'Rejected');
                $out[] = "  - [{$p['reference']}] NGN " . number_format((float)$p['amount'], 2) . " · {$pst} · {$p['date']}";
            }
        }

        // 6. Recent blocked KYC/KYB upload scan (if any) so AI can explain why an upload was rejected
        $q4 = @mysqli_query($connection_server, "SELECT doc_key, verdict, reason, created_at FROM sas_kyc_av_logs WHERE vendor_id='$vendor_id' AND user_id='$user_id' AND verdict='blocked' ORDER BY id DESC LIMIT 2");
        if ($q4 && mysqli_num_rows($q4) > 0) {
            $out[] = "Recent Blocked Document Upload(s):";
            while ($av = mysqli_fetch_assoc($q4)) {
                $out[] = "  - {$av['doc_key']} blocked at {$av['created_at']}: " . substr((string)$av['reason'], 0, 120);
            }
        }

        return implode("\n", $out);
    }
}
$GLOBALS['bc_chat_context_provider'] = 'bc_chat_user_context';

    bc_chat_ai_reply($bc_chat_vendor_id, $thread);

    $messages = bc_chat_messages($bc_chat_vendor_id, $thread_id, 0, 100);
    $thread = bc_chat_thread_get($bc_chat_vendor_id, $thread_id);
    bc_chat_json([
        'ok'       => true,
        'thread'   => bc_chat_public_thread($thread),
        'messages' => bc_chat_public_messages($messages),
    ]);
}

if ($action === 'poll') {
    $after = (int)($data['after_id'] ?? 0);
    // Polling is how the customer learns an agent replied; clear their unread counter when the
    // transcript is actually handed back, not when they open the panel.
    if ((int)$thread['unread_for_user'] > 0) {
        mysqli_query($connection_server, "UPDATE sas_chat_threads SET unread_for_user=0
            WHERE id='$thread_id' AND vendor_id='$bc_chat_vendor_id'");
        $thread['unread_for_user'] = 0;
    }
    bc_chat_json([
        'ok'       => true,
        'thread'   => bc_chat_public_thread($thread),
        'messages' => bc_chat_public_messages(bc_chat_messages($bc_chat_vendor_id, $thread_id, $after, 100)),
    ]);
}

if ($action === 'escalate') {
    bc_chat_escalate($bc_chat_vendor_id, $thread_id, 'user_request');
    bc_chat_post_message($bc_chat_vendor_id, $thread_id, 'system', 'Support',
        'Thanks — a member of our team will take over from here.');
    $thread = bc_chat_thread_get($bc_chat_vendor_id, $thread_id);
    bc_chat_json([
        'ok'       => true,
        'thread'   => bc_chat_public_thread($thread),
        'messages' => bc_chat_public_messages(bc_chat_messages($bc_chat_vendor_id, $thread_id, 0, 100)),
    ]);
}

if ($action === 'close') {
    bc_chat_close_thread($bc_chat_vendor_id, $thread_id, 'user');
    bc_chat_json(['ok' => true, 'closed' => true]);
}

bc_chat_json(['ok' => false, 'error' => 'Unknown action.'], 400);
