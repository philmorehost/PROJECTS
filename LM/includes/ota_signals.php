<?php
/**
 * includes/ota_signals.php — push an "update available" signal to licensed sites.
 *
 * When a release is published (manual upload or GitHub pull), every domain
 * registered to a licence of that project can be told immediately instead of
 * waiting for its own cron to poll check-update.php.
 *
 * The signal is signed with the target licence key:
 *
 *     X-LM-Signature: sha256=<hmac_sha256(raw_body, license_key)>
 *
 * so a portal can verify it with the key it already holds and nothing else.
 * Each delivery is recorded in `ota_signal_logs`; a project opts in with
 * `script_tiers.auto_signal` and can override the receiving path with
 * `script_tiers.signal_endpoint`.
 *
 * PHP 7.4 compatible, like the rest of the manager.
 *
 * @package LicenseManager
 */

require_once __DIR__ . '/lm_config.php';
require_once __DIR__ . '/updates.php';

if (!function_exists('lm_ota_signal_default_endpoint')) {
    /**
     * Default receiving path on a licensed portal.
     *
     * @return string
     */
    function lm_ota_signal_default_endpoint()
    {
        return '/api/v1/ota-signal.php';
    }
}

if (!function_exists('lm_ota_signal_normalize_domain')) {
    /**
     * Turn whatever is stored in licensed_domains into a bare host name.
     *
     * Installations have been seen with "https://example.com/", "example.com"
     * and "www.example.com" for the same site, so everything is reduced to a
     * lower-case host without scheme, path or trailing slash.
     *
     * @param string $raw
     * @return string
     */
    function lm_ota_signal_normalize_domain($raw)
    {
        $raw = trim((string) $raw);
        if ($raw === '') {
            return '';
        }

        $raw = preg_replace('#^[a-z][a-z0-9+.-]*://#i', '', $raw);
        $raw = preg_replace('#/.*$#', '', $raw);
        $raw = preg_replace('#^www\.#i', '', $raw);

        return strtolower(rtrim($raw, './'));
    }
}

if (!function_exists('lm_ota_signal_endpoint')) {
    /**
     * Full URL to signal a given domain, honouring the project override.
     *
     * Always HTTPS: a licence key travels in the signature, and check-update.php
     * is already expected to be reachable over TLS.
     *
     * @param array  $tier
     * @param string $domain
     * @return string
     */
    function lm_ota_signal_endpoint(array $tier, $domain)
    {
        $path = isset($tier['signal_endpoint']) ? trim((string) $tier['signal_endpoint']) : '';
        if ($path === '') {
            $path = lm_ota_signal_default_endpoint();
        }
        if (strpos($path, '://') !== false) {
            $path = (string) parse_url($path, PHP_URL_PATH);
            if ($path === '') {
                $path = lm_ota_signal_default_endpoint();
            }
        }
        if ($path[0] !== '/') {
            $path = '/' . $path;
        }

        return 'https://' . lm_ota_signal_normalize_domain($domain) . $path;
    }
}

if (!function_exists('lm_ota_signal_targets')) {
    /**
     * Every active licensed domain that should receive the signal for a project.
     *
     * A licence with no registered domain yet is skipped: there is nowhere to
     * send it, and the portal will still poll check-update.php on its own.
     *
     * @param PDO $pdo
     * @param int $tierId
     * @return array<int, array{license_id:int,license_key:string,domain:string,endpoint:string,installed_version:string}>
     */
    function lm_ota_signal_targets(PDO $pdo, $tierId)
    {
        $sql = "SELECT l.id AS license_id, l.license_key, d.domain_name, d.installed_version
                  FROM licenses l
                  JOIN licensed_domains d ON d.license_id = l.id
                 WHERE l.tier_id = ?
                   AND l.status = 'active'
                   AND d.domain_name <> ''
              ORDER BY l.id ASC, d.domain_name ASC";

        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute(array((int) $tierId));
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            lm_log('signals', 'Target query failed: ' . $e->getMessage());
            return array();
        }

        $tier = array();
        try {
            $tierStmt = $pdo->prepare('SELECT * FROM script_tiers WHERE id = ?');
            $tierStmt->execute(array((int) $tierId));
            $tier = (array) $tierStmt->fetch(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            $tier = array();
        }

        $targets = array();
        $seen = array();
        foreach ($rows as $row) {
            $domain = lm_ota_signal_normalize_domain($row['domain_name']);
            if ($domain === '') {
                continue;
            }
            // One signal per domain, even when two licences point at it.
            if (isset($seen[$domain])) {
                continue;
            }
            $seen[$domain] = true;

            $targets[] = array(
                'license_id'        => (int) $row['license_id'],
                'license_key'       => (string) $row['license_key'],
                'domain'            => $domain,
                'endpoint'          => lm_ota_signal_endpoint($tier, $domain),
                'installed_version' => (string) ($row['installed_version'] ?? ''),
            );
        }

        return $targets;
    }
}

