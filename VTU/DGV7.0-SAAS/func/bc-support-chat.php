<?php
/**
 * DGV7 phase 2 — support chat engine.
 *
 * The rules this file exists to enforce:
 *
 *   1. One live thread per user per tenant. A closed thread is not resumed; the user's next
 *      message starts a fresh one, so an old transcript cannot be reopened by accident.
 *   2. The AI only speaks while a thread is 'open' AND ai_enabled=1. Escalation and claiming both
 *      clear the flag, so there is no state in which the assistant talks over a human agent.
 *   3. A provider failure still escalates to a human. A dead AI must not look like a dead chat.
 *   4. Every read and write is scoped by vendor_id. A thread id from another tenant resolves to
 *      nothing rather than to someone else's conversation.
 *
 * This file moves no money and writes no ledgers. The tests assert that.
 */

require_once(__DIR__ . '/bc-support-chat-schema.php');

if (!function_exists('mb_strlen_safe')) {
    /**
     * mbstring is not guaranteed (the shipped code runs under `php -n` in CI, and under the WASM
     * harness). Fall back to strlen, which gives the same answer for the ASCII that dominates
     * support traffic and never fatals when the extension is missing.
     */
    function mb_strlen_safe($s) {
        return function_exists('mb_strlen') ? mb_strlen($s) : strlen($s);
    }
}

