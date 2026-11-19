<?php
/**
 * includes/release_retention.php — how many releases a project keeps.
 *
 * A project that pulls from GitHub publishes a new version every time the
 * repository moves, and each package is 250 MB of archive. Nothing used to
 * remove the previous one, so the folder grew without limit and the operator
 * had to press "Clean up old releases" and hope. This file makes the rule
 * explicit, runs it whenever a release is published (pull or upload), and —
 * this is the part that matters — says so when it could not remove something.
 *
 * The shape of the rule:
 *
 *   keep       How many of the newest releases to keep. 1 = only the current
 *              one. Never less than 1: the current package has to exist for a
 *              licence to download it.
 *   keep_days  Optional age floor. A release younger than this is not removed
 *              even when it falls outside `keep`, so an operator who wants
 *              "the last 5, and nothing I published this week" gets both.
 *
 * Two protections that must not be traded away:
 *
 *   - The newest release per project is never deleted. `keep` is clamped to a
 *     minimum of 1 for exactly that reason.
 *   - A file that cannot be deleted (wrong owner, immutable flag, a folder in
 *     place of a file) is reported as a failure, with the path and the reason.
 *     It is not silently counted as removed, and the release row is not deleted
 *     while its package is still on disk — otherwise the panel says the release
 *     is gone while 250 MB stays behind, which is how the folder fills up.
 *
 * The 9.7.x incumbents (`lm_cleanup_old_updates()`, and the older copy of that
 * logic in admin/ajax_delete_update.php) stay for compatibility, but they are
 * not what a publish calls: they delete the row even when the unlink failed.
 *
 * @package LicenseManager
 */

require_once __DIR__ . '/lm_config.php';

if (!function_exists('lm_retention_settings')) {
    /**
     * The retention rule, with defaults filled in and values clamped.
     *
     * @return array{keep:int,keep_days:int,auto:bool}
     */
    function lm_retention_settings()
    {
        $settings = lm_settings();
        $defaults = function_exists('lm_setting_defaults') ? lm_setting_defaults() : array();

        $keep = array_key_exists('release_keep', $settings) ? (int) $settings['release_keep'] : (int) ($defaults['release_keep'] ?? 1);
        $days = array_key_exists('release_keep_days', $settings) ? (int) $settings['release_keep_days'] : (int) ($defaults['release_keep_days'] ?? 0);

        $auto = array_key_exists('release_auto_prune', $settings)
            ? (bool) $settings['release_auto_prune']
            : (bool) ($defaults['release_auto_prune'] ?? true);

        return array(
            // 1..50: one is "only the current release", and a cap stops a typo
            // ("500") from turning retention into a no-op.
            'keep' => max(1, min(50, $keep)),
            // 0 = no age floor; max 3650 days.
            'keep_days' => max(0, min(3650, $days)),
            'auto' => $auto,
        );
    }
}

if (!function_exists('lm_retention_plan')) {
    /**
     * Which releases of one project should go, and why.
     *
     * Pure: it reads rows and returns a decision, so the rule can be tested
     * without a database or a filesystem.
     *
     * @param array $rows    Rows with id, version_number, release_date (any order).
     * @param array $rule    Optional rule override (same shape as lm_retention_settings()).
     * @param int   $now     Timestamp "now", for tests.
     * @return array{keep:array,drop:array}
     */
    function lm_retention_plan(array $rows, array $rule = array(), $now = 0)
    {
        $rule = $rule ? $rule + lm_retention_settings() : lm_retention_settings();
        $keep = max(1, (int) $rule['keep']);
        $days = max(0, (int) $rule['keep_days']);
        $now = $now > 0 ? (int) $now : time();

        // Newest first: version number decides, release date breaks a tie (a
        // re-publish keeps the id and updates the date).
        usort($rows, function ($a, $b) {
            $version = function_exists('lm_version_compare') ? lm_version_compare($a['version_number'], $b['version_number']) : 0;
            if ($version !== 0) {
                return $version > 0 ? -1 : 1;
            }
            $aDate = strtotime((string) ($a['release_date'] ?? '')) ?: 0;
            $bDate = strtotime((string) ($b['release_date'] ?? '')) ?: 0;
            if ($aDate !== $bDate) {
                return $aDate > $bDate ? -1 : 1;
            }
            return (int) $b['id'] <=> (int) $a['id'];
        });

        $result = array('keep' => array(), 'drop' => array());
        $rank = 0;

        foreach ($rows as $row) {
            $rank++;

            // Position alone decides the keep list; the newest is always kept.
            if ($rank <= $keep) {
                $result['keep'][] = $row;
                continue;
            }

            // Beyond `keep`: the age floor can still save it.
            if ($days > 0) {
                $published = strtotime((string) ($row['release_date'] ?? ''));
                if ($published !== false && $published > 0 && ($now - $published) < ($days * 86400)) {
                    $result['keep'][] = $row;
                    continue;
                }
            }

            $result['drop'][] = $row;
        }

        return $result;
    }
}

