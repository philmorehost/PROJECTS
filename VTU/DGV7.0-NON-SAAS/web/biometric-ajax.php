<?php
require_once __DIR__ . '/../func/bc-secure-session.php';

ob_start();
if (session_status() === PHP_SESSION_NONE) {
    bc_secure_session_start();
}

// Explicitly include config, func, and WebAuthn cryptographic verifier
require_once("../func/bc-config.php");
require_once("../func/bc-func.php");
require_once("../func/bc-webauthn.php");

header('Content-Type: application/json');
header('Cache-Control: no-store');

$action = isset($_GET['action']) ? $_GET['action'] : '';

if (!$connection_server) {
    ob_clean();
    echo json_encode(array('status' => 'error', 'message' => 'Database connection failed'));
    exit;
}

$rp_id_array = explode(':', (string)($_SERVER['HTTP_HOST'] ?? 'localhost'));
$rpId = preg_replace('/[^A-Za-z0-9.\-]/', '', (string)$rp_id_array[0]);
if ($rpId === '') $rpId = 'localhost';

if ($action === 'status') {
    if (!isset($_SESSION['user_session'])) {
        ob_clean();
        echo json_encode(array('status' => 'error', 'message' => 'Not logged in'));
        exit;
    }
    $username = mysqli_real_escape_string($connection_server, (string)$_SESSION['user_session']);
    $u_res = mysqli_query($connection_server, "SELECT id FROM sas_users WHERE username='$username' LIMIT 1");
    $u = $u_res ? mysqli_fetch_assoc($u_res) : null;
    $count = 0;
    if ($u) {
        $uid = (int)$u['id'];
        $c_res = @mysqli_query($connection_server, "SELECT COUNT(*) AS c FROM sas_user_biometrics WHERE user_id='$uid' AND public_key LIKE '-----BEGIN PUBLIC KEY-----%'");
        if ($c_res && ($cr = mysqli_fetch_assoc($c_res))) {
            $count = (int)$cr['c'];
        }
    }
    ob_clean();
    echo json_encode(array('status' => 'success', 'registered' => $count > 0, 'count' => $count));
    exit;
}

if ($action === 'remove') {
    if (!isset($_SESSION['user_session'])) {
        ob_clean();
        echo json_encode(array('status' => 'error', 'message' => 'Not logged in'));
        exit;
    }
    $username = mysqli_real_escape_string($connection_server, (string)$_SESSION['user_session']);
    $u_res = mysqli_query($connection_server, "SELECT id FROM sas_users WHERE username='$username' LIMIT 1");
    $u = $u_res ? mysqli_fetch_assoc($u_res) : null;
    if ($u) {
        $uid = (int)$u['id'];
        @mysqli_query($connection_server, "DELETE FROM sas_user_biometrics WHERE user_id='$uid'");
    }
    ob_clean();
    echo json_encode(array('status' => 'success', 'message' => 'Biometric passkeys removed.'));
    exit;
}

if ($action === 'get_registration_options') {
    if (!isset($_SESSION['user_session'])) {
        ob_clean();
        echo json_encode(array('status' => 'error', 'message' => 'Not logged in'));
        exit;
    }

    $username = mysqli_real_escape_string($connection_server, (string)$_SESSION['user_session']);
    $user_res = mysqli_query($connection_server, "SELECT id, username, firstname FROM sas_users WHERE username='$username' LIMIT 1");
    $user = $user_res ? mysqli_fetch_assoc($user_res) : null;
    if (!$user) {
        ob_clean();
        echo json_encode(array('status' => 'error', 'message' => 'User not found'));
        exit;
    }

    $challenge = function_exists('random_bytes') ? random_bytes(32) : openssl_random_pseudo_bytes(32);
    $_SESSION['biometric_challenge'] = base64url_encode($challenge);

    $options = array(
        'challenge' => $_SESSION['biometric_challenge'],
        'rp' => array(
            'name' => $rpId,
            'id' => $rpId
        ),
        'user' => array(
            'id' => base64url_encode((string)$user['id']),
            'name' => $user['username'],
            'displayName' => $user['firstname'] ?: $user['username']
        ),
        'pubKeyCredParams' => array(
            array('type' => 'public-key', 'alg' => -7), // ES256
            array('type' => 'public-key', 'alg' => -257) // RS256
        ),
        'timeout' => 60000,
        'attestation' => 'none',
        'authenticatorSelection' => array(
            'authenticatorAttachment' => 'platform',
            'userVerification' => 'preferred',
            'residentKey' => 'required',
            'requireResidentKey' => true
        )
    );

    ob_clean();
    echo json_encode($options);
    exit;
}

