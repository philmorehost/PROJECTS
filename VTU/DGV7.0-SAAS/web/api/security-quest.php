<?php session_start();
ob_start();
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type, Accept, Authorization, X-Requested-With, X-App-Source");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Content-Type: application/json");

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

$api_key = mysqli_real_escape_string($connection_server, trim(strip_tags($data['api_key'] ?? '')));
$action = trim($data['action'] ?? '');

if (empty($api_key) || empty($action)) {
    sq_json(["status" => "failed", "desc" => "Missing required parameters: api_key or action."]);
    exit;
}

$q_user = mysqli_query($connection_server, "SELECT * FROM sas_users WHERE vendor_id='$vendor_id' AND api_key='$api_key' LIMIT 1");
if (mysqli_num_rows($q_user) != 1) {
    sq_json(["status" => "failed", "desc" => "Invalid API key."]);
    exit;
}
$user = mysqli_fetch_assoc($q_user);

$has_quest = !empty(trim($user["security_quest"] ?? '')) && !empty(trim($user["security_answer"] ?? '')) &&
    (strlen($user["security_answer"]) >= 3) && (strlen($user["security_answer"]) <= 20);

switch ($action) {
    case "get":
        // Always include the question bank — the client needs it both for first-time setup
        // and for the "reset security answer" form (picking a new question), regardless of
        // whether a question is already set.
        $bank = [];
        $q_bank = mysqli_query($connection_server, "SELECT id, quest FROM sas_security_quests");
        while ($row = mysqli_fetch_assoc($q_bank)) $bank[] = ["id" => (int)$row["id"], "quest" => $row["quest"]];

        if ($has_quest) {
            $quest_row = mysqli_fetch_array(mysqli_query($connection_server, "SELECT quest FROM sas_security_quests WHERE id='" . (int)$user["security_quest"] . "' LIMIT 1"));
            sq_json(["status" => "success", "has_quest" => true, "question" => $quest_row["quest"] ?? "", "quest_bank" => $bank]);
        } else {
            sq_json(["status" => "success", "has_quest" => false, "quest_bank" => $bank]);
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
        // alterUser() escapes internally — pass raw trimmed values, not pre-escaped ones.
        alterUser($user["username"], "security_quest", $quest_id);
        alterUser($user["username"], "security_answer", $answer);
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
        $pass = trim($data['password'] ?? '');
        if (empty($quest_id) || !is_numeric($quest_id) || empty($answer) || strlen($answer) < 3 || strlen($answer) > 20) {
            sq_json(["status" => "failed", "desc" => "Security answer must be between 3-20 characters."]);
            break;
        }
        $md5_pass = md5($pass);
        $q_check = mysqli_query($connection_server, "SELECT id FROM sas_users WHERE vendor_id='$vendor_id' AND username='" . $user["username"] . "' AND password='$md5_pass'");
        if (mysqli_num_rows($q_check) != 1) {
            sq_json(["status" => "failed", "desc" => "Incorrect password."]);
            break;
        }
        // alterUser() escapes internally — pass raw trimmed values, not pre-escaped ones.
        alterUser($user["username"], "security_quest", $quest_id);
        alterUser($user["username"], "security_answer", $answer);
        sq_json(["status" => "success", "desc" => "Security details reset successfully."]);
        break;

    default:
        sq_json(["status" => "failed", "desc" => "Unknown action."]);
}

mysqli_close($connection_server);