if (!function_exists('lm_retention_prune')) {
    /**
     * Apply the rule to one project, deleting packages and rows.
     *
     * Failure is loud on purpose. Every release that was meant to go is either
     * in `deleted` (row gone, file gone) or in `failed` (with the reason), and
     * `ok` is false when anything landed in the latter. A caller that ignores
     * the result still leaves a log line that names the file.
     *
     * @param PDO      $pdo
     * @param int      $tierId
     * @param array    $rule        Optional rule override.
     * @param callable $log         Optional logger, for tests.
     * @return array{ok:bool,kept:int,deleted:int,files:int,removed:array,failed:array,errors:array}
     */
    function lm_retention_prune($pdo, $tierId, array $rule = array(), $log = null)
    {
        $rule = $rule ? $rule + lm_retention_settings() : lm_retention_settings();
        $out = array(
            'ok' => true,
            'kept' => 0,
            'deleted' => 0,
            'files' => 0,
            'removed' => array(),
            'failed' => array(),
            'errors' => array(),
        );

        $writer = $log;
        if (!is_callable($writer)) {
            $writer = function ($message) {
                if (function_exists('lm_log')) {
                    lm_log('updates', $message);
                }
            };
        }

        try {
            $stmt = $pdo->prepare(
                "SELECT id, version_number, release_date
                   FROM script_updates
                  WHERE tier_id = ?
                  ORDER BY id ASC"
            );
            $stmt->execute(array((int) $tierId));
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            $out['ok'] = false;
            $out['errors'][] = 'Could not read the release list: ' . $e->getMessage();
            return $out;
        }

        if (count($rows) <= 1) {
            // Nothing to prune, and nothing to say: a single release is the
            // normal state of a project that has been pulled once.
            $out['kept'] = count($rows);
            return $out;
        }

        $plan = lm_retention_plan($rows, $rule);
        $out['kept'] = count($plan['keep']);

        foreach ($plan['drop'] as $row) {
            $delete = lm_delete_release($pdo, (int) $row['id']);

            if (!empty($delete['ok'])) {
                $out['deleted']++;
                $out['files'] += count($delete['removed']);
                foreach ($delete['removed'] as $path) {
                    $out['removed'][] = $path;
                }
                continue;
            }

            // Not ok: name the file, name the reason, and leave the row so a
            // retry has something to act on.
            $out['ok'] = false;
            $reason = (string) ($delete['error'] ?? 'unknown error');
            $out['failed'][] = array(
                'id' => (int) $row['id'],
                'version' => (string) $row['version_number'],
                'error' => $reason,
                'paths' => isset($delete['stuck']) ? $delete['stuck'] : array(),
            );
            $out['errors'][] = 'v' . $row['version_number'] . ': ' . $reason;
            $writer('Retention could not delete v' . $row['version_number']
                . ' (id ' . (int) $row['id'] . '): ' . $reason);
        }

        $writer('Retention: kept ' . $out['kept'] . ', deleted ' . $out['deleted']
            . ' release(s), ' . $out['files'] . ' path(s)'
            . ($out['failed'] ? ', FAILED ' . count($out['failed']) : ''));

        return $out;
    }
}