if (!function_exists('lm_ota_signal_auto_enabled')) {
    /**
     * Should publishing to this project signal automatically?
     *
     * Defaults to yes: the feature only does anything when a domain is
     * registered, so an untouched install behaves exactly as before.
     *
     * @param array $tier
     * @return bool
     */
    function lm_ota_signal_auto_enabled(array $tier)
    {
        if (!array_key_exists('auto_signal', $tier)) {
            return true;
        }

        return (int) $tier['auto_signal'] === 1;
    }
}

if (!function_exists('lm_ota_signal_payload')) {
    /**
     * Build the signed body for one target.
     *
     * @param array  $tier
     * @param array  $update
     * @param string $domain
     * @param string $downloadUrl
     * @return string JSON
     */
    function lm_ota_signal_payload(array $tier, array $update, $domain, $downloadUrl = '')
    {
        return json_encode(array(
            'event'          => 'ota.update_available',
            'project_tier'   => (string) ($tier['tier_code'] ?? ''),
            'project_name'   => (string) ($tier['tier_name'] ?? ''),
            'latest_version' => (string) ($update['version_number'] ?? ''),
            'checksum'       => (string) ($update['checksum'] ?? ''),
            'download_url'   => (string) $downloadUrl,
            'domain'         => $domain,
            'update_id'      => (int) ($update['id'] ?? 0),
            'timestamp'      => time(),
            'nonce'          => function_exists('random_bytes')
                ? bin2hex(random_bytes(8))
                : substr(sha1(uniqid('', true) . mt_rand()), 0, 16),
        ), JSON_UNESCAPED_SLASHES);
    }
}

