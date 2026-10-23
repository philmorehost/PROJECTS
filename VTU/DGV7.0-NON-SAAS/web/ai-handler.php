<?php
/**
 * ai-handler.php — DGV6.90 AI Edition
 * AI Middleware — The PHP Safety Wall
 *
 * This is the single entry point for ALL AI requests from the web frontend.
 * Every call must pass through this file's gate checks before Cloud AI is reached.
 *
 * Accepts: POST with JSON body or form data
 * Returns: JSON response
 *
 * GOLDEN RULES enforced here:
 * 1. Auth gate — must be logged in
 * 2. AI status gate — vendor must have AI enabled
 * 3. Token gate — must have ai_token_balance >= ai_per_tx_cost
 * 4. Prompt firewall — malicious prompts are rejected
 * 5. Token deduction ONLY after successful Cloud AI response
 * 6. Every call is logged to sas_ai_transactions
 */

session_start();
ob_start();
include_once(__DIR__ . "/../func/bc-config.php");

// The AI assistant buys on behalf of the LOGGED-IN person on our own site, so an AI purchase is a
// person-to-person flow and must still prove the transaction PIN when the vendor forces one
// (web/func/*.php reads this flag). Nothing here is an external api_key integration.
$GLOBALS['pin_required_for_api_purchase'] = true;

header('Content-Type: application/json; charset=UTF-8');
header('X-Content-Type-Options: nosniff');

// Guarantees stray warnings/notices from anywhere in the include chain are logged
// instead of silently prepended to the JSON body (which corrupts it for Gson/JSONSerialization
// while the HTTP status stays 200, since PHP locks in the status at first byte of output).
function ai_send_json(int $code, array $data): void {
    $leaked = ob_get_clean();
    if ($leaked !== '') {
        error_log('[ai-handler] leaked output before JSON response: ' . substr($leaked, 0, 500));
    }
    http_response_code($code);
    echo json_encode($data);
    exit;
}

// ─── Modified method check: Allow GET only for 'apply' action ────────────────
$action_type = $_REQUEST['action'] ?? 'chat';
if ($_SERVER['REQUEST_METHOD'] !== 'POST' && $action_type !== 'apply') {
    ai_send_json(405, ['status' => 'error', 'message' => 'Method not allowed']);
}

// ─── Parse input (supports JSON body or form-data) ───────────
$raw_input = file_get_contents('php://input');
$json_input = json_decode($raw_input, true);
$prompt_raw    = $json_input['prompt'] ?? $_POST['prompt'] ?? '';
$request_model = $json_input['model'] ?? $_POST['model'] ?? '';

// ─── GATE 1: Authentication ──────────────────────────────────
$api_key = $json_input['api_key'] ?? $_POST['api_key'] ?? '';
$user_session   = $_SESSION['user_session'] ?? '';
$admin_session  = $_SESSION['admin_session'] ?? '';
$spadmin_session = $_SESSION['spadmin_session'] ?? '';

if (!empty($api_key) && isset($connection_server)) {
    $esc_api_key = mysqli_real_escape_string($connection_server, $api_key);
    $user_q = mysqli_query($connection_server, "SELECT username FROM sas_users WHERE api_key='$esc_api_key' AND status=1 LIMIT 1");
    if ($user_q && mysqli_num_rows($user_q) > 0) {
        $user_row = mysqli_fetch_assoc($user_q);
        $user_session = $user_row['username'];
    }
}

if (empty($user_session) && empty($admin_session) && empty($spadmin_session) || !isset($connection_server)) {
    ai_send_json(401, ['status' => 'error', 'code' => 'NOT_LOGGED_IN', 'message' => 'Please log in to use AI features.']);
}

$context = $_GET['context'] ?? 'user';
$username = $user_session;
if ($context === 'admin') $username = $admin_session;
if ($context === 'spadmin') $username = $spadmin_session;

$vendor_id = resolveVendorID();

