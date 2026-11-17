<?php
/**
 * github-webhook.php
 * Receives push webhooks from GitHub (e.g. philmorehost/PROJECTS), detects which configured
 * project subdirectories were modified, automatically pulls & packages new OTA ZIP releases,
 * and broadcasts signed update signals to all websites licensed to those projects.
 */

header('Content-Type: application/json');

function gh_webhook_log(string $msg): void
{
    @file_put_contents(__DIR__ . '/github_webhook.log', date('[Y-m-d H:i:s] ') . $msg . "\n", FILE_APPEND);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method Not Allowed. Use POST.']);
    exit;
}

$rawBody = (string)file_get_contents('php://input');
$ghEvent = strtolower(trim((string)($_SERVER['HTTP_X_GITHUB_EVENT'] ?? '')));
$ghSig   = trim((string)($_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? ''));

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/ota_github_helper.php';

$settings = OTAGitHubHelper::loadSettings();
$webhookSecret = trim((string)($settings['github_webhook_secret'] ?? ''));

if ($webhookSecret === '') {
    gh_webhook_log('Rejected webhook: github_webhook_secret is not configured in LM Settings.');
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'GitHub webhook secret is not configured in License Manager settings.']);
    exit;
}

$expectedSig = 'sha256=' . hash_hmac('sha256', $rawBody, $webhookSecret);
if ($ghSig === '' || !hash_equals($expectedSig, $ghSig)) {
    gh_webhook_log('Rejected webhook: invalid X-Hub-Signature-256 header.');
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Invalid webhook signature.']);
    exit;
}

if ($ghEvent === 'ping') {
    gh_webhook_log('Received verified GitHub ping event.');
    echo json_encode(['status' => 'pong', 'message' => 'GitHub webhook verified and connected to License Manager.']);
    exit;
}

if ($ghEvent !== 'push') {
    echo json_encode(['status' => 'ignored', 'message' => "Ignored GitHub event '{$ghEvent}'."]);
    exit;
}

$payload = json_decode($rawBody, true);
if (!is_array($payload)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Invalid JSON payload.']);
    exit;
}

// Ignore branch deletion pushes
if (!empty($payload['deleted'])) {
    echo json_encode(['status' => 'ignored', 'message' => 'Branch deletion ignored.']);
    exit;
}

$pushedRepo = strtolower(trim((string)($payload['repository']['full_name'] ?? '')));
$ref = (string)($payload['ref'] ?? '');
if (!str_starts_with($ref, 'refs/heads/')) {
    echo json_encode(['status' => 'ignored', 'message' => 'Non-branch push ignored.']);
    exit;
}
$pushedBranch = substr($ref, strlen('refs/heads/'));

// Collect all touched file paths in this push
$changedFiles = [];
if (!empty($payload['commits']) && is_array($payload['commits'])) {
    foreach ($payload['commits'] as $commit) {
        foreach (['added', 'modified', 'removed'] as $bucket) {
            if (!empty($commit[$bucket]) && is_array($commit[$bucket])) {
                foreach ($commit[$bucket] as $fPath) {
                    $changedFiles[] = ltrim(str_replace('\\', '/', (string)$fPath), '/');
                }
            }
        }
    }
}
$changedFiles = array_values(array_unique($changedFiles));

// Match against projects with auto_pull_github = 1
$defaultRepo = strtolower(trim((string)($settings['github_default_repo'] ?? '')));
$stmt = $pdo->query("SELECT * FROM script_tiers WHERE auto_pull_github = 1 ORDER BY id ASC");
$candidates = $stmt->fetchAll(PDO::FETCH_ASSOC);

$matchedTiers = [];
foreach ($candidates as $tier) {
    $tierRepo = strtolower(trim((string)($tier['github_repo'] ?: $defaultRepo)));
    $tierBranch = trim((string)($tier['github_branch'] ?: 'main'));
    $tierSubdir = trim(str_replace('\\', '/', (string)($tier['github_subdir'] ?? '')), '/');

    if ($tierRepo === '' || $tierRepo !== $pushedRepo || $tierBranch !== $pushedBranch) {
        continue;
    }

    if ($tierSubdir === '') {
        $matchedTiers[] = $tier;
        continue;
    }

    foreach ($changedFiles as $cf) {
        if (str_starts_with($cf, $tierSubdir . '/')) {
            $matchedTiers[] = $tier;
            break;
        }
    }
}

if (empty($matchedTiers)) {
    gh_webhook_log("Push to {$pushedRepo}:{$pushedBranch} touched no auto-pull project directories.");
    echo json_encode(['status' => 'ignored', 'message' => 'No auto-pull projects matched the modified paths.']);
    exit;
}

$matchedCodes = array_map(fn($t) => $t['tier_code'], $matchedTiers);
gh_webhook_log("Accepted push to {$pushedRepo}:{$pushedBranch}; queuing GitHub pull & client signals for: " . implode(', ', $matchedCodes));

// Respond 202 Accepted immediately so GitHub's 10s webhook timeout never trips, then build & signal in background
$ackJson = json_encode([
    'status'           => 'accepted',
    'matched_projects' => $matchedCodes,
    'message'          => 'Packaging updates from GitHub and signaling licensed websites in background.',
]);

ignore_user_abort(true);
set_time_limit(0);

http_response_code(202);
header('Connection: close');
header('Content-Length: ' . strlen($ackJson));
echo $ackJson;

if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
} else {
    if (ob_get_level() > 0) {
        @ob_end_flush();
    }
    @flush();
}

foreach ($matchedTiers as $tier) {
    try {
        $res = OTAGitHubHelper::pullProjectFromGitHub($pdo, (int)$tier['id']);
        gh_webhook_log("[{$tier['tier_code']}] {$res['status']}: " . ($res['message'] ?? ''));
    } catch (Throwable $e) {
        gh_webhook_log("[{$tier['tier_code']}] Exception: " . $e->getMessage());
    }
}
exit;
