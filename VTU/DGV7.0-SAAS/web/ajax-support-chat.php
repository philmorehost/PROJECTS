<?php
/**
 * DGV7 phase 2 — support chat JSON endpoint.
 *
 * The widget polls here. Everything privileged happens on this side: the API key never leaves the
 * server, and the user identity is taken from the session, never from the request.
 *
 * POST only. Actions: open | send | poll | escalate | close.
 */

session_start();
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