if (!function_exists('lm_delete_release')) {
    /**
     * Delete one release — package, staging folders, tokens, rows — but only
     * drop the row once every file it owns is really gone.
     *
     * This is the difference from lm_delete_update(): when an unlink fails the
     * row survives and the failure is reported, instead of leaving a package on
     * disk that nothing in the panel points at any more.
     *
     * @param PDO $pdo
     * @param int $updateId
     * @return array{ok:bool,error:string,version:string,removed:array,stuck:array,missing:array}
     */
    function lm_delete_release($pdo, $updateId)
    {
        $report = array(
            'ok' => false,
            'error' => '',
            'version' => '',
            'removed' => array(),
            'stuck' => array(),
            'missing' => array(),
        );

        try {
            $stmt = $pdo->prepare(
                "SELECT u.*, t.tier_code FROM script_updates u
                  LEFT JOIN script_tiers t ON u.tier_id = t.id
                  WHERE u.id = ?"
            );
            $stmt->execute(array((int) $updateId));
            $update = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            $report['error'] = 'Could not read the release: ' . $e->getMessage();
            return $report;
        }

        if (!$update) {
            $report['error'] = 'Release record not found.';
            return $report;
        }

        $report['version'] = (string) $update['version_number'];
        $tierCode = $update['tier_code'] ?? null;

        $resolved = lm_resolve_update_path($update['zip_path'], $tierCode);
        $zipPath = $resolved['path'];

        // Defence in depth: the path comes from the database and this deletes
        // it, so refuse anything outside the update store even if the row was
        // edited by hand.
        if ($zipPath !== null && !lm_path_is_inside($zipPath, array(lm_updates_root(), lm_storage_dir()))) {
            $report['error'] = 'The package path is outside the update store; nothing was deleted.';
            $report['stuck'][] = $zipPath;
            return $report;
        }

        if ($zipPath !== null) {
            $error = '';
            if (!lm_unlink_checked($zipPath, $error)) {
                // The row stays: the release is still on disk, and a retry must
                // be able to find it again.
                $report['error'] = 'Could not delete ' . $zipPath . ' — ' . $error;
                $report['stuck'][] = $zipPath;
                return $report;
            }
            $report['removed'][] = $zipPath;
            $report['missing'] = array_values(array_diff($resolved['candidates'], array($zipPath)));
        } else {
            // Never written, or already gone: this is not a failure.
            $report['missing'] = $resolved['candidates'];
        }

        // Extracted and staging folders for this release. Exact names only.
        $searchDirs = array();
        if ($zipPath !== null) {
            $searchDirs[] = dirname($zipPath);
        }
        if ($tierCode) {
            $searchDirs[] = lm_tier_storage_dir($tierCode, false);
        }

        $versionSlug = preg_replace('/[^A-Za-z0-9._-]/', '_', (string) $update['version_number']);
        $needles = array(
            'update_' . $versionSlug . '.zip',
            'update_' . $versionSlug,
            'update_' . (int) $update['id'],
            'extracted_' . (int) $update['id'],
            'build_' . (int) $update['id'],
        );

        foreach (array_unique($searchDirs) as $dir) {
            if (!is_dir($dir)) {
                continue;
            }
            $items = @scandir($dir);
            if (!is_array($items)) {
                continue;
            }
            foreach ($items as $item) {
                if ($item === '.' || $item === '..' || $item === 'index.php' || !in_array($item, $needles, true)) {
                    continue;
                }
                $full = $dir . DIRECTORY_SEPARATOR . $item;
                if (!lm_path_is_inside($full, array($dir))) {
                    continue;
                }
                if (is_dir($full)) {
                    if (lm_remove_tree($full)) {
                        $report['removed'][] = $full;
                    } else {
                        $report['stuck'][] = $full;
                    }
                } elseif (is_file($full)) {
                    $error = '';
                    if (lm_unlink_checked($full, $error)) {
                        $report['removed'][] = $full;
                    } else {
                        $report['stuck'][] = $full;
                    }
                }
            }
        }

        // Staging folder used by GitHub pulls.
        $work = lm_storage_dir() . DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . 'update_' . (int) $update['id'];
        if (is_dir($work)) {
            if (lm_remove_tree($work)) {
                $report['removed'][] = $work;
            } else {
                $report['stuck'][] = $work;
            }
        }

        if ($report['stuck']) {
            $report['error'] = 'Left behind (cannot delete): ' . implode(', ', $report['stuck']);
            return $report;
        }

        try {
            $pdo->prepare("DELETE FROM update_notifications WHERE update_id = ?")->execute(array((int) $updateId));
            $pdo->prepare("DELETE FROM download_tokens WHERE update_id = ?")->execute(array((int) $updateId));
            $pdo->prepare("DELETE FROM script_updates WHERE id = ?")->execute(array((int) $updateId));
        } catch (PDOException $e) {
            $report['error'] = 'Files were removed but the row could not be deleted: ' . $e->getMessage();
            return $report;
        }

        $report['ok'] = true;
        if (function_exists('lm_log')) {
            lm_log('updates', 'Retention deleted v' . $report['version'] . ' (id ' . (int) $updateId . ')');
        }

        return $report;
    }
}

