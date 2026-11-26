<?php
/**
 * Shared system-update backup pruning & exclusion helpers.
 * Ensures pre-update snapshots never accumulate unboundedly on disk.
 */

if (!function_exists('bc_backup_excluded_prefixes')) {
    function bc_backup_excluded_prefixes(): array {
        return [
            'backups/',
            'tmp_update/',
            'dg7-android/',
            'dg7-ios/',
            'dg6-android/',
            'dg6-ios/',
            'jikabiz-android/',
            'jikabiz-ios/',
            'mzeevtu/',
            'mzeevtu-android/',
            'mzeevtu-ios/',
            'payhub-android/',
            'payhub-ios/',
            'uploaded-image/',
            'downloads/',
            'logs/',
            'scratch/',
            'tests/',
            'secure_updates/',
            'cache/',
            'func/cache/',
            '.git/',
            '.github/',
        ];
    }
}

if (!function_exists('bc_prune_old_backups')) {
    /**
     * Prune old backup archives in $backup_dir so at most $max_keep_sets
     * recent backup sets (and none older than $max_days days, unless listed
     * in $protected_files) remain on disk.
     *
     * @param string   $backup_dir      Absolute path to /backups directory.
     * @param int      $max_keep_sets   Maximum backup pairs/sets to retain (default 2).
     * @param int      $max_days        Maximum age in days for non-protected sets (default 14).
     * @param string[] $protected_files Basenames (e.g. current run's zip/sql) to never delete.
     * @return int Number of deleted backup files.
     */
    function bc_prune_old_backups(string $backup_dir, int $max_keep_sets = 2, int $max_days = 14, array $protected_files = []): int {
        if (!is_dir($backup_dir)) {
            return 0;
        }

        $max_keep_sets = max(0, $max_keep_sets);
        $max_days = max(1, $max_days);
        $cutoff_time = time() - ($max_days * 86400);

        $protected_map = [];
        foreach ($protected_files as $pf) {
            $pf = basename((string)$pf);
            if ($pf !== '') {
                $protected_map[$pf] = true;
            }
        }

        $groups = [];
        $iterator = new DirectoryIterator($backup_dir);
        foreach ($iterator as $fileinfo) {
            if ($fileinfo->isDot() || !$fileinfo->isFile()) {
                continue;
            }
            $basename = $fileinfo->getFilename();
            if ($basename === '' || $basename[0] === '.') {
                continue;
            }

            $lower = strtolower($basename);
            $ext = strtolower($fileinfo->getExtension());
            $is_sql_gz = (substr($lower, -7) === '.sql.gz');
            if (!in_array($ext, ['sql', 'zip'], true) && !$is_sql_gz) {
                continue;
            }

            if (preg_match('/^(?:files_backup_|db_backup_|backup_)(.+)\.(?:zip|sql(?:\.gz)?)$/i', $basename, $m)) {
                $group_key = 'pair:' . strtolower($m[1]);
            } else {
                $group_key = 'single:' . $lower;
            }

            $mtime = (int)$fileinfo->getMTime();
            $real = $fileinfo->getRealPath();
            if ($real === false) {
                $real = rtrim($backup_dir, '/\\') . DIRECTORY_SEPARATOR . $basename;
            }

            if (!isset($groups[$group_key])) {
                $groups[$group_key] = [
                    'key' => $group_key,
                    'mtime' => $mtime,
                    'protected' => false,
                    'files' => [],
                ];
            } elseif ($mtime > $groups[$group_key]['mtime']) {
                $groups[$group_key]['mtime'] = $mtime;
            }

            if (isset($protected_map[$basename])) {
                $groups[$group_key]['protected'] = true;
            }
            $groups[$group_key]['files'][] = $real;
        }

        if (empty($groups)) {
            return 0;
        }

        uasort($groups, static function (array $a, array $b): int {
            if ($a['mtime'] !== $b['mtime']) {
                return ($b['mtime'] <=> $a['mtime']);
            }
            return strcmp($b['key'], $a['key']);
        });

        $kept = 0;
        $deleted_count = 0;

        foreach ($groups as $group) {
            $keep = false;
            if (!empty($group['protected'])) {
                $keep = true;
            } elseif ($kept < $max_keep_sets && $group['mtime'] >= $cutoff_time) {
                $keep = true;
            }

            if ($keep) {
                $kept++;
                continue;
            }

            foreach ($group['files'] as $path) {
                if (is_file($path) && @unlink($path)) {
                    $deleted_count++;
                }
            }
        }

        return $deleted_count;
    }
}