if (!function_exists('lm_ota_signal_log_table')) {
    /**
     * @param PDO $pdo
     * @return void
     */
    function lm_ota_signal_log_table(PDO $pdo)
    {
        try {
            $pdo->exec(
                "CREATE TABLE IF NOT EXISTS `ota_signal_logs` (
                    `id` INT AUTO_INCREMENT PRIMARY KEY,
                    `update_id` INT NOT NULL,
                    `tier_id` INT NOT NULL,
                    `license_id` INT NULL,
                    `domain_name` VARCHAR(190) NOT NULL,
                    `endpoint_url` VARCHAR(255) NOT NULL,
                    `http_code` INT NOT NULL DEFAULT 0,
                    `status` VARCHAR(30) NOT NULL DEFAULT 'pending',
                    `response_body` TEXT NULL,
                    `trigger_source` VARCHAR(20) NOT NULL DEFAULT 'auto',
                    `signaled_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    KEY `idx_signal_update` (`update_id`),
                    KEY `idx_signal_tier` (`tier_id`),
                    KEY `idx_signal_domain` (`domain_name`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
            );
        } catch (PDOException $e) {
            lm_log('signals', 'Signal log table unavailable: ' . $e->getMessage());
        }
    }
}

if (!function_exists('lm_ota_signal_record')) {
    /**
     * @param PDO    $pdo
     * @param array  $row
     * @return void
     */
    function lm_ota_signal_record(PDO $pdo, array $row)
    {
        try {
            $stmt = $pdo->prepare(
                'INSERT INTO ota_signal_logs
                    (update_id, tier_id, license_id, domain_name, endpoint_url, http_code, status, response_body, trigger_source)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute(array(
                (int) $row['update_id'],
                (int) $row['tier_id'],
                (int) $row['license_id'],
                (string) $row['domain_name'],
                (string) $row['endpoint_url'],
                (int) $row['http_code'],
                (string) $row['status'],
                (string) $row['response_body'],
                (string) $row['trigger_source'],
            ));
        } catch (PDOException $e) {
            lm_log('signals', 'Could not record signal to ' . $row['domain_name'] . ': ' . $e->getMessage());
        }
    }
}

if (!function_exists('lm_ota_signal_send')) {
    /**
     * Deliver the signal to every licensed domain of one project.
     *
     * Deliveries run in parallel batches of 10 with a 10 second ceiling, so a
     * dead host cannot hold up a publish. Every outcome — sent, up_to_date,
     * skipped, failed — is written to `ota_signal_logs`.
     *
     * @param PDO   $pdo
     * @param int   $updateId
     * @param array $options source:auto|manual, force:bool, batch:int
     * @return array{ok:bool,update_id:int,tier_id:int,version:string,total:int,sent:int,accepted:int,up_to_date:int,failed:int,skipped:int,results:array}
     */
    function lm_ota_signal_send(PDO $pdo, $updateId, array $options = array())
    {
        @set_time_limit(0);

        $source = isset($options['source']) ? (string) $options['source'] : 'auto';
        $force = !empty($options['force']);
        $batchSize = isset($options['batch']) ? max(1, min(20, (int) $options['batch'])) : 10;

        $result = array(
            'ok'         => false,
            'update_id'  => (int) $updateId,
            'tier_id'    => 0,
            'version'    => '',
            'message'    => '',
            'total'      => 0,
            'sent'       => 0,
            'accepted'   => 0,
            'up_to_date' => 0,
            'failed'     => 0,
            'skipped'    => 0,
            'results'    => array(),
        );

        $stmt = $pdo->prepare(
            'SELECT u.*, t.tier_code, t.tier_name, t.signal_endpoint, t.auto_signal
               FROM script_updates u
               JOIN script_tiers t ON t.id = u.tier_id
              WHERE u.id = ?'
        );
        $stmt->execute(array((int) $updateId));
        $update = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$update) {
            $result['message'] = 'Release not found.';
            return $result;
        }

        $result['tier_id'] = (int) $update['tier_id'];
        $result['version'] = (string) $update['version_number'];

        $tier = array(
            'tier_code'       => $update['tier_code'],
            'tier_name'       => $update['tier_name'],
            'signal_endpoint' => $update['signal_endpoint'],
            'auto_signal'     => $update['auto_signal'],
        );

        if (!$force && $source === 'auto' && !lm_ota_signal_auto_enabled($tier)) {
            $result['ok'] = true;
            $result['message'] = 'Automatic signalling is switched off for this project.';
            $result['skipped'] = 1;
            return $result;
        }

        $version = ltrim((string) $update['version_number'], 'vV');
        $targets = lm_ota_signal_targets($pdo, (int) $update['tier_id']);
        $result['total'] = count($targets);

        if (empty($targets)) {
            $result['ok'] = true;
            $result['message'] = 'No active licensed domain is registered for this project yet.';
            return $result;
        }

        lm_ota_signal_log_table($pdo);

        // A portal already running this version (or newer) has nothing to do.
        $pending = array();
        foreach ($targets as $target) {
            $installed = ltrim($target['installed_version'], 'vV');
            if (!$force && $installed !== '' && !lm_version_is_newer($version, $installed)) {
                $result['up_to_date']++;
                lm_ota_signal_record($pdo, array(
                    'update_id'     => (int) $updateId,
                    'tier_id'       => (int) $update['tier_id'],
                    'license_id'    => $target['license_id'],
                    'domain_name'   => $target['domain'],
                    'endpoint_url'  => $target['endpoint'],
                    'http_code'     => 0,
                    'status'        => 'up_to_date',
                    'response_body' => 'Already reports v' . $installed,
                    'trigger_source' => $source,
                ));
                continue;
            }
            $pending[] = $target;
        }

        if (empty($pending)) {
            $result['ok'] = true;
            $result['message'] = 'Every licensed site already reports this version or newer.';
            return $result;
        }

        if (!function_exists('curl_init') || !function_exists('curl_multi_init')) {
            $result['ok'] = true;
            $result['failed'] = count($pending);
            $result['message'] = 'The PHP cURL extension is not available on this server, so no signal could be delivered.';

            foreach ($pending as $target) {
                lm_ota_signal_record($pdo, array(
                    'update_id'      => (int) $updateId,
                    'tier_id'        => (int) $update['tier_id'],
                    'license_id'     => $target['license_id'],
                    'domain_name'    => $target['domain'],
                    'endpoint_url'   => $target['endpoint'],
                    'http_code'      => 0,
                    'status'         => 'failed',
                    'response_body'  => 'cURL extension missing on the License Manager server.',
                    'trigger_source' => $source,
                ));
            }

            return $result;
        }

        $downloadUrl = lm_site_url('download.php');

        foreach (array_chunk($pending, $batchSize) as $chunk) {
            $handles = array();

            foreach ($chunk as $index => $target) {
                $body = lm_ota_signal_payload($tier, $update, $target['domain'], $downloadUrl);
                $signature = 'sha256=' . hash_hmac('sha256', $body, $target['license_key']);

                $ch = curl_init($target['endpoint']);
                curl_setopt_array($ch, array(
                    CURLOPT_POST           => true,
                    CURLOPT_POSTFIELDS     => $body,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_FOLLOWLOCATION => false,
                    CURLOPT_CONNECTTIMEOUT => 5,
                    CURLOPT_TIMEOUT        => 10,
                    CURLOPT_SSL_VERIFYPEER => true,
                    CURLOPT_SSL_VERIFYHOST => 2,
                    CURLOPT_HTTPHEADER     => array(
                        'Content-Type: application/json',
                        'Accept: application/json',
                        'X-LM-Signature: ' . $signature,
                        'User-Agent: LicenseManager-OTA/' . (defined('LM_VERSION') ? LM_VERSION : 'unknown'),
                    ),
                ));

                $handles[$index] = array('handle' => $ch, 'target' => $target);
            }

            $multi = curl_multi_init();
            foreach ($handles as $entry) {
                curl_multi_add_handle($multi, $entry['handle']);
            }

            do {
                $status = curl_multi_exec($multi, $running);
                if ($running) {
                    curl_multi_select($multi, 1.0);
                }
            } while ($running && $status === CURLM_OK);

            foreach ($handles as $index => $entry) {
                $ch = $entry['handle'];
                $target = $entry['target'];
                $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $raw = (string) curl_multi_getcontent($ch);
                $curlError = curl_error($ch);

                curl_multi_remove_handle($multi, $ch);
                curl_close($ch);

                $decoded = json_decode($raw, true);
                $apiStatus = is_array($decoded) ? strtolower((string) ($decoded['status'] ?? '')) : '';

                if ($curlError !== '') {
                    $state = 'failed';
                    $result['failed']++;
                    $responseBody = 'cURL: ' . $curlError;
                } elseif ($httpCode >= 200 && $httpCode < 300) {
                    if ($apiStatus === 'up_to_date') {
                        $state = 'up_to_date';
                        $result['up_to_date']++;
                    } elseif ($apiStatus === 'skipped') {
                        $state = 'skipped';
                        $result['skipped']++;
                    } else {
                        $state = 'accepted';
                        $result['accepted']++;
                    }
                    $result['sent']++;
                    $responseBody = $raw;
                } else {
                    $state = 'failed';
                    $result['failed']++;
                    $responseBody = $raw !== '' ? $raw : ('HTTP ' . $httpCode);
                }

                lm_ota_signal_record($pdo, array(
                    'update_id'      => (int) $updateId,
                    'tier_id'        => (int) $update['tier_id'],
                    'license_id'     => $target['license_id'],
                    'domain_name'    => $target['domain'],
                    'endpoint_url'   => $target['endpoint'],
                    'http_code'      => $httpCode,
                    'status'         => $state,
                    'response_body'  => substr((string) $responseBody, 0, 4000),
                    'trigger_source' => $source,
                ));

                $result['results'][] = array(
                    'domain'    => $target['domain'],
                    'endpoint'  => $target['endpoint'],
                    'http_code' => $httpCode,
                    'status'    => $state,
                    'message'   => substr(trim((string) $raw), 0, 300),
                );
            }

            curl_multi_close($multi);
        }

        $result['ok'] = true;
        $result['message'] = sprintf(
            'Signalled %d domain(s): %d accepted, %d already current, %d skipped, %d failed.',
            $result['total'],
            $result['accepted'],
            $result['up_to_date'],
            $result['skipped'],
            $result['failed']
        );

        lm_log('signals', 'v' . $version . ' → ' . $result['message']);

        return $result;
    }
}

if (!function_exists('lm_ota_signal_recent')) {
    /**
     * Latest signal deliveries, newest first.
     *
     * @param PDO $pdo
     * @param int $limit
     * @return array
     */
    function lm_ota_signal_recent(PDO $pdo, $limit = 50)
    {
        $limit = max(1, min(200, (int) $limit));

        try {
            $stmt = $pdo->query(
                "SELECT s.*, t.tier_code, u.version_number
                   FROM ota_signal_logs s
                   LEFT JOIN script_tiers t ON t.id = s.tier_id
                   LEFT JOIN script_updates u ON u.id = s.update_id
                  ORDER BY s.id DESC LIMIT {$limit}"
            );
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            return array();
        }
    }
}

if (!function_exists('lm_ota_signal_summary')) {
    /**
     * Counts used by the page header.
     *
     * @param PDO $pdo
     * @return array
     */
    function lm_ota_signal_summary(PDO $pdo)
    {
        $summary = array('total' => 0, 'accepted' => 0, 'failed' => 0, 'last_at' => '');

        try {
            $row = $pdo->query(
                "SELECT COUNT(*) AS total,
                        SUM(status IN ('accepted','sent')) AS accepted,
                        SUM(status = 'failed') AS failed,
                        MAX(signaled_at) AS last_at
                   FROM ota_signal_logs"
            )->fetch(PDO::FETCH_ASSOC);

            if ($row) {
                $summary['total'] = (int) $row['total'];
                $summary['accepted'] = (int) $row['accepted'];
                $summary['failed'] = (int) $row['failed'];
                $summary['last_at'] = (string) ($row['last_at'] ?? '');
            }
        } catch (PDOException $e) {
            // Table not created yet.
        }

        return $summary;
    }
}
