<?php
/**
 * check-update.php — OTA update endpoint for licensed installations.
 *
 * GET/POST parameters
 *   license_key     required for licensed installs
 *   domain          required for licensed installs
 *   current_version optional; the version currently installed on the site.
 *                   When supplied it is stored so the License Manager knows
 *                   who has already updated (and who still needs a reminder).
 *   project         optional tier code ("SAAS") or project key ("PRJ-SAAS").
 *                   Required only when the licence is not yet mapped to a tier.
 *   force_version   optional; request one specific published version.
 *   platform        optional (e.g. "php", "android") — stored for diagnostics.
 *
 * Responses
 *   { status, latest_version, update_required, changelog, download_url,
 *     checksum, tier_detected, installed_version }
 */

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/updates.php';

/**
 * @param string $message
 * @return void
 */
function check_update_log($message)
{
    lm_log('ota', $message);
}

/**
 * Resolve a project (tier) from a code / project key.
 *
 * @param PDO    $pdo
 * @param string $project
 * @return array|null
 */
function lm_ota_resolve_project(PDO $pdo, $project)
{
    $project = trim((string) $project);
    if ($project === '') {
        return null;
    }

    $stmt = $pdo->prepare("SELECT * FROM script_tiers WHERE tier_code = ? OR project_key = ? LIMIT 1");
    $stmt->execute(array(strtoupper($project), $project));

    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

/**
 * Issue a one-time download token and return the URL.
 *
 * @param PDO   $pdo
 * @param array $update
 * @param int|null $licenseId
 * @return string
 */
function lm_ota_issue_download(PDO $pdo, array $update, $licenseId)
{
    $token = bin2hex(random_bytes(32));
    $expiresAt = gmdate('Y-m-d H:i:s', time() + 1800);

    $stmt = $pdo->prepare("INSERT INTO download_tokens (license_id, update_id, token, expires_at) VALUES (?, ?, ?, ?)");
    $stmt->execute(array($licenseId, (int) $update['id'], $token, $expiresAt));

    return lm_site_url('download.php?token=' . $token);
}

$requesterIp = lm_client_ip();

/* -------------------------------------------------------------------------
 * 1. Whitelisted (internal / unlicensed) platforms
 * ---------------------------------------------------------------------- */

$settings = lm_settings();
$whitelisted = $settings['whitelisted_ips'];
if (!is_array($whitelisted)) {
    $whitelisted = array_filter(array_map('trim', explode(',', (string) $whitelisted)));
}

if (in_array($requesterIp, $whitelisted, true)) {
    check_update_log('Bypassing licence validation for whitelisted IP ' . $requesterIp);

    try {
        $projectCode = trim((string) ($_REQUEST['project'] ?? 'SAAS'));
        $tier = lm_ota_resolve_project($pdo, $projectCode);
        if (!$tier) {
            $tier = lm_ota_resolve_project($pdo, 'SAAS');
        }
        if (!$tier) {
            http_response_code(404);
            echo json_encode(array('status' => 'error', 'message' => 'No script project configured.'));
            exit;
        }

        $forceVersion = lm_version_clean($_REQUEST['force_version'] ?? '');
        if ($forceVersion !== '') {
            $stmt = $pdo->prepare("SELECT * FROM script_updates WHERE version_number = ? AND tier_id = ? AND is_released = 1 LIMIT 1");
            $stmt->execute(array($forceVersion, (int) $tier['id']));
            $latest = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        } else {
            $latest = lm_latest_update_for_tier($pdo, (int) $tier['id'], true);
        }

        if (!$latest) {
            echo json_encode(array('status' => 'success', 'message' => 'No updates released for this project.', 'latest_version' => '0.00', 'update_required' => false));
            exit;
        }

        $currentVersion = lm_version_clean($_REQUEST['current_version'] ?? '');
        $downloadUrl = lm_ota_issue_download($pdo, $latest, null);

        echo json_encode(array(
            'status' => 'success',
            'latest_version' => $latest['version_number'],
            'installed_version' => $currentVersion,
            'update_required' => ($currentVersion !== '' && lm_version_compare($currentVersion, $latest['version_number']) >= 0) ? false : true,
            'changelog' => $latest['changelog'],
            'changelog_format' => $latest['changelog_format'] ?? 'html',
            'download_url' => $downloadUrl,
            'checksum' => $latest['checksum'],
            'release_date' => $latest['release_date'],
            'tier_detected' => $tier['tier_code'] . ' (whitelisted)',
            'project' => $tier['tier_name'],
        ));
        exit;
    } catch (PDOException $e) {
        check_update_log('Database error (whitelisted): ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(array('status' => 'error', 'message' => 'Database error occurred.'));
        exit;
    }
}

/* -------------------------------------------------------------------------
 * 2. Licensed installations
 * ---------------------------------------------------------------------- */

$licenseKey = trim((string) ($_REQUEST['license_key'] ?? $_REQUEST['key'] ?? ''));
$requestingDomain = lm_normalize_domain($_REQUEST['domain'] ?? '');
$currentVersion = lm_version_clean($_REQUEST['current_version'] ?? '');

if ($licenseKey === '' || $requestingDomain === '') {
    http_response_code(400);
    echo json_encode(array('status' => 'error', 'message' => 'Missing license_key or domain parameter.'));
    exit;
}

check_update_log('Licence check: key ' . substr($licenseKey, 0, 12) . '…' . ' domain ' . $requestingDomain . ' version ' . ($currentVersion !== '' ? $currentVersion : 'not reported'));

try {
    $stmt = $pdo->prepare(
        "SELECT l.*, t.tier_code, t.tier_name, t.project_key, t.is_active
         FROM licenses l
         LEFT JOIN script_tiers t ON l.tier_id = t.id
         WHERE l.license_key = ?
         LIMIT 1"
    );
    $stmt->execute(array($licenseKey));
    $license = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$license) {
        check_update_log('Authentication failed: licence key not found.');
        http_response_code(403);
        echo json_encode(array('status' => 'error', 'message' => 'Invalid license key.'));
        exit;
    }

    if ($license['status'] !== 'active') {
        check_update_log('Authentication failed: licence status ' . $license['status']);
        http_response_code(403);
        echo json_encode(array('status' => 'suspended', 'message' => 'Your license is suspended or expired.'));
        exit;
    }

    // Resolve the project / tier.
    $requestedProject = trim((string) ($_REQUEST['project'] ?? ''));
    $requestedTier = $requestedProject !== '' ? lm_ota_resolve_project($pdo, $requestedProject) : null;

    $tier = array(
        'id' => (int) ($license['tier_id'] ?? 0),
        'tier_code' => (string) ($license['tier_code'] ?? ''),
        'tier_name' => (string) ($license['tier_name'] ?? ''),
        'project_key' => (string) ($license['project_key'] ?? ''),
    );

    if ($requestedTier) {
        if ($tier['id'] > 0 && (int) $tier['id'] !== (int) $requestedTier['id']) {
            check_update_log('Project mismatch: licence tier ' . $tier['id'] . ' vs requested ' . $requestedTier['id']);
            http_response_code(403);
            echo json_encode(array('status' => 'error', 'message' => 'This licence belongs to a different script project.'));
            exit;
        }
        $tier = $requestedTier;
        if ((int) ($license['tier_id'] ?? 0) === 0) {
            // Self-heal legacy licences that never had a tier assigned.
            $pdo->prepare("UPDATE licenses SET tier_id = ? WHERE id = ?")->execute(array((int) $tier['id'], (int) $license['id']));
            check_update_log('Assigned tier ' . $tier['tier_code'] . ' to licence ' . $license['id']);
        }
    }

    if ((int) $tier['id'] === 0) {
        // Legacy fallback for DGV7 installs.
        if (($license['license_type'] ?? '') === 'extended') {
            $fallback = lm_ota_resolve_project($pdo, 'SAAS');
        } else {
            $fallback = lm_ota_resolve_project($pdo, 'NON-SAAS');
        }
        if ($fallback) {
            $tier = $fallback;
            $pdo->prepare("UPDATE licenses SET tier_id = ? WHERE id = ?")->execute(array((int) $tier['id'], (int) $license['id']));
            check_update_log('WARNING: licence ' . $license['id'] . ' had no tier; mapped to ' . $tier['tier_code']);
        }
    }

    if ((int) $tier['id'] === 0) {
        echo json_encode(array('status' => 'success', 'message' => 'No update channel configured for this licence.', 'latest_version' => '0.00', 'update_required' => false));
        exit;
    }

    // Domain registration / validation.
    $domainStmt = $pdo->prepare("SELECT * FROM licensed_domains WHERE license_id = ? AND domain_name = ? LIMIT 1");
    $domainStmt->execute(array((int) $license['id'], $requestingDomain));
    $domainRecord = $domainStmt->fetch(PDO::FETCH_ASSOC);

    if (!$domainRecord) {
        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM licensed_domains WHERE license_id = ?");
        $countStmt->execute(array((int) $license['id']));
        $domainCount = (int) $countStmt->fetchColumn();
        $maxDomains = max(1, (int) ($license['max_domains'] ?? ($tier['max_domains'] ?? 1)));

        if ($domainCount < $maxDomains) {
            try {
                $pdo->prepare("INSERT INTO licensed_domains (license_id, domain_name, server_ip, installed_version, version_reported_at) VALUES (?, ?, ?, ?, ?)")
                    ->execute(array(
                        (int) $license['id'],
                        $requestingDomain,
                        $requesterIp,
                        $currentVersion !== '' ? $currentVersion : null,
                        $currentVersion !== '' ? gmdate('Y-m-d H:i:s') : null,
                    ));
                check_update_log('Registered domain ' . $requestingDomain . ' for licence ' . $license['id']);
            } catch (PDOException $e) {
                // A concurrent request may have inserted the same domain.
                check_update_log('Domain registration race for ' . $requestingDomain . ': ' . $e->getMessage());
            }
        } else {
            check_update_log('Domain limit reached for licence ' . $license['id'] . ' (' . $maxDomains . ')');
            http_response_code(403);
            echo json_encode(array('status' => 'error', 'message' => 'This license has reached the maximum number of registered domains.'));
            exit;
        }
    }

    // Always refresh IP + reported version, even when the domain was just added.
    try {
        if ($currentVersion !== '') {
            $pdo->prepare(
                "UPDATE licensed_domains
                 SET server_ip = ?, last_update_check = NOW(), installed_version = ?, version_reported_at = NOW()
                 WHERE license_id = ? AND domain_name = ?"
            )->execute(array($requesterIp, $currentVersion, (int) $license['id'], $requestingDomain));
        } else {
            $pdo->prepare(
                "UPDATE licensed_domains SET server_ip = ?, last_update_check = NOW()
                 WHERE license_id = ? AND domain_name = ?"
            )->execute(array($requesterIp, (int) $license['id'], $requestingDomain));
        }
    } catch (PDOException $e) {
        check_update_log('Could not update domain metadata: ' . $e->getMessage());
    }

    // Latest (or forced) update for this tier.
    $forceVersion = lm_version_clean($_REQUEST['force_version'] ?? '');
    if ($forceVersion !== '') {
        $updStmt = $pdo->prepare("SELECT * FROM script_updates WHERE version_number = ? AND tier_id = ? AND is_released = 1 LIMIT 1");
        $updStmt->execute(array($forceVersion, (int) $tier['id']));
        $latestUpdate = $updStmt->fetch(PDO::FETCH_ASSOC) ?: null;
    } else {
        $latestUpdate = lm_latest_update_for_tier($pdo, (int) $tier['id'], true);
    }

    if (!$latestUpdate) {
        echo json_encode(array(
            'status' => 'success',
            'message' => 'No updates released for this tier.',
            'latest_version' => '0.00',
            'installed_version' => $currentVersion,
            'update_required' => false,
            'tier_detected' => $tier['tier_code'],
        ));
        exit;
    }

    $updateRequired = true;
    if ($currentVersion !== '' && lm_version_compare($currentVersion, $latestUpdate['version_number']) >= 0) {
        $updateRequired = false;
    }

    $downloadUrl = lm_ota_issue_download($pdo, $latestUpdate, (int) $license['id']);

    check_update_log('Serving v' . $latestUpdate['version_number'] . ' to ' . $requestingDomain . ' (installed: ' . ($currentVersion !== '' ? $currentVersion : 'unknown') . ')');

    echo json_encode(array(
        'status' => 'success',
        'latest_version' => $latestUpdate['version_number'],
        'installed_version' => $currentVersion,
        'update_required' => $updateRequired,
        'checksum' => $latestUpdate['checksum'],
        'changelog' => $latestUpdate['changelog'],
        'changelog_format' => $latestUpdate['changelog_format'] ?? 'html',
        'release_date' => $latestUpdate['release_date'],
        'download_url' => $downloadUrl,
        'tier_detected' => $tier['tier_code'],
        'project' => $tier['tier_name'],
    ));
    exit;

} catch (PDOException $e) {
    check_update_log('Database error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(array('status' => 'error', 'message' => 'Internal database error.'));
    exit;
}