if ($vendor_id <= 0 && $context !== 'spadmin') {
    ai_send_json(403, ['status' => 'error', 'code' => 'VENDOR_ERROR', 'message' => 'Vendor not found.']);
}

// ─── GATE 2: Rate limiting (per actor, 20 AI requests/minute) ─
$is_admin_actor = (($context === 'admin' || $context === 'spadmin') && (!empty($admin_session) || !empty($spadmin_session)));
$rate_key = $is_admin_actor ? "ai_adm_{$vendor_id}_{$username}" : "ai_usr_{$vendor_id}_{$username}";

// DEBUG
// file_put_contents('ai_debug.log', "VID: $vendor_id | Admin: $admin_session | User: $user_session | IsAdminActor: ".($is_admin_actor?'Y':'N')." | Username: $username\n", FILE_APPEND);

if (bc_is_rate_limited('ai_request', $rate_key, 20, 60)) {
    ai_send_json(429, ['status' => 'error', 'code' => 'RATE_LIMITED', 'message' => 'Too many AI requests. Please wait a moment.']);
}

// ─── Load actor and vendor details ────────────────────────────
$esc_name = mysqli_real_escape_string($connection_server, $username);
$safe_vid = (int)$vendor_id;

if ($is_admin_actor) {
    if ($context === 'spadmin') {
        // Super Admin uses system settings (vendor 0 or similar, but we check if they are authorized)
        $q = mysqli_query($connection_server, "SELECT 1 as id, 'Super' as firstname, 1 as ai_status, 999999 as ai_token_balance");
    } else {
        // Fetch from sas_vendors
        $q = mysqli_query($connection_server, "SELECT id, firstname, ai_status, ai_token_balance FROM sas_vendors WHERE id='$safe_vid' AND email='$esc_name' LIMIT 1");
    }
} else {
    // Fetch from sas_users
    $q = mysqli_query($connection_server, "SELECT * FROM sas_users WHERE vendor_id='$safe_vid' AND username='$esc_name' AND status=1 LIMIT 1");
}

$get_logged_user_details = $q ? mysqli_fetch_assoc($q) : null;

if (!$get_logged_user_details) {
    ai_send_json(403, ['status' => 'error', 'code' => 'USER_NOT_FOUND', 'message' => 'Account not found.']);
}

// ─── Special Action: Apply/Activate AI ───────────────────────
if ($action_type === 'apply' && !$is_admin_actor) {
    $v_q = mysqli_query($connection_server, "SELECT ai_status, voice_tx_threshold FROM sas_vendors WHERE id='$safe_vid' LIMIT 1");
    $v_ai = mysqli_fetch_assoc($v_q);
    
    if (($v_ai['ai_status'] ?? 0) != 1) {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            ai_send_json(200, ['status' => 'error', 'message' => 'AI features are disabled by the platform admin.']);
        } else {
            $_SESSION['product_purchase_response'] = "AI features are currently disabled by the admin.";
            header("Location: Dashboard.php"); exit;
        }
    }

    $threshold = (int)($v_ai['voice_tx_threshold'] ?? 50);
    $q_tx = mysqli_query($connection_server, "SELECT COUNT(*) as total FROM sas_transactions WHERE vendor_id='$safe_vid' AND username='$esc_name' AND status=1");
    $tx_data = mysqli_fetch_assoc($q_tx);
    $total_tx = (int)($tx_data['total'] ?? 0);

    if ($total_tx < $threshold) {
        $msg = "You need at least $threshold successful transactions to activate AI. Current: $total_tx";
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            ai_send_json(200, ['status' => 'error', 'message' => $msg]);
        } else {
            $_SESSION['product_purchase_response'] = $msg;
            header("Location: Dashboard.php"); exit;
        }
    }

    // Activate: Status=1 (Assistant), Voice=1 (Pending Review)
    // Grant 500 starter tokens if they have 0
    $tokens = (int)($get_logged_user_details['ai_token_balance'] ?? 0) > 0 ? (int)$get_logged_user_details['ai_token_balance'] : 500;
    
    mysqli_query($connection_server, "UPDATE sas_users SET ai_status=1, ai_voice_status=1, ai_token_balance='$tokens' WHERE id='{$get_logged_user_details['id']}'");
    
    $success_msg = "🎉 AI Assistant Activated! You now have access to Voice-to-VTU and Smart Chat. $tokens tokens granted.";
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        ai_send_json(200, ['status' => 'success', 'message' => $success_msg]);
    } else {
        $_SESSION['product_purchase_response'] = $success_msg;
        header("Location: Dashboard.php"); exit;
    }
}