if (!function_exists('bc_chat_thread_for_user')) {
    /** The user's live thread for this tenant, or null. Closed threads are not resumed. */
    function bc_chat_thread_for_user($vendor_id, $user_id) {
        global $connection_server;
        $vendor_id = (int)$vendor_id;
        $user_id   = (int)$user_id;
        if ($vendor_id <= 0 || $user_id <= 0 || !($connection_server instanceof mysqli)) return null;

        $q = mysqli_query($connection_server, "SELECT * FROM sas_chat_threads
            WHERE vendor_id='$vendor_id' AND user_id='$user_id' AND status<>'closed'
            ORDER BY id DESC LIMIT 1");
        if (!$q || mysqli_num_rows($q) !== 1) return null;
        return mysqli_fetch_assoc($q);
    }
}

if (!function_exists('bc_chat_thread_get')) {
    /** Fetch one thread, scoped to the tenant. Returns null for another vendor's id. */
    function bc_chat_thread_get($vendor_id, $thread_id) {
        global $connection_server;
        $vendor_id = (int)$vendor_id;
        $thread_id = (int)$thread_id;
        if ($vendor_id <= 0 || $thread_id <= 0 || !($connection_server instanceof mysqli)) return null;

        $q = mysqli_query($connection_server, "SELECT * FROM sas_chat_threads
            WHERE id='$thread_id' AND vendor_id='$vendor_id' LIMIT 1");
        if (!$q || mysqli_num_rows($q) !== 1) return null;
        return mysqli_fetch_assoc($q);
    }
}

if (!function_exists('bc_chat_open_thread')) {
    /**
     * Open a thread for this user, reusing the live one if present.
     *
     * ai_enabled is seeded from the tenant settings at creation time and never re-read afterwards:
     * settings changed mid-conversation must not switch the assistant back on under an agent.
     */
    function bc_chat_open_thread($vendor_id, $user_id, $username, $subject = '') {
        global $connection_server;
        $vendor_id = (int)$vendor_id;
        $user_id   = (int)$user_id;
        if ($vendor_id <= 0 || $user_id <= 0 || !($connection_server instanceof mysqli)) return null;

        $existing = bc_chat_thread_for_user($vendor_id, $user_id);
        if ($existing) return $existing;

        $settings = bc_chat_settings($vendor_id);
        $ai = 0;
        if ($settings['chat_ai_enabled'] === '1') {
            if (trim((string)$settings['chat_ai_key']) !== '') {
                $ai = 1;
            } elseif (function_exists('getSuperAdminOption')) {
                $engine_file = __DIR__ . '/bc-ai-engine.php';
                if (file_exists($engine_file)) require_once($engine_file);
                if (function_exists('bc_ai_platform_status')) {
                    $plat = bc_ai_platform_status($connection_server, $vendor_id, 'support_reply');
                    if (!empty($plat['available'])) $ai = 1;
                }
            }
        }

        $user_esc = mysqli_real_escape_string($connection_server, (string)$username);
        $subj_esc = mysqli_real_escape_string($connection_server, substr(trim((string)$subject), 0, 255));
        if ($subj_esc === '') $subj_esc = 'Support request';

        $ok = mysqli_query($connection_server, "INSERT INTO sas_chat_threads
            (vendor_id, user_id, username, subject, status, ai_enabled, created_at, last_message_at, last_message_by)
            VALUES ('$vendor_id', '$user_id', '$user_esc', '$subj_esc', 'open', '$ai', NOW(), NOW(), 'system')");
        if (!$ok) return null;

        $id = mysqli_insert_id($connection_server);
        if (!$id) return null;
        $ticket_no = 'SUP-' . str_pad((string)$id, 6, '0', STR_PAD_LEFT);
        mysqli_query($connection_server, "UPDATE sas_chat_threads SET ticket_no='$ticket_no' WHERE id='$id'");
        return bc_chat_thread_get($vendor_id, $id);
    }
}

if (!function_exists('bc_chat_messages')) {
    /**
     * Transcript for one thread, oldest first.
     *
     * @param int $after_id  only messages newer than this (the widget polls with the last id it has)
     */
    function bc_chat_messages($vendor_id, $thread_id, $after_id = 0, $limit = 200) {
        global $connection_server;
        $vendor_id = (int)$vendor_id;
        $thread_id = (int)$thread_id;
        $after_id  = (int)$after_id;
        $limit     = max(1, min(500, (int)$limit));
        $rows = [];
        if ($vendor_id <= 0 || $thread_id <= 0 || !($connection_server instanceof mysqli)) return $rows;

        $q = mysqli_query($connection_server, "SELECT * FROM sas_chat_messages
            WHERE thread_id='$thread_id' AND vendor_id='$vendor_id' AND id > $after_id
            ORDER BY id ASC LIMIT $limit");
        if (!$q) return $rows;
        while ($r = mysqli_fetch_assoc($q)) $rows[] = $r;
        return $rows;
    }
}

if (!function_exists('bc_chat_post_message')) {
    /**
     * Append one message and update the thread bookkeeping.
     *
     * Returns the new message id, or 0 when the body is empty or over the cap. The 4000-character
     * cap is enforced here rather than in the endpoint so every caller inherits it.
     */
    function bc_chat_post_message($vendor_id, $thread_id, $author_type, $author_name, $body) {
        global $connection_server;
        $vendor_id = (int)$vendor_id;
        $thread_id = (int)$thread_id;

        $author_type = strtolower(trim((string)$author_type));
        if (!in_array($author_type, ['user', 'ai', 'agent', 'system'], true)) return 0;

        $body = trim((string)$body);
        if ($body === '' || mb_strlen_safe($body) > 4000) return 0;
        if ($vendor_id <= 0 || $thread_id <= 0 || !($connection_server instanceof mysqli)) return 0;

        // Refuse to write into another tenant's thread even if the caller got the id wrong.
        if (!bc_chat_thread_get($vendor_id, $thread_id)) return 0;

        $body_esc = mysqli_real_escape_string($connection_server, $body);
        $name_esc = mysqli_real_escape_string($connection_server, substr((string)$author_name, 0, 191));

        $ok = mysqli_query($connection_server, "INSERT INTO sas_chat_messages
            (thread_id, vendor_id, author_type, author_name, body, created_at)
            VALUES ('$thread_id', '$vendor_id', '$author_type', '$name_esc', '$body_esc', NOW())");
        if (!$ok) return 0;

        $id = mysqli_insert_id($connection_server);

        // Unread counters are per side: a message from the customer is unread FOR THE AGENT.
        if ($author_type === 'user') {
            mysqli_query($connection_server, "UPDATE sas_chat_threads
                SET last_message_at=NOW(), last_message_by='user', unread_for_agent=unread_for_agent+1
                WHERE id='$thread_id' AND vendor_id='$vendor_id'");
        } elseif ($author_type === 'agent') {
            mysqli_query($connection_server, "UPDATE sas_chat_threads
                SET last_message_at=NOW(), last_message_by='agent', unread_for_user=unread_for_user+1
                WHERE id='$thread_id' AND vendor_id='$vendor_id'");
        } else {
            mysqli_query($connection_server, "UPDATE sas_chat_threads
                SET last_message_at=NOW(), last_message_by='$author_type'
                WHERE id='$thread_id' AND vendor_id='$vendor_id'");
        }

        return (int)$id;
    }
}

if (!function_exists('bc_chat_event')) {
    /** Record an operational event. Never shown in the customer transcript. */
    function bc_chat_event($vendor_id, $thread_id, $event, $actor = '', $detail = '') {
        global $connection_server;
        $vendor_id = (int)$vendor_id;
        $thread_id = (int)$thread_id;
        if ($vendor_id <= 0 || $thread_id <= 0 || !($connection_server instanceof mysqli)) return false;

        $event  = mysqli_real_escape_string($connection_server, substr((string)$event, 0, 64));
        $actor  = mysqli_real_escape_string($connection_server, substr((string)$actor, 0, 191));
        $detail = mysqli_real_escape_string($connection_server, substr((string)$detail, 0, 255));

        return (bool)mysqli_query($connection_server, "INSERT INTO sas_chat_events
            (thread_id, vendor_id, event, actor, detail, created_at)
            VALUES ('$thread_id', '$vendor_id', '$event', '$actor', '$detail', NOW())");
    }
}

if (!function_exists('bc_chat_escalate')) {
    /**
     * Hand the thread to a human and silence the assistant.
     *
     * Called from the widget's "Talk to a person" AND from the AI failure path, which is why it is
     * idempotent: escalating an already-pending thread is a no-op, not an error.
     */
    function bc_chat_escalate($vendor_id, $thread_id, $reason = 'user_request') {
        global $connection_server;
        $vendor_id = (int)$vendor_id;
        $thread_id = (int)$thread_id;

        $thread = bc_chat_thread_get($vendor_id, $thread_id);
        if (!$thread) return false;
        if ($thread['status'] === 'pending_agent' || $thread['status'] === 'with_agent') return true;

        $ok = mysqli_query($connection_server, "UPDATE sas_chat_threads
            SET status='pending_agent', ai_enabled=0
            WHERE id='$thread_id' AND vendor_id='$vendor_id'");
        if (!$ok) return false;

        bc_chat_event($vendor_id, $thread_id, 'escalated', 'system', (string)$reason);
        return true;
    }
}

if (!function_exists('bc_chat_claim_thread')) {
    /**
     * An agent takes the thread. Posts the handoff line so the customer sees a human arrive.
     *
     * Returns false when the thread is already someone else's: two agents must not both believe
     * they own the conversation.
     */
    function bc_chat_claim_thread($vendor_id, $thread_id, $admin_id, $admin_name) {
        global $connection_server;
        $vendor_id = (int)$vendor_id;
        $thread_id = (int)$thread_id;
        $admin_id  = (int)$admin_id;
        if ($vendor_id <= 0 || $thread_id <= 0 || $admin_id <= 0) return false;

        $thread = bc_chat_thread_get($vendor_id, $thread_id);
        if (!$thread) return false;
        if ((int)($thread['assigned_admin'] ?: 0) === $admin_id) return true;
        if ((string)$thread['assigned_admin'] !== '' && (int)$thread['assigned_admin'] !== $admin_id) return false;

        $admin_esc = mysqli_real_escape_string($connection_server, (string)$admin_id);
        $ok = mysqli_query($connection_server, "UPDATE sas_chat_threads
            SET status='with_agent', ai_enabled=0, assigned_admin='$admin_esc', unread_for_agent=0
            WHERE id='$thread_id' AND vendor_id='$vendor_id'
              AND (assigned_admin='' OR assigned_admin IS NULL OR assigned_admin='$admin_esc')");
        if (!$ok || mysqli_affected_rows($connection_server) < 1) return false;

        $settings = bc_chat_settings($vendor_id);
        $handoff  = $settings['chat_handoff'] !== '' ? $settings['chat_handoff'] : 'A member of our team will continue from here.';
        bc_chat_post_message($vendor_id, $thread_id, 'system', 'Support', $handoff);
        bc_chat_event($vendor_id, $thread_id, 'claimed', (string)$admin_name, 'agent ' . $admin_id);
        return true;
    }
}

if (!function_exists('bc_chat_close_thread')) {
    /**
     * Close the thread. The customer's next message opens a new one; the transcript stays readable
     * in the console for audit.
     */
    function bc_chat_close_thread($vendor_id, $thread_id, $actor = 'agent') {
        global $connection_server;
        $vendor_id = (int)$vendor_id;
        $thread_id = (int)$thread_id;

        $thread = bc_chat_thread_get($vendor_id, $thread_id);
        if (!$thread) return false;
        if ($thread['status'] === 'closed') return true;

        $ok = mysqli_query($connection_server, "UPDATE sas_chat_threads
            SET status='closed', ai_enabled=0, closed_at=NOW(), unread_for_agent=0
            WHERE id='$thread_id' AND vendor_id='$vendor_id'");
        if (!$ok) return false;

        bc_chat_event($vendor_id, $thread_id, 'closed', (string)$actor, '');
        return true;
    }
}

if (!function_exists('bc_chat_agent_queue')) {
    /**
     * The agent's console lists: waiting for a human, and currently with a human.
     *
     * Scoped by tenant. The individual agent id is NOT used to filter: any agent of the tenant may
     * see the queue, and claiming is what takes ownership.
     */
    function bc_chat_agent_queue($vendor_id, $limit = 100) {
        global $connection_server;
        $vendor_id = (int)$vendor_id;
        $limit = max(1, min(500, (int)$limit));
        $out = ['pending' => [], 'active' => []];
        if ($vendor_id <= 0 || !($connection_server instanceof mysqli)) return $out;

        $q = mysqli_query($connection_server, "SELECT * FROM sas_chat_threads
            WHERE vendor_id='$vendor_id' AND status='pending_agent'
            ORDER BY last_message_at ASC LIMIT $limit");
        if ($q) while ($r = mysqli_fetch_assoc($q)) $out['pending'][] = $r;

        $q = mysqli_query($connection_server, "SELECT * FROM sas_chat_threads
            WHERE vendor_id='$vendor_id' AND status='with_agent'
            ORDER BY last_message_at DESC LIMIT $limit");
        if ($q) while ($r = mysqli_fetch_assoc($q)) $out['active'][] = $r;

        return $out;
    }
}

if (!function_exists('bc_chat_widget_enabled')) {
    /**
     * Should the widget render on this page at all?
     *
     * Two independent gates: the tenant switched chat on, and the schema is usable. A tenant who
     * has not paid for chat gets nothing rendered; a tenant with a broken migration gets no widget
     * rather than a page-level error.
     */
    function bc_chat_widget_enabled($vendor_id) {
        $vendor_id = (int)$vendor_id;
        if ($vendor_id <= 0) return false;
        $settings = bc_chat_settings($vendor_id);
        if ($settings['chat_enabled'] !== '1') return false;
        return bc_chat_schema_ready();
    }
}

if (!function_exists('bc_chat_ai_system_prompt')) {
    /**
     * The assistant's instructions.
     *
     * Uses verified server-side account, transaction, and website context supplied by the
     * web layer so customers get real answers immediately instead of unnecessary escalation,
     * while strictly blocking unverified guesses, refund approvals, or credential requests.
     */
    function bc_chat_ai_system_prompt() {
        return "You are the intelligent customer support assistant for this fintech/VTU platform.\n"
            . "Answer clearly, politely, and accurately in the customer's language.\n"
            . "When a [Customer account context] block is supplied below, USE IT to answer questions "
            . "directly about the customer's verified profile, KYC status, wallet balance, recent "
            . "transactions (reference, service, amount, status, date), deposit orders, and website services/navigation.\n"
            . "Never do any of these:\n"
            . "- invent data or state, estimate or confirm an account balance, transaction amount or exchange rate outside the verified [Customer account context] provided below;\n"
            . "- promise, approve or estimate a refund, reversal or credit;\n"
            . "- ask for a password, PIN, OTP, API key, card number or any credential;\n"
            . "- claim an action or manual adjustment was performed on the account.\n"
            . "Only reply with exactly ESCALATE if the customer explicitly asks to speak to a human, "
            . "requests a manual refund/credit/account unlock, or the question cannot be answered from "
            . "the verified [Customer account context] and platform knowledge.";
    }
}

if (!function_exists('bc_chat_ai_direct')) {
    /**
     * Direct provider call, used when AIEngine is unavailable.
     *
     * Keeping a second path is deliberate: AIEngine lives in the admin application and is not
     * guaranteed to be present in every edition/build. If both paths fail the caller escalates.
     */
    function bc_chat_ai_direct($provider, $model, $api_key, $prompt, $system) {
        $provider = strtolower(trim((string)$provider));
        if ($api_key === '' || !function_exists('curl_init')) return null;

        $payload = null;
        $url = '';
        $headers = ['Content-Type: application/json'];

        if ($provider === 'gemini') {
            $model = $model !== '' ? $model : 'gemini-2.0-flash';
            $url = 'https://generativelanguage.googleapis.com/v1beta/models/'
                . rawurlencode($model) . ':generateContent?key=' . rawurlencode($api_key);
            $payload = [
                'systemInstruction' => ['parts' => [['text' => $system]]],
                'contents' => [['role' => 'user', 'parts' => [['text' => $prompt]]]],
                'generationConfig' => ['temperature' => 0.3, 'maxOutputTokens' => 500],
            ];
        } elseif ($provider === 'deepseek') {
            $model = $model !== '' ? $model : 'deepseek-chat';
            $url = 'https://api.deepseek.com/chat/completions';
            $headers[] = 'Authorization: Bearer ' . $api_key;
            $payload = [
                'model' => $model,
                'messages' => [
                    ['role' => 'system', 'content' => $system],
                    ['role' => 'user', 'content' => $prompt],
                ],
                'temperature' => 0.3,
                'max_tokens' => 500,
            ];
        } else {
            return null;
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_TIMEOUT => 20,
            CURLOPT_CONNECTTIMEOUT => 8,
        ]);
        $raw = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($raw === false || $code < 200 || $code >= 300) return null;

        $data = json_decode($raw, true);
        if (!is_array($data)) return null;
        return bc_chat_ai_extract_text($provider, $data);
    }
}

if (!function_exists('bc_chat_ai_extract_text')) {
    /** Pull the assistant text out of either provider's response envelope. */
    function bc_chat_ai_extract_text($provider, array $data) {
        if ($provider === 'gemini') {
            $text = $data['candidates'][0]['content']['parts'][0]['text'] ?? '';
            return is_string($text) ? trim($text) : '';
        }
        $text = $data['choices'][0]['message']['content'] ?? '';
        return is_string($text) ? trim($text) : '';
    }
}

if (!function_exists('bc_chat_ai_call')) {
    /**
     * Ask the platform AI engine (Super Admin in SAAS with token billing, or direct owner API
     * key in NON-SAAS) to answer the customer's question, falling back to any legacy per-tenant
     * chat_ai_key if present. Returns reply string or null on failure.
     */
    function bc_chat_ai_call($vendor_id, $prompt) {
        global $connection_server;
        $vendor_id = (int)$vendor_id;
        $settings = bc_chat_settings($vendor_id);

        $provider = strtolower(trim((string)$settings['chat_ai_provider']));
        if (!in_array($provider, ['gemini', 'deepseek'], true)) $provider = 'gemini';
        $model = trim((string)$settings['chat_ai_model']);
        $key   = trim((string)$settings['chat_ai_key']);

        $system = bc_chat_ai_system_prompt();

        // Preferred path: central AIEngine (connected to bc-spadmin + token billing in SAAS,
        // or direct owner API key in NON-SAAS).
        $engine_file = __DIR__ . '/bc-ai-engine.php';
        if (file_exists($engine_file)) {
            require_once($engine_file);
            if (class_exists('AIEngine') && function_exists('getSuperAdminOption')) {
                try {
                    $plat = function_exists('bc_ai_platform_status')
                        ? bc_ai_platform_status($connection_server, $vendor_id, 'support_reply')
                        : ['available' => false];
                    if (!empty($plat['available'])) {
                        $engine = new AIEngine();
                        $use_model = $model !== '' ? $model : (string)($plat['model'] ?? $engine->getDefaultModel());
                        $res = $engine->chat($use_model, $system . "\n\n" . $prompt, ['temperature' => 0.3, 'num_predict' => 700]);
                        if (is_array($res) && ($res['status'] ?? '') === 'success' && trim((string)($res['response'] ?? '')) !== '') {
                            $reply_text = trim((string)$res['response']);
                            if (function_exists('bc_ai_charge_vendor_tokens') && !preg_match('/^\s*ESCALATE\b/i', $reply_text)) {
                                bc_ai_charge_vendor_tokens(
                                    $connection_server,
                                    $vendor_id,
                                    'support_reply',
                                    'support_chat',
                                    'AI Support Reply',
                                    (string)($res['model'] ?? $use_model),
                                    (int)($res['duration_ms'] ?? 0)
                                );
                            }
                            return $reply_text;
                        }
                    }
                } catch (Throwable $e) {
                    // Fall through to legacy direct key if configured.
                }
            }
        }

        if ($key === '') return null;
        return bc_chat_ai_direct($provider, $model, $key, $prompt, $system);
    }
}



if (!function_exists('bc_chat_ai_reply')) {
    /**
     * Generate and post the assistant's reply to a thread.
     *
     * Returns the message id, or 0 when the assistant should stay silent.
     */
    function bc_chat_ai_reply($vendor_id, $thread) {
        global $connection_server;
        $vendor_id = (int)$vendor_id;
        if (!is_array($thread) || empty($thread['id'])) return 0;

        // The thread's own flag is authoritative; it is cleared on escalate and on claim.
        if ((int)$thread['ai_enabled'] !== 1 || $thread['status'] !== 'open') return 0;

        $settings = bc_chat_settings($vendor_id);
        if ($settings['chat_ai_enabled'] !== '1') return 0;

        $has_legacy_key = trim((string)$settings['chat_ai_key']) !== '';
        $has_platform_ai = false;
        if (!$has_legacy_key && function_exists('getSuperAdminOption')) {
            $engine_file = __DIR__ . '/bc-ai-engine.php';
            if (file_exists($engine_file)) require_once($engine_file);
            if (function_exists('bc_ai_platform_status')) {
                $plat = bc_ai_platform_status($connection_server, $vendor_id, 'support_reply');
                $has_platform_ai = !empty($plat['available']);
            }
        }
        if (!$has_legacy_key && !$has_platform_ai) return 0;

        // Build a short context from the recent transcript so follow-ups make sense.
        $history = bc_chat_messages($vendor_id, (int)$thread['id'], 0, 20);
        $lines = [];
        $last_user_msg = '';
        foreach ($history as $m) {
            if ($m['author_type'] === 'system') continue;
            $who = $m['author_type'] === 'user' ? 'Customer' : 'Assistant';
            $lines[] = $who . ': ' . $m['body'];
            if ($m['author_type'] === 'user') $last_user_msg = (string)$m['body'];
        }
        $prompt = implode("\n", array_slice($lines, -12));
        if ($prompt === '') return 0;

        // §25: the web layer may register a context provider so the assistant can
        // reference the customer's own account, transactions, and website details.
        $ctx_provider = $GLOBALS['bc_chat_context_provider'] ?? null;
        if (($settings['chat_ai_context_enabled'] ?? '1') === '1' && is_callable($ctx_provider)) {
            $ctx = (string)$ctx_provider($vendor_id, (int)($thread['user_id'] ?? 0), $last_user_msg);
            if ($ctx !== '') {
                $prompt .= "\n\n[Customer account context]\n" . $ctx;
            }
        }

        $reply = bc_chat_ai_call($vendor_id, $prompt);

        // Provider failure (or an explicit ESCALATE) hands the thread to a human.
        if ($reply === null) {
            bc_chat_escalate($vendor_id, (int)$thread['id'], 'ai_unavailable');
            bc_chat_post_message($vendor_id, (int)$thread['id'], 'system', 'Support',
                'Sorry — I could not answer that automatically. A member of our team will follow up here.');
            return 0;
        }

        if (preg_match('/^\s*ESCALATE\b/i', trim($reply)) || trim(strtoupper($reply)) === 'ESCALATE') {
            bc_chat_escalate($vendor_id, (int)$thread['id'], 'ai_deferred');
            bc_chat_post_message($vendor_id, (int)$thread['id'], 'system', 'Support',
                'That one needs a person. A member of our team will pick this up shortly.');
            return 0;
        }

        return bc_chat_post_message($vendor_id, (int)$thread['id'], 'ai', 'Assistant', $reply);
    }
}

if (!function_exists('bc_chat_public_thread')) {
    /**
     * The thread fields the browser is allowed to see.
     *
     * An allow-list, not a redaction: assigned_admin and the internal counters are simply absent,
     * so a field added to the table later cannot leak by default.
     */
    function bc_chat_public_thread($thread) {
        if (!is_array($thread)) return null;
        return [
            'id'        => (int)$thread['id'],
            'status'    => (string)$thread['status'],
            'ai_active' => ((int)$thread['ai_enabled'] === 1 && $thread['status'] === 'open'),
            'subject'   => (string)$thread['subject'],
            'ticket_no' => (string)($thread['ticket_no'] ?? ''),
            'unread'    => (int)($thread['unread_for_user'] ?? 0),
        ];
    }
}

if (!function_exists('bc_chat_public_messages')) {
    /**
     * The message fields the browser is allowed to see.
     *
     * author_type is passed through because the widget styles the speaker differently; nothing
     * here is trusted for rendering — the widget builds text nodes, never innerHTML.
     */
    function bc_chat_public_messages(array $messages) {
        $out = [];
        foreach ($messages as $m) {
            $out[] = [
                'id'    => (int)$m['id'],
                'who'   => (string)$m['author_type'],
                'name'  => (string)$m['author_name'],
                'body'  => (string)$m['body'],
                'at'    => (string)$m['created_at'],
            ];
        }
        return $out;
    }
}