if (!function_exists('lm_unlink_checked')) {
    /**
     * unlink() that explains itself.
     *
     * @param string $path
     * @param string $error Set to a human-readable reason on failure.
     * @return bool
     */
    function lm_unlink_checked($path, &$error = null)
    {
        $error = '';

        if (!file_exists($path) && !is_link($path)) {
            return true; // already gone: the goal of the call
        }

        if (is_dir($path) && !is_link($path)) {
            $error = 'it is a folder, not a file';
            return false;
        }

        $last = '';
        $unlinked = @unlink($path);
        if (!$unlinked) {
            $last = (string) (error_get_last()['message'] ?? '');
        }

        clearstatcache(true, $path);

        if (!$unlinked && (file_exists($path) || is_link($path))) {
            $owner = @fileowner($path);
            $reason = 'permission denied';
            if ($last !== '' && stripos($last, 'permission') === false) {
                $reason = $last;
            } elseif ($owner !== false && function_exists('posix_geteuid') && $owner !== posix_geteuid()) {
                $reason = 'the file is owned by uid ' . (int) $owner . ', but PHP runs as uid ' . (int) posix_geteuid();
            }
            $error = $reason;
            return false;
        }

        return true;
    }
}

if (!function_exists('lm_retention_prune_all')) {
    /**
     * Apply the rule to every project — the cron sweep.
     *
     * Catches projects the publish path missed: a failed publish, a project
     * deleted mid-pull, releases that piled up before this rule existed.
     *
     * @param PDO $pdo
     * @return array{ok:bool,projects:int,deleted:int,files:int,failed:int,errors:array}
     */
    function lm_retention_prune_all(PDO $pdo)
    {
        $out = array('ok' => true, 'projects' => 0, 'deleted' => 0, 'files' => 0, 'failed' => 0, 'errors' => array());

        try {
            $tiers = $pdo->query("SELECT id FROM script_tiers")->fetchAll(PDO::FETCH_COLUMN);
        } catch (PDOException $e) {
            $out['ok'] = false;
            $out['errors'][] = $e->getMessage();
            return $out;
        }

        foreach ($tiers as $tierId) {
            $prune = lm_retention_prune($pdo, (int) $tierId);
            $out['projects']++;
            $out['deleted'] += (int) $prune['deleted'];
            $out['files'] += (int) $prune['files'];
            $out['failed'] += count($prune['failed']);
            if (!$prune['ok']) {
                $out['ok'] = false;
                foreach ($prune['errors'] as $error) {
                    $out['errors'][] = $error;
                }
            }
        }

        return $out;
    }
}

if (!function_exists('lm_retention_summary_line')) {
    /**
     * One sentence for the toast, the pull history and the admin e-mail.
     *
     * The point is that "nothing was removed" is never indistinguishable from
     * "it was removed and you were not told": every branch says something.
     *
     * @param array $result A result carrying a `retention` key (publish/pull).
     * @return string
     */
    function lm_retention_summary_line(array $result)
    {
        if (!isset($result['retention']) || !is_array($result['retention'])) {
            return 'old release: not checked';
        }

        $retention = $result['retention'];
        $deleted = isset($retention['deleted']) ? (int) $retention['deleted'] : 0;
        $failed = isset($retention['failed']) ? count((array) $retention['failed']) : 0;
        $files = isset($retention['files']) ? (int) $retention['files'] : 0;

        if ($failed > 0) {
            $first = '';
            if (!empty($retention['failed'][0]['error'])) {
                $first = ': ' . (string) $retention['failed'][0]['error'];
            }
            return $failed . ' old release(s) could NOT be deleted' . $first;
        }

        if ($deleted > 0) {
            return 'removed ' . $deleted . ' old release(s)'
                . ($files > 0 ? ', ' . $files . ' path(s)' : '');
        }

        return 'no old release to remove';
    }
}