// ─── GATE 3: AI Status Check ─────────────────────────────────
// Fetch Vendor Global AI Status
$v_status_q = mysqli_query($connection_server, "SELECT ai_status FROM sas_vendors WHERE id='$safe_vid' LIMIT 1");
$v_status = ($v_status_q) ? mysqli_fetch_assoc($v_status_q) : null;

if (($v_status['ai_status'] ?? 0) != 1 && $context !== 'spadmin') {
    ai_send_json(403, [
        'status'  => 'error',
        'code'    => 'PLATFORM_DISABLED',
        'message' => 'AI features are currently disabled by the platform admin.',
    ]);
}

// User Activation Check Removed for standalone

// ─── Load vendor AI config ────────────────────────────────────
$vendor_q = mysqli_query($connection_server,
    "SELECT ai_per_tx_cost, ai_model_assigned, ai_price_per_1k_tokens FROM sas_vendors WHERE id='$safe_vid' LIMIT 1"
);
$vendor_ai = $vendor_q ? mysqli_fetch_assoc($vendor_q) : null;
$tokens_per_call = (int)($vendor_ai['ai_per_tx_cost'] ?? 2);
$assigned_model  = $vendor_ai['ai_model_assigned'] ?? 'gemini-1.5-flash';

// Use requested model only if it matches the assigned model (prevent tier-hopping)
$model_to_use = $assigned_model;

// ─── GATE 4: Token Balance Check ─────────────────────────────
$current_tokens = (int)($get_logged_user_details['ai_token_balance'] ?? 0);
if ($current_tokens < $tokens_per_call) {
    ai_send_json(402, [
        'status'         => 'error',
        'code'           => 'INSUFFICIENT_TOKENS',
        'message'        => "Insufficient AI tokens. You have $current_tokens tokens but need $tokens_per_call.",
        'current_tokens' => $current_tokens,
        'cost'           => $tokens_per_call,
    ]);
}

// ─── GATE 5: Prompt Firewall ──────────────────────────────────
include_once(__DIR__ . "/../func/bc-ai-engine.php");

if (empty($prompt_raw)) {
    ai_send_json(400, ['status' => 'error', 'code' => 'EMPTY_PROMPT', 'message' => 'Please enter a question.']);
}

$context_data = $json_input['context'] ?? [];
$safe_prompt = bc_firewall_prompt($prompt_raw, false, $context_data);

if ($safe_prompt === false) {
    ai_send_json(400, [
        'status'  => 'error',
        'code'    => 'PROMPT_REJECTED',
        'message' => 'Your request contains content that cannot be processed. Please ask a VTU business-related question.',
    ]);
}

// ─── CALL CLOUD AI ──────────────────────────────────────────
$ai = ai_engine();

// Ensure the model is compatible with the active provider
if (!$ai->isModelCompatible($model_to_use)) {
    $model_to_use = $ai->getDefaultModel();
}

// --- ACTION: Get Data Plans for prompt refinement ---
$data_plans_ctx = [];
$acc_lvl = $get_logged_user_details['account_level'] ?? 1;
$acc_lvl_tables = [1 => "sas_smart_parameter_values", 2 => "sas_agent_parameter_values", 3 => "sas_api_parameter_values"];
$price_table = $acc_lvl_tables[$acc_lvl] ?? "sas_api_parameter_values";

