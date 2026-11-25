<?php
require_once __DIR__ . '/../func/bc-secure-session.php';
 bc_secure_session_start();
/**
 * §41 — live support-chat endpoint for bc-admin agents.
 *
 * The agent half of the chat widget: SupportChat.php polls this endpoint so
 * agents can watch the queue and answer customer ticket chats in near-real
 * time, without a page reload per reply. Every action is tenant-scoped to
 * the signed-in admin's own vendor id and reuses the func/bc-support-chat.php
 * engine (same 4000-char cap, same unread bookkeeping, same claim/close
 * rules as the no-JS console).
 */
include("../func/bc-admin-config.php");
require_once(__DIR__ . "/../func/bc-support-chat.php");

header('Content-Type: application/json');

function bc_agent_chat_json($arr, $code = 200) {
    http_response_code($code);
    echo json_encode($arr);
    exit();
}

if (empty($get_logged_admin_details['id'])) {
    bc_agent_chat_json(array('ok' => false, 'error' => 'Not signed in.'), 403);
}
$vid = (int)$get_logged_admin_details['id'];
$agent_name = trim((string)($get_logged_admin_details['username'] ?? $get_logged_admin_details['email'] ?? 'agent'));

if (function_exists('bc_chat_schema_ready') && !bc_chat_schema_ready() && function_exists('bc_chat_ensure_schema')) {
    bc_chat_ensure_schema();
}

$data = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($data)) $data = $_POST;
$action = strtolower(trim((string)($data['action'] ?? '')));
$thread_id = (int)($data['thread_id'] ?? 0);

function bc_agent_chat_public_thread($t) {
    if (!$t) return null;
    return array(
        'id' => (int)$t['id'],
        'username' => (string)($t['username'] ?? ''),
        'ticket_no' => (string)($t['ticket_no'] ?? ''),
        'status' => (string)($t['status'] ?? ''),
        'ai_enabled' => (int)($t['ai_enabled'] ?? 0),
        'assigned_admin' => (string)($t['assigned_admin'] ?? ''),
        'unread_for_agent' => (int)($t['unread_for_agent'] ?? 0),
        'last_message_at' => (string)($t['last_message_at'] ?? ''),
    );
}
function bc_agent_chat_public_messages($rows) {
    $out = array();
    foreach ((array)$rows as $m) {
        $out[] = array(
            'id' => (int)$m['id'],
            'author_type' => (string)$m['author_type'],
            'author_name' => (string)($m['author_name'] ?: $m['author_type']),
            'body' => (string)$m['body'],
            'created_at' => (string)$m['created_at'],
        );
    }
    return $out;
}

if ($action === 'queue') {
    $queue = bc_chat_agent_queue($vid);
    $open_threads = array();
    if ($connection_server instanceof mysqli) {
        $oq = mysqli_query($connection_server, "SELECT * FROM sas_chat_threads
            WHERE vendor_id='$vid' AND status='open'
            ORDER BY last_message_at DESC LIMIT 50");
        if ($oq) while ($or = mysqli_fetch_assoc($oq)) $open_threads[] = $or;
    }
    bc_agent_chat_json(array(
        'ok' => true,
        'pending' => array_map('bc_agent_chat_public_thread', $queue['pending']),
        'active' => array_map('bc_agent_chat_public_thread', $queue['active']),
        'open_threads' => array_map('bc_agent_chat_public_thread', $open_threads),
    ));
}

if ($thread_id <= 0) bc_agent_chat_json(array('ok' => false, 'error' => 'Missing thread.'), 400);
$thread = bc_chat_thread_get($vid, $thread_id);
if (!$thread) bc_agent_chat_json(array('ok' => false, 'error' => 'Conversation not found.'), 404);

if ($action === 'thread') {
    // Opening a conversation marks it read for the agent side.
    mysqli_query($connection_server, "UPDATE sas_chat_threads SET unread_for_agent=0 WHERE id='$thread_id' AND vendor_id='$vid'");
    $after_id = (int)($data['after_id'] ?? 0);
    bc_agent_chat_json(array(
        'ok' => true,
        'thread' => bc_agent_chat_public_thread(bc_chat_thread_get($vid, $thread_id)),
        'messages' => bc_agent_chat_public_messages(bc_chat_messages($vid, $thread_id, $after_id, 300)),
    ));
}

if ($action === 'reply') {
    $body = trim((string)($data['body'] ?? ''));
    if ($body === '') bc_agent_chat_json(array('ok' => false, 'error' => 'Write a reply first.'), 400);
    if ((string)$thread['status'] === 'closed') bc_agent_chat_json(array('ok' => false, 'error' => 'This conversation is closed.'), 400);
    $id = bc_chat_post_message($vid, $thread_id, 'agent', $agent_name, $body);
    if (!$id) bc_agent_chat_json(array('ok' => false, 'error' => 'Reply was empty or too long.'), 400);
    if ((int)($thread['ai_enabled'] ?? 0) === 1 || (int)($thread['unread_for_agent'] ?? 0) > 0) {
        mysqli_query($connection_server, "UPDATE sas_chat_threads SET ai_enabled=0, unread_for_agent=0 WHERE id='$thread_id' AND vendor_id='$vid'");
    }
    bc_agent_chat_json(array(
        'ok' => true,
        'messages' => bc_agent_chat_public_messages(bc_chat_messages($vid, $thread_id, $id - 1, 5)),
        'thread' => bc_agent_chat_public_thread(bc_chat_thread_get($vid, $thread_id)),
    ));
}

if ($action === 'claim') {
    $ok = bc_chat_claim_thread($vid, $thread_id, $vid, $agent_name);
    bc_agent_chat_json(array(
        'ok' => $ok,
        'error' => $ok ? '' : 'Someone else already claimed it.',
        'thread' => bc_agent_chat_public_thread(bc_chat_thread_get($vid, $thread_id)),
    ));
}

if ($action === 'close') {
    bc_chat_close_thread($vid, $thread_id, $agent_name);
    bc_agent_chat_json(array('ok' => true, 'thread' => bc_agent_chat_public_thread(bc_chat_thread_get($vid, $thread_id))));
}

if ($action === 'pause_ai') {
    mysqli_query($connection_server, "UPDATE sas_chat_threads SET ai_enabled=0 WHERE id='$thread_id' AND vendor_id='$vid'");
    bc_chat_event($vid, $thread_id, 'ai_paused', $agent_name, '');
    bc_agent_chat_json(array('ok' => true, 'thread' => bc_agent_chat_public_thread(bc_chat_thread_get($vid, $thread_id))));
}

bc_agent_chat_json(array('ok' => false, 'error' => 'Unknown action.'), 400);
