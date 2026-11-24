<?php
require_once __DIR__ . '/../../func/bc-secure-session.php';
 bc_secure_session_start();
ob_start();
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type, Accept, Authorization, X-App-Token, X-Requested-With, X-App-Source");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Content-Type: application/json");
header("Cache-Control: private, no-store");

// Guarantees a stray PHP warning/notice anywhere in the include chain gets logged instead of
// silently prepending garbage to the JSON body (the exact bug already fixed in web/ai-handler.php —
// a corrupted-but-still-2xx body makes Retrofit/Gson either throw or, if the leaked text happens
// to be swallowed as an unrecognized field, land here with no status/desc the client can show).
function sq_json(array $data): void {
    $leaked = ob_get_clean();
    if ($leaked !== '') {
        error_log('[security-quest] leaked output before JSON response: ' . substr($leaked, 0, 500));
    }
    echo json_encode($data);
    ob_start();
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

include_once("../../func/bc-connect.php");

// Stateless api_key-based counterpart to web/SecurityQuest.php, for the mobile apps. The web
// version protects a browser session via a 6-hour cookie; the apps have no session to protect,
// so instead they track a local "last verified at" timestamp and call action=verify again after
// an idle period. Answer comparison mirrors the web version exactly: lowercased on write, plain
// string compare on read (no hashing there either — kept consistent, not introducing a new scheme).

$vendor_id = resolveVendorID();
$get_vendor = mysqli_fetch_array(mysqli_query($connection_server, "SELECT id FROM sas_vendors WHERE id='$vendor_id' AND status=1 LIMIT 1"));
if (!$get_vendor) {
    sq_json(["status" => "failed", "desc" => "Vendor not found or inactive"]);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);
if (empty($data)) $data = $_REQUEST;

// This is a first-party app security endpoint, not a merchant-key bridge.
require_once __DIR__.'/../../func/bc-app-request-compat.php';
$data = bc_app_security_question_input($data);
$user = bc_fintech_require_app_account((int)$vendor_id, $data);
require_once __DIR__.'/../../func/bc-security-question-policy.php';
$action = is_string($data['action']??null)?$data['action']:'';
if(!in_array($action,['get','set','verify','reset'],true)){sq_json(['status'=>'failed','desc'=>'Unknown action.']);exit;}
if(!bc_security_question_policy((int)$vendor_id)['app']){
 sq_json(['status'=>'success','enabled'=>false,'has_quest'=>false,'quest_bank'=>[],'desc'=>'Security question is disabled by your administrator.']);exit;
}
if($action!=='get' && bc_is_rate_limited('app_security_question',(string)$vendor_id.':'.(int)$user['id'],10,300)){
 http_response_code(429);sq_json(['status'=>'failed','desc'=>'Too many attempts. Please wait before retrying.']);exit;
}
$has_quest = !empty(trim($user["security_quest"] ?? '')) && !empty(trim($user["security_answer"] ?? '')) &&
    (strlen($user["security_answer"]) >= 3) && (strlen($user["security_answer"]) <= 20);

switch ($action) {
    case "get":
        // Always include the question bank — the client needs it both for first-time setup
        // and for the "reset security answer" form (picking a new question), regardless of
        // whether a question is already set.
        $bank = [];
        $q_bank = mysqli_query($connection_server, "SELECT id, quest FROM sas_security_quests");
        if(!$q_bank){http_response_code(503);sq_json(["status"=>"failed","desc"=>"Security question catalogue is unavailable. Contact support."]);break;}
        while ($row = mysqli_fetch_assoc($q_bank)) $bank[] = ["id" => (int)$row["id"], "quest" => $row["quest"]];

        if(!$bank){http_response_code(503);sq_json(["status"=>"failed","desc"=>"Security question catalogue is empty. Contact support."]);break;}
        if ($has_quest) {
            $quest_row = mysqli_fetch_array(mysqli_query($connection_server, "SELECT quest FROM sas_security_quests WHERE id='" . (int)$user["security_quest"] . "' LIMIT 1"));
            sq_json(["status" => "success", "enabled" => true, "has_quest" => true, "question" => $quest_row["quest"] ?? "", "quest_bank" => $bank]);
        } else {
            sq_json(["status" => "success", "enabled" => true, "has_quest" => false, "quest_bank" => $bank]);
        }
        break;

    case "set":
        if ($has_quest) {
            sq_json(["status" => "failed", "desc" => "Security question already set. Use action=reset instead."]);
            break;
        }
        $quest_id = trim($data['quest_id'] ?? '');
        $answer = trim(strip_tags(strtolower($data['answer'] ?? '')));
        if (empty($quest_id) || !is_numeric($quest_id) || empty($answer)) {
            sq_json(["status" => "failed", "desc" => "Security answer cannot be empty."]);
            break;
        }
        if (strlen($answer) < 3 || strlen($answer) > 20) {
            sq_json(["status" => "failed", "desc" => "Security answer must be between 3-20 characters."]);
            break;
        }
        $qid=(int)$quest_id;
        $exists=bc_q_row('SELECT id FROM sas_security_quests WHERE id=? LIMIT 1',[$qid]);
        if(!$exists){sq_json(['status'=>'failed','desc'=>'Choose a valid security question.']);break;}
        $affected=null;
        if(!bc_q('UPDATE sas_users SET security_quest=?,security_answer=? WHERE id=? AND vendor_id=?',[$qid,$answer,(int)$user['id'],(int)$vendor_id],$affected)){
            http_response_code(503);sq_json(['status'=>'failed','desc'=>'Could not save security question. Please retry.']);break;
        }
        sq_json(["status" => "success", "desc" => "Security question set successfully."]);
        break;

    case "verify":
        $answer = trim(strip_tags(strtolower($data['answer'] ?? '')));
        if (empty($answer)) {
            sq_json(["status" => "failed", "desc" => "Security answer cannot be empty."]);
            break;
        }
        if ($has_quest && $answer === $user["security_answer"]) {
            sq_json(["status" => "success", "desc" => "Security verification successful."]);
        } else {
            sq_json(["status" => "failed", "desc" => "Invalid security answer."]);
        }
        break;

    case "reset":
        $quest_id = trim($data['quest_id'] ?? '');
        $answer = trim(strip_tags(strtolower($data['answer'] ?? '')));
        $pass = is_string($data['password']??null)?$data['password']:'';
        if (empty($quest_id) || !is_numeric($quest_id) || empty($answer) || strlen($answer) < 3 || strlen($answer) > 20) {
            sq_json(["status" => "failed", "desc" => "Security answer must be between 3-20 characters."]);
            break;
        }
        if(!bc_verify_password($pass,(string)$user['password'])){
            sq_json(['status'=>'failed','desc'=>'Incorrect password.']);break;
        }
        $qid=(int)$quest_id;
        $exists=bc_q_row('SELECT id FROM sas_security_quests WHERE id=? LIMIT 1',[$qid]);
        if(!$exists){sq_json(['status'=>'failed','desc'=>'Choose a valid security question.']);break;}
        $affected=null;
        if(!bc_q('UPDATE sas_users SET security_quest=?,security_answer=? WHERE id=? AND vendor_id=?',[$qid,$answer,(int)$user['id'],(int)$vendor_id],$affected)){
            http_response_code(503);sq_json(['status'=>'failed','desc'=>'Could not save security question. Please retry.']);break;
        }
        sq_json(["status" => "success", "desc" => "Security details reset successfully."]);
        break;

    default:
        sq_json(["status" => "failed", "desc" => "Unknown action."]);
}

mysqli_close($connection_server);