if ($action === 'verify_registration') {
    if (!isset($_SESSION['user_session'])) {
        ob_clean();
        echo json_encode(array('status' => 'error', 'message' => 'Not logged in'));
        exit;
    }

    $data = json_decode(file_get_contents('php://input'), true);
    $username = mysqli_real_escape_string($connection_server, (string)$_SESSION['user_session']);
    $user_res = mysqli_query($connection_server, "SELECT id, vendor_id, username FROM sas_users WHERE username='$username' LIMIT 1");
    $user = $user_res ? mysqli_fetch_assoc($user_res) : null;
    if (!$user) {
        ob_clean();
        echo json_encode(array('status' => 'error', 'message' => 'User not found'));
        exit;
    }
    $user_id = (int)$user['id'];

    $expectedChallenge = (string)($_SESSION['biometric_challenge'] ?? '');
    unset($_SESSION['biometric_challenge']);

    $clientDataJSON = (string)($data['response']['clientDataJSON'] ?? '');
    $attestationObject = (string)($data['response']['attestationObject'] ?? '');
    $verified = bc_webauthn_verify_attestation($clientDataJSON, $attestationObject, $expectedChallenge, $rpId);

    if (empty($verified['ok'])) {
        ob_clean();
        http_response_code(400);
        echo json_encode(array('status' => 'error', 'message' => $verified['error'] ?? 'Invalid biometric registration.'));
        exit;
    }

    $credential_id = (string)$verified['credential_id'];
    $public_key_pem = (string)$verified['public_key_pem'];

    $del = mysqli_prepare($connection_server, "DELETE FROM sas_user_biometrics WHERE credential_id = ?");
    if ($del) {
        mysqli_stmt_bind_param($del, "s", $credential_id);
        mysqli_stmt_execute($del);
    }

    $stmt = mysqli_prepare($connection_server, "INSERT INTO sas_user_biometrics (user_id, credential_id, public_key) VALUES (?, ?, ?)");
    mysqli_stmt_bind_param($stmt, "iss", $user_id, $credential_id, $public_key_pem);

    ob_clean();
    if (mysqli_stmt_execute($stmt)) {
        if (function_exists('bc_sec_audit')) {
            bc_sec_audit((int)$user['vendor_id'], 'user', (string)$user['username'], 'biometric_registered', (string)$user_id, 'WebAuthn passkey registered');
        }
        echo json_encode(array('status' => 'success'));
    } else {
        echo json_encode(array('status' => 'error', 'message' => mysqli_error($connection_server)));
    }
    exit;
}

if ($action === 'get_authentication_options') {
    $challenge = function_exists('random_bytes') ? random_bytes(32) : openssl_random_pseudo_bytes(32);
    $_SESSION['biometric_challenge'] = base64url_encode($challenge);

    $options = array(
        'challenge' => $_SESSION['biometric_challenge'],
        'timeout' => 60000,
        'rpId' => $rpId,
        'userVerification' => 'preferred'
    );

    ob_clean();
    echo json_encode($options);
    exit;
}

if ($action === 'verify_authentication') {
    if (function_exists('bc_is_rate_limited') && bc_is_rate_limited('webauthn_login', (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown'), 20, 300)) {
        ob_clean();
        http_response_code(429);
        echo json_encode(array('status' => 'error', 'message' => 'Too many biometric attempts. Please wait a few minutes.'));
        exit;
    }

    $data = json_decode(file_get_contents('php://input'), true);
    $credential_id = (string)($data['id'] ?? '');
    $clientDataJSON = (string)($data['response']['clientDataJSON'] ?? '');
    $authenticatorData = (string)($data['response']['authenticatorData'] ?? '');
    $signature = (string)($data['response']['signature'] ?? '');

    $expectedChallenge = (string)($_SESSION['biometric_challenge'] ?? '');
    unset($_SESSION['biometric_challenge']);

    if ($credential_id === '' || $expectedChallenge === '') {
        ob_clean();
        http_response_code(400);
        echo json_encode(array('status' => 'error', 'message' => 'Biometric challenge missing or expired. Please try again.'));
        exit;
    }

    $stmt = mysqli_prepare($connection_server, "SELECT u.*, b.public_key FROM sas_user_biometrics b JOIN sas_users u ON b.user_id = u.id WHERE b.credential_id = ? LIMIT 1");
    mysqli_stmt_bind_param($stmt, "s", $credential_id);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $row = $res ? mysqli_fetch_assoc($res) : null;

    if (!$row) {
        ob_clean();
        http_response_code(401);
        echo json_encode(array('status' => 'error', 'message' => 'Biometric credential not recognized. Please sign in with your password and re-enable biometrics.'));
        exit;
    }

    if ((int)($row['status'] ?? 0) !== 1 || !empty($row['is_blocked'])) {
        ob_clean();
        http_response_code(403);
        echo json_encode(array('status' => 'error', 'message' => 'Account is not active.'));
        exit;
    }

    // Cryptographically verify WebAuthn assertion signature against stored PEM public key.
    $verify = bc_webauthn_verify_assertion(
        $clientDataJSON,
        $authenticatorData,
        $signature,
        (string)($row['public_key'] ?? ''),
        $expectedChallenge,
        $rpId
    );
    if (empty($verify['ok'])) {
        ob_clean();
        http_response_code(401);
        echo json_encode(array('status' => 'error', 'message' => $verify['error'] ?? 'Biometric signature verification failed.'));
        exit;
    }

    // Check vendor is active
    $vid = (int)($row['vendor_id'] ?? 0);
    $vq = mysqli_query($connection_server, "SELECT id, status FROM sas_vendors WHERE id='$vid' AND status=1 LIMIT 1");
    if (!$vq || mysqli_num_rows($vq) !== 1) {
        ob_clean();
        http_response_code(403);
        echo json_encode(array('status' => 'error', 'message' => 'Platform is currently unavailable.'));
        exit;
    }

    if (session_status() === PHP_SESSION_ACTIVE) {
        @session_regenerate_id(true);
    }
    $_SESSION['user_session'] = $row['username'];
    if (function_exists('bc_auth_epoch_stamp')) bc_auth_epoch_stamp();
    if (function_exists('bc_browser_binding_stamp')) bc_browser_binding_stamp('user', $row);
    if (function_exists('bc_sec_audit')) {
        bc_sec_audit($vid, 'user', (string)$row['username'], 'biometric_login', (string)$row['id'], 'WebAuthn biometric login verified');
    }

    $redirect = function_exists('bc_web_next_onboarding_url')
        ? ltrim(bc_web_next_onboarding_url($connection_server, $row, $vid), '/')
        : 'web/Dashboard.php';
    if (strpos($redirect, 'web/') === 0) {
        $redirect = substr($redirect, 4);
    }

    ob_clean();
    echo json_encode(array('status' => 'success', 'redirect' => $redirect));
    exit;
}