$dp_q = mysqli_query($connection_server, "
    SELECT p.product_name, pr.val_1, pr.val_4, pr.val_2
    FROM $price_table pr
    JOIN sas_products p ON p.id = pr.product_id
    WHERE pr.vendor_id = '$safe_vid' AND pr.status = 1 AND pr.val_2 > 0
    LIMIT 100
");
if ($dp_q) {
    while($dp_row = mysqli_fetch_assoc($dp_q)) {
        $p_name = !empty($dp_row['val_4']) ? $dp_row['val_4'] : $dp_row['val_1'];
        $data_plans_ctx[] = strtoupper($dp_row['product_name']) . " " . $p_name . " (N" . $dp_row['val_2'] . ")";
    }
}
$context_data['current_data_prices'] = $data_plans_ctx;

// Action routing
switch ($action_type) {
    case 'voice_vtu':
    case 'execute_vtu':
        execute_vtu_logic:
        // ─── AI Parameter Mapping ────────────────────────────
        $type_map = [
            'sme'               => 'sme-data',
            'sme-data'          => 'sme-data',
            'sme data'          => 'sme-data',
            'mtn sme'           => 'sme-data',
            'gifting'           => 'cg-data',
            'gifting data'      => 'cg-data',
            'cg'                => 'cg-data',
            'cg-data'           => 'cg-data',
            'corporate'         => 'cg-data',
            'corporate gifting' => 'cg-data',
            'cg data'           => 'cg-data',
            'shared'            => 'shared-data',
            'shared data'       => 'shared-data',
            'shared-data'       => 'shared-data',
            'direct'            => 'dd-data',
            'direct data'       => 'dd-data',
            'direct-data'       => 'dd-data',
            'dd-data'           => 'dd-data',
            'prepaid'           => 'prepaid',
            'postpaid'          => 'postpaid',
            'pre-paid'          => 'prepaid',
            'post-paid'         => 'postpaid'
        ];

        $service_map = [
            'airtime'     => 'func/airtime.php',
            'data'        => 'func/data.php',
            'electricity' => 'func/electric.php',
            'cable'       => 'func/cable.php',
            'betting'     => 'func/betting.php',
            'exam'        => 'func/exam.php'
        ];

        // 1. Get Intent (either parsed now or passed from client or session)
        if ($action_type === 'execute_vtu') {
            $intent = $json_input['intent'] ?? null;
            if (empty($intent) && !empty($_POST['intent'])) {
                $intent = is_string($_POST['intent']) ? json_decode($_POST['intent'], true) : $_POST['intent'];
            }
            if (empty($intent)) $intent = $_SESSION['ai_pending_vtu'] ?? null;
        } else {
            $intent = $ai->parseVtuIntent($prompt_raw, $model_to_use, $context_data);
        }

        $intent_confidence = (is_array($intent) && isset($intent['confidence'])) ? (int)$intent['confidence'] : 0;

        if (!is_array($intent) || $intent_confidence < 50) {
             // Log failed intent for training
             $esc_prompt = mysqli_real_escape_string($connection_server, substr($prompt_raw, 0, 1000));
             $esc_intent = mysqli_real_escape_string($connection_server, json_encode($intent));
             $esc_model  = mysqli_real_escape_string($connection_server, $model_to_use);
             @mysqli_query($connection_server, "INSERT INTO sas_ai_failed_intents (vendor_id, username, prompt, raw_intent, model_used, confidence) VALUES ('$safe_vid', '$esc_name', '$esc_prompt', '$esc_intent', '$esc_model', '$intent_confidence')");

             ai_send_json(200, ['status' => 'error', 'code' => 'LOW_CONFIDENCE', 'message' => 'I could not understand that command clearly. Please ensure you mention the service (e.g., Airtime), Network (e.g., MTN), and Amount or Data Plan.']);
        }

        // 2. Check Authorization and Perform Professional Verification
        $verified_name = "";
        
        // --- Provider Normalization ---
        $provider_raw = strtolower(trim($intent['network'] ?? ''));
        $clean_provider = $provider_raw;
        $electric_providers = ['ikedc', 'ekedc', 'aedc', 'eedc', 'jedc', 'ibedc', 'kedco', 'phed', 'yedc', 'bedc', 'aba', 'kaedco'];
        $cable_providers    = ['dstv', 'gotv', 'startimes', 'showmax'];
        
        foreach($electric_providers as $ep) {
            if (strpos($provider_raw, $ep) !== false) { $clean_provider = $ep; break; }
        }
        foreach($cable_providers as $cp) {
            if (strpos($provider_raw, $cp) !== false) { $clean_provider = $cp; break; }
        }
        $intent['network'] = $clean_provider; // Update intent with clean provider

        if (in_array(strtolower($intent['service']), ['electricity', 'cable'])) {
            // Perform professional verification before AI summarizes
            $action_function = 3; // Verify
            $purchase_method = "API";
            $raw_type = strtolower(trim($intent['type'] ?? ''));
            $mapped_type = $type_map[$raw_type] ?? $raw_type;
            
            $get_api_post_info = [
                'provider'     => $intent['network'],
                'type'         => (strtolower($intent['service']) === 'cable') ? $intent['network'] : $mapped_type,
                'meter_number' => $intent['phone'],
                'iuc_number'   => $intent['phone'],
                'package'      => $intent['amount'], // Cable plan identifier
                'network'      => str_replace(['mobile', ' '], ['', ''], strtolower($intent['network'] ?? ''))
            ];
            if ($get_api_post_info['network'] == '9') $get_api_post_info['network'] = '9mobile';

            $handler_rel = $service_map[strtolower($intent['service'])] ?? '';
            $handler_path = __DIR__ . "/" . $handler_rel;
            
            if (!empty($handler_rel) && file_exists($handler_path)) {
                $json_response_encode = null; // Reset
                ob_start(); 
                include($handler_path);
                ob_end_clean();
                
                $verify_res = json_decode($json_response_encode ?? '{}', true);
                if (($verify_res['status'] ?? '') === 'success') {
                    $verified_name = $verify_res['customer_name'] ?? $verify_res['desc'] ?? "";
                }
            }
        }

        if (($get_logged_user_details['ai_voice_status'] ?? 0) != 2 && $action_type === 'voice_vtu') {
             // Fallback: If not approved for zero-click, treat as a chat request but keep the intent
             $augmented_prompt = $safe_prompt;
             if (!empty($verified_name)) {
                 $augmented_prompt = "[SYSTEM INSTRUCTION: The verified owner for this account is \"$verified_name\". You MUST mention this name clearly in your summary to the user so they can verify it before proceeding.] " . $safe_prompt;
             }

             $ai_result = $ai->chat($model_to_use, $augmented_prompt);
             $ai_result['pending_vtu'] = $intent;
             $_SESSION['ai_pending_vtu'] = $intent; // Server-side persistence
             break; // Skip the direct execution below
        }
        
        // Note: execute_vtu is ALWAYS allowed if the user has a pending intent, 
        // as it is a conscious confirmation step.

        // 3. Prepare for Transaction Execution
        $action_function = 1; // Purchase
        $purchase_method = "API"; 
        
        $raw_type = strtolower(trim($intent['type'] ?? ''));
        $mapped_type = $type_map[$raw_type] ?? $raw_type;

        // Provider/Network Normalization
        $network = strtolower(trim($intent['network'] ?? ''));
        $network = str_replace(['mobile', ' '], ['', ''], $network); // "9 mobile" -> "9"
        if ($network == '9') $network = '9mobile';

        $get_api_post_info = [
            'network'      => $network,
            'phone_number' => $intent['phone'],
            'amount'       => $intent['amount'],
            'type'         => $mapped_type,
            'provider'     => $intent['network'],
            'quantity'     => $intent['amount'],
            'meter_number' => $intent['phone'],
'id'           => $intent['id'] ?? '',
            // Transaction PIN for an AI purchase. The intent parser only fills this when the client
            // collected it; requireTransactionPin() stays the authority either way.
            'pin'          => $intent['pin'] ?? ($_POST['pin'] ?? '')
        ];

        $handler_rel = $service_map[strtolower($intent['service'])] ?? '';
        $handler_path = __DIR__ . "/" . $handler_rel; // Since ai-handler.php is in web/
        
        if (empty($handler_rel) || !file_exists($handler_path)) {
             ai_send_json(200, ['status' => 'error', 'message' => 'That service (' . $intent['service'] . ') is not yet supported for voice commands. Path: ' . $handler_rel]);
        }

        // Execute Transaction
        include($handler_path);
        
        $res = json_decode($json_response_encode ?? '{}', true);
        
        $res_status = $res['status'] ?? '';
        if ($res_status === 'success' || $res_status === 'pending') {
            $is_pending = ($res_status === 'pending');
            $icon = $is_pending ? "⏳" : "✅";
            $msg = $is_pending ? "Order Placed! (Processing...)" : "Order Placed Successfully!";
            
            $ai_result = [
                'status'   => 'success',
                'response' => "$icon $msg\nType: " . ucwords($intent['service']) . "\nDest: " . $intent['phone'] . "\nAmt: ₦" . number_format($intent['amount']) . "\nRef: " . ($res['ref'] ?? 'N/A'),
                'model'    => $model_to_use,
                'duration_ms' => 0 
            ];
            $tokens_per_call = (int)($vendor_ai['ai_voice_fee_tokens'] ?? 0);
        } else {
            ai_send_json(200, [
                'status'  => 'error',
                'code'    => 'TRANSACTION_FAILED',
                'message' => "❌ Transaction Failed: " . ($res['desc'] ?? 'Unknown Error') . ". No AI tokens were charged.",
                'intent'  => $intent
            ]);
        }
        break;
    case 'marketing':
        $ai_result = $ai->chat($model_to_use, $safe_prompt, ['temperature' => 0.85]);
        break;
    case 'analysis':
        $ai_result = $ai->chat($model_to_use, $safe_prompt, ['temperature' => 0.3]);
        break;
    case 'balance':
        // Direct data lookup — no AI call needed, so this doesn't burn tokens.
        $bal = (float)($get_logged_user_details['balance'] ?? 0);
        $ai_result = [
            'status'      => 'success',
            'response'    => "Your current wallet balance is ₦" . number_format($bal, 2) . ".",
            'model'       => 'system',
            'duration_ms' => 0,
        ];
        $tokens_per_call = 0;
        break;
    case 'batch_status':
        // Direct data lookup via the bulk-queue progress helper — no AI call needed.
        $batch_number = trim($json_input['batch_number'] ?? $_POST['batch_number'] ?? '');
        if (empty($batch_number)) {
            ai_send_json(400, ['status' => 'error', 'code' => 'MISSING_BATCH', 'message' => 'Please provide a batch number.']);
        }
        $progress = bc_get_bulk_batch_progress($connection_server, $safe_vid, $is_admin_actor ? null : $username, $batch_number);
        $ai_result = [
            'status'      => 'success',
            'response'    => "Batch #$batch_number is <b>{$progress['status']}</b>: {$progress['successful']} successful, {$progress['failed']} failed, {$progress['pending']} pending of {$progress['total']} total."
                . (!empty($progress['ai_diagnosis']) ? " AI diagnosis: " . $progress['ai_diagnosis'] : ""),
            'model'       => 'system',
            'duration_ms' => 0,
        ];
        $tokens_per_call = 0;
        break;
    default:
        $is_confirm = preg_match('/\b(yes|confirm|proceed|go ahead|yep|sure|ok|do it|okay|process)\b/i', trim($prompt_raw));
        if ($is_confirm && !empty($_SESSION['ai_pending_vtu'])) {
            $intent = $_SESSION['ai_pending_vtu'];
            $action_type = 'execute_vtu';
            goto execute_vtu_logic;
        }

        // Proactive: balance queries answered directly from real data (no AI guessing, no token cost)
        if (preg_match('/\b(my\s+)?(wallet\s+)?balance\b|how much (do i have|is in my wallet)/i', $prompt_raw)) {
            $bal = (float)($get_logged_user_details['balance'] ?? 0);
            $ai_result = [
                'status'      => 'success',
                'response'    => "Your current wallet balance is ₦" . number_format($bal, 2) . ".",
                'model'       => 'system',
                'duration_ms' => 0,
            ];
            $tokens_per_call = 0;
            break;
        }

        // Proactive: batch status queries ("check batch 4F2A9C", "status of batch #4F2A9C")
        if (preg_match('/batch\s*#?\s*([a-zA-Z0-9]{4,10})/i', $prompt_raw, $batch_match)) {
            $progress = bc_get_bulk_batch_progress($connection_server, $safe_vid, $is_admin_actor ? null : $username, $batch_match[1]);
            if ($progress['total'] > 0) {
                $ai_result = [
                    'status'      => 'success',
                    'response'    => "Batch #{$batch_match[1]} is <b>{$progress['status']}</b>: {$progress['successful']} successful, {$progress['failed']} failed, {$progress['pending']} pending of {$progress['total']} total."
                        . (!empty($progress['ai_diagnosis']) ? " AI diagnosis: " . $progress['ai_diagnosis'] : ""),
                    'model'       => 'system',
                    'duration_ms' => 0,
                ];
                $tokens_per_call = 0;
                break;
            }
        }

        // Proactive Intent Detection: Check if this is a transaction request
        if (preg_match('/buy|recharge|send|topup|data|airtime|pay|pin|exam|electric|meter|ikedc|ekedc|aedc|phed|jedc|kedco|ibedc|eedc|bedc|kaedco|yedc|aba|dstv|gotv|startimes/i', $prompt_raw)) {
            $intent = $ai->parseVtuIntent($prompt_raw, $model_to_use, $context_data);
            if ($intent && $intent['confidence'] >= 50) {
                // If it's electricity/cable, run verification BEFORE chat
                if (in_array(strtolower($intent['service'] ?? ''), ['electricity', 'cable'])) {
                    $action_function = 3; // Verify
                    $purchase_method = "API";
                    $raw_type_sub = strtolower(trim($intent['type'] ?? ''));
                    $mapped_type_sub = $type_map[$raw_type_sub] ?? $raw_type_sub;
                    
                    $get_api_post_info = [
                        'provider'     => $intent['network'],
                        'type'         => (strtolower($intent['service']) === 'cable') ? $intent['network'] : $mapped_type_sub,
                        'meter_number' => $intent['phone'],
                        'iuc_number'   => $intent['phone'],
                        'package'      => $intent['amount'],
                        'network'      => str_replace(['mobile', ' '], ['', ''], strtolower($intent['network'] ?? ''))
                    ];
                    if ($get_api_post_info['network'] == '9') $get_api_post_info['network'] = '9mobile';

                    $handler_rel_sub = $service_map[strtolower($intent['service'])] ?? '';
                    $handler_path_sub = __DIR__ . "/" . $handler_rel_sub;
                    
                    if (!empty($handler_rel_sub) && file_exists($handler_path_sub)) {
                        $json_response_encode = null;
                        ob_start();
                        include($handler_path_sub);
                        ob_end_clean();
                        $verify_res_sub = json_decode($json_response_encode ?? '{}', true);
                        if (($verify_res_sub['status'] ?? '') === 'success') {
                            $verified_name = $verify_res_sub['customer_name'] ?? $verify_res_sub['desc'] ?? "";
                        }
                    }
                }
                
                if (!empty($verified_name)) {
                    $safe_prompt = "[SYSTEM INSTRUCTION: The verified owner for this account is \"$verified_name\". You MUST mention this name clearly in your summary to the user so they can verify it before proceeding.] " . $safe_prompt;
                }
                
                $_SESSION['ai_pending_vtu'] = $intent; // Save for next turn
                $ai_result = $ai->chat($model_to_use, $safe_prompt);
                $ai_result['pending_vtu'] = $intent;
            } else {
                $ai_result = $ai->chat($model_to_use, $safe_prompt);
            }
        } else {
            $ai_result = $ai->chat($model_to_use, $safe_prompt);
        }
}
$ai_duration = $ai_result['duration_ms'] ?? 0;

// ─── SUCCESS: Deduct tokens, log, respond ────────────────────
if ($ai_result['status'] === 'success') {
    // Deduct tokens ONLY on success (pay-per-success billing)
    $new_tokens = max(0, $current_tokens - $tokens_per_call);
    $actor_id   = (int)$get_logged_user_details['id'];

    if ($context === 'spadmin') {
        // Super admin doesn't get debited
    } elseif ($context === 'admin') {
        mysqli_query($connection_server, "UPDATE sas_vendors SET ai_token_balance='$new_tokens' WHERE id='$actor_id'");
    } else {
        mysqli_query($connection_server, "UPDATE sas_users SET ai_token_balance='$new_tokens', ai_requests_used=ai_requests_used+1 WHERE id='$actor_id'");
    }

    // Log the AI transaction (store only a hash of the prompt for privacy)
    $prompt_hash = hash('sha256', $prompt_raw);
    $esc_action  = mysqli_real_escape_string($connection_server, substr($action_type, 0, 50));
    $esc_model   = mysqli_real_escape_string($connection_server, $ai_result['model']);
    $esc_hash    = mysqli_real_escape_string($connection_server, $prompt_hash);
    $cost_naira  = ($tokens_per_call / 1000) * (float)($vendor_ai['ai_price_per_1k_tokens'] ?? 100);

    mysqli_query($connection_server,
        "INSERT INTO sas_ai_transactions
         (vendor_id, username, action_type, model_used, tokens_burned, duration_ms, cost_naira, prompt_hash, status)
         VALUES ('$safe_vid', '" . mysqli_real_escape_string($connection_server, $username) . "', '$esc_action', '$esc_model', '$tokens_per_call', '$ai_duration', '$cost_naira', '$esc_hash', 'success')"
    );

    // Return response to frontend
    ai_send_json(200, [
        'status'           => 'success',
        'response'         => $ai_result['response'],
        'model_used'       => $ai_result['model'],
        'duration_ms'      => $ai_result['duration_ms'],
        'tokens_used'      => $tokens_per_call,
        'tokens_remaining' => $new_tokens,
        'pending_vtu'      => $ai_result['pending_vtu'] ?? null,
        'ref'              => isset($res) && is_array($res) ? ($res['ref'] ?? null) : null,
    ]);

} else {
    // Log failed call (no token deduction on failure)
    $prompt_hash = hash('sha256', $prompt_raw);
    $esc_hash    = mysqli_real_escape_string($connection_server, $prompt_hash);
    @mysqli_query($connection_server,
        "INSERT INTO sas_ai_transactions
         (vendor_id, username, action_type, model_used, tokens_burned, cost_naira, prompt_hash, status)
         VALUES ('$safe_vid', '" . mysqli_real_escape_string($connection_server, $username) . "', 'failed_call', '', 0, 0, '$esc_hash', 'failed')"
    );

    ai_send_json(503, [
        'status'  => 'error',
        'code'    => 'AI_UNAVAILABLE',
        'message' => 'The AI engine is temporarily unavailable. Please try again shortly. No tokens were deducted.',
    ]);
}
