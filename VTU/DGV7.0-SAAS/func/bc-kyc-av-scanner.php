<?php
/**
 * bc-kyc-av-scanner.php — DGV7.0 Pre-Upload Antivirus, Trojan & Blank-File Scanner
 *
 * Defense-in-depth upload scanner executed BEFORE any uploaded KYC/KYB file is
 * written into permanent storage (/uploads/kyc/ or /uploads/kyb/).
 *
 * Two-stage architecture:
 *   Stage 1 (Always-On Standard Antivirus & Integrity Scanner):
 *     - Runs even when AI is disabled by bc-admin or bc-spadmin.
 *     - Blocks 0-byte / undersized / blank / solid-color images & blank PDFs.
 *     - Verifies magic bytes + finfo MIME match declared extension (stops spoofed files).
 *     - Scans binary content for EICAR, PE/ELF/Mach-O executables, PHP/JS/VBS/PS trojans,
 *       polyglot payloads, and weaponized PDF active-content (/JavaScript, /Launch, /OpenAction).
 *     - Invokes ClamAV (clamdscan / clamscan) if installed on the host.
 *
 *   Stage 2 (AI-Powered Pre-Upload Security & Blank-Document Inspection):
 *     - Runs automatically when Global AI is enabled in bc-spadmin AND KYC/AI is enabled
 *       by bc-admin (with a configured Vision AI provider).
 *     - Inspects image/PDF uploads before storage for blank pages, erased/empty templates,
 *       tampered overlays, or suspicious non-document content.
 *     - Falls back seamlessly to Stage 1 if AI is disabled or temporarily unreachable.
 *
 * Both editions (SAAS / NON-SAAS) ship an identical copy of this file.
 */

if (!function_exists('bc_kyc_is_ai_enabled')) {
    /**
     * Determine whether AI compliance/security scanning is enabled for a vendor.
     * - SAAS: Respects BOTH bc-spadmin global AI toggle + bc-admin toggles + SAAS token balance + Vision key.
     * - NON-SAAS: Direct API connection (respects bc-admin toggle + Vision key, no SAAS token wall).
     *
     * @param mysqli|null $connection_server
     * @param int         $vendor_id
     * @return array{enabled:bool,global_enabled:bool,vendor_enabled:bool,vision_ready:bool,reason:string}
     */
    function bc_kyc_is_ai_enabled($connection_server, int $vendor_id): array
    {
        if (!function_exists('bc_ai_is_saas') && is_file(__DIR__ . '/bc-edition.php')) {
            include_once(__DIR__ . '/bc-edition.php');
        }
        $is_saas = function_exists('bc_ai_is_saas')
            ? bc_ai_is_saas()
            : (function_exists('bc_is_saas_edition') ? (bool)bc_is_saas_edition() : true);

        $global_enabled = true;
        if ($is_saas && function_exists('getSuperAdminOption')) {
            $global_val = getSuperAdminOption('ai_global_enabled', '0');
            $global_enabled = ((string)$global_val === '1');
        }
        if (!$global_enabled) {
            return [
                'enabled'        => false,
                'global_enabled' => false,
                'vendor_enabled' => false,
                'vision_ready'   => false,
                'reason'         => 'Global AI is disabled by Super Admin (bc-spadmin). Using standard antivirus scanner.',
            ];
        }

        $vendor_enabled = !$is_saas;
        $token_balance = 0;
        if ($connection_server && $vendor_id > 0) {
            $q = @mysqli_query($connection_server, "SELECT * FROM sas_vendors WHERE id='" . (int)$vendor_id . "' LIMIT 1");
            $row = $q ? mysqli_fetch_assoc($q) : null;
            if ($row) {
                $token_balance = (int)($row['ai_token_balance'] ?? 0);
                if (array_key_exists('kyc_ai_enabled', $row)) {
                    $vendor_enabled = ((int)$row['kyc_ai_enabled'] === 1);
                } elseif (array_key_exists('ai_status', $row)) {
                    $vendor_enabled = ((int)$row['ai_status'] === 1);
                }
            }
        }

        if (!$vendor_enabled) {
            return [
                'enabled'        => false,
                'global_enabled' => true,
                'vendor_enabled' => false,
                'vision_ready'   => false,
                'reason'         => 'KYC/KYB AI is disabled by Admin (bc-admin). Using standard antivirus scanner.',
            ];
        }

        if ($is_saas && function_exists('bc_ai_get_action_cost') && $connection_server && $vendor_id > 0) {
            $av_cost = bc_ai_get_action_cost('av_scan');
            if ($av_cost > 0 && $token_balance < $av_cost) {
                return [
                    'enabled'        => false,
                    'global_enabled' => true,
                    'vendor_enabled' => true,
                    'vision_ready'   => false,
                    'reason'         => 'Insufficient AI tokens for AI AV scan (' . $token_balance . ' available, ' . $av_cost . ' required). Using standard antivirus scanner.',
                ];
            }
        }

        $vision_ready = false;
        if (function_exists('ai_engine')) {
            $ai = ai_engine();
            if ($ai && method_exists($ai, 'isVisionConfigured')) {
                $vision_ready = $ai->isVisionConfigured();
            } else {
                $vision_ready = ($ai && method_exists($ai, 'getApiKey') && $ai->getApiKey() !== '');
            }
        }

        if (!$vision_ready) {
            return [
                'enabled'        => false,
                'global_enabled' => true,
                'vendor_enabled' => true,
                'vision_ready'   => false,
                'reason'         => 'Vision AI provider key is not configured. Using standard antivirus scanner.',
            ];
        }

        return [
            'enabled'        => true,
            'global_enabled' => true,
            'vendor_enabled' => true,
            'vision_ready'   => true,
            'reason'         => 'AI security & compliance scanning active.',
        ];
    }
}

if (!function_exists('bc_kyc_av_ensure_schema')) {
    function bc_kyc_av_ensure_schema($connection_server): bool
    {
        static $done = false;
        if ($done || !($connection_server instanceof mysqli)) return $done;
        $ok = @mysqli_query($connection_server, "CREATE TABLE IF NOT EXISTS sas_kyc_av_logs (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            vendor_id INT UNSIGNED NOT NULL DEFAULT 0,
            user_id INT UNSIGNED NOT NULL DEFAULT 0,
            username VARCHAR(191) NOT NULL DEFAULT '',
            subject_type VARCHAR(32) NOT NULL DEFAULT 'kyc',
            doc_key VARCHAR(64) NOT NULL DEFAULT '',
            filename VARCHAR(255) NOT NULL DEFAULT '',
            file_size INT UNSIGNED NOT NULL DEFAULT 0,
            mime_type VARCHAR(128) NOT NULL DEFAULT '',
            scan_mode VARCHAR(48) NOT NULL DEFAULT 'standard_antivirus',
            verdict VARCHAR(24) NOT NULL DEFAULT 'clean',
            threat_code VARCHAR(64) NOT NULL DEFAULT '',
            reason VARCHAR(500) NOT NULL DEFAULT '',
            ai_checked TINYINT(1) NOT NULL DEFAULT 0,
            tokens_charged INT NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_av_vendor_date (vendor_id, created_at),
            INDEX idx_av_verdict (verdict, created_at)
        )");
        $done = (bool)$ok;
        return $done;
    }
}

if (!function_exists('bc_kyc_av_log_scan')) {
    function bc_kyc_av_log_scan($connection_server, array $entry): void
    {
        if (!($connection_server instanceof mysqli)) return;
        bc_kyc_av_ensure_schema($connection_server);

        $vid = (int)($entry['vendor_id'] ?? 0);
        $uid = (int)($entry['user_id'] ?? 0);
        $uname = trim((string)($entry['username'] ?? ''));
        if ($uname === '' && $uid > 0) {
            $uq = @mysqli_query($connection_server, "SELECT username FROM sas_users WHERE id='$uid' LIMIT 1");
            if ($uq && ($ur = mysqli_fetch_assoc($uq))) $uname = (string)$ur['username'];
        }
        $esc_user   = mysqli_real_escape_string($connection_server, substr($uname, 0, 190));
        $esc_subj   = mysqli_real_escape_string($connection_server, substr((string)($entry['subject_type'] ?? 'kyc'), 0, 32));
        $esc_doc    = mysqli_real_escape_string($connection_server, substr((string)($entry['doc_key'] ?? ''), 0, 64));
        $esc_file   = mysqli_real_escape_string($connection_server, substr(basename((string)($entry['filename'] ?? '')), 0, 250));
        $file_size  = max(0, (int)($entry['file_size'] ?? 0));
        $esc_mime   = mysqli_real_escape_string($connection_server, substr((string)($entry['mime_type'] ?? ''), 0, 120));
        $esc_mode   = mysqli_real_escape_string($connection_server, substr((string)($entry['scan_mode'] ?? 'standard_antivirus'), 0, 48));
        $esc_verd   = mysqli_real_escape_string($connection_server, substr((string)($entry['verdict'] ?? 'clean'), 0, 24));
        $esc_threat = mysqli_real_escape_string($connection_server, substr((string)($entry['threat_code'] ?? ''), 0, 64));
        $esc_reason = mysqli_real_escape_string($connection_server, substr((string)($entry['reason'] ?? ''), 0, 490));
        $ai_chk     = !empty($entry['ai_checked']) ? 1 : 0;
        $tokens     = max(0, (int)($entry['tokens_charged'] ?? 0));

        @mysqli_query($connection_server,
            "INSERT INTO sas_kyc_av_logs (vendor_id, user_id, username, subject_type, doc_key, filename, file_size, mime_type, scan_mode, verdict, threat_code, reason, ai_checked, tokens_charged)
             VALUES ('$vid', '$uid', '$esc_user', '$esc_subj', '$esc_doc', '$esc_file', '$file_size', '$esc_mime', '$esc_mode', '$esc_verd', '$esc_threat', '$esc_reason', '$ai_chk', '$tokens')"
        );
    }
}

if (!function_exists('bc_kyc_av_check_magic_bytes')) {
    /**
     * Verify file magic bytes & MIME match the expected extension.
     */
    function bc_kyc_av_check_magic_bytes(string $filepath, string $ext): array
    {
        $ext = strtolower(trim($ext));
        $fh = @fopen($filepath, 'rb');
        if (!$fh) {
            return ['ok' => false, 'reason' => 'Cannot read uploaded file for security inspection.'];
        }
        $head = (string)@fread($fh, 2048);
        @fclose($fh);

        if ($head === '') {
            return ['ok' => false, 'reason' => 'File is empty (0 bytes). Blank files are not allowed.'];
        }

        $finfo_mime = '';
        if (function_exists('finfo_open')) {
            $fi = @finfo_open(FILEINFO_MIME_TYPE);
            if ($fi) {
                $finfo_mime = strtolower((string)@finfo_file($fi, $filepath));
                @finfo_close($fi);
            }
        }

        switch ($ext) {
            case 'jpg':
            case 'jpeg':
                if (substr($head, 0, 3) !== "\xFF\xD8\xFF") {
                    return ['ok' => false, 'reason' => 'File header does not match a genuine JPEG image.'];
                }
                if ($finfo_mime !== '' && strpos($finfo_mime, 'image/') !== 0) {
                    return ['ok' => false, 'reason' => 'File content type (' . $finfo_mime . ') does not match JPEG.'];
                }
                return ['ok' => true, 'mime' => 'image/jpeg'];

            case 'png':
                if (substr($head, 0, 8) !== "\x89PNG\r\n\x1a\n") {
                    return ['ok' => false, 'reason' => 'File header does not match a genuine PNG image.'];
                }
                if ($finfo_mime !== '' && strpos($finfo_mime, 'image/') !== 0) {
                    return ['ok' => false, 'reason' => 'File content type (' . $finfo_mime . ') does not match PNG.'];
                }
                return ['ok' => true, 'mime' => 'image/png'];

            case 'webp':
                if (strlen($head) < 12 || substr($head, 0, 4) !== 'RIFF' || substr($head, 8, 4) !== 'WEBP') {
                    return ['ok' => false, 'reason' => 'File header does not match a genuine WebP image.'];
                }
                return ['ok' => true, 'mime' => 'image/webp'];

            case 'gif':
                if (substr($head, 0, 6) !== 'GIF87a' && substr($head, 0, 6) !== 'GIF89a') {
                    return ['ok' => false, 'reason' => 'File header does not match a genuine GIF image.'];
                }
                return ['ok' => true, 'mime' => 'image/gif'];

            case 'pdf':
                if (strpos($head, '%PDF-') === false) {
                    return ['ok' => false, 'reason' => 'File header does not match a genuine PDF document.'];
                }
                if ($finfo_mime !== '' && $finfo_mime !== 'application/pdf' && $finfo_mime !== 'application/octet-stream') {
                    return ['ok' => false, 'reason' => 'File content type (' . $finfo_mime . ') does not match PDF.'];
                }
                return ['ok' => true, 'mime' => 'application/pdf'];

            case 'mp4':
            case 'mov':
                $ftyp_pos = strpos(substr($head, 0, 64), 'ftyp');
                if ($ftyp_pos === false || $ftyp_pos < 2) {
                    return ['ok' => false, 'reason' => 'File header does not match a valid MP4/MOV video.'];
                }
                return ['ok' => true, 'mime' => $ext === 'mov' ? 'video/quicktime' : 'video/mp4'];

            case 'webm':
            case 'mkv':
                if (substr($head, 0, 4) !== "\x1A\x45\xDF\xA3") {
                    return ['ok' => false, 'reason' => 'File header does not match a valid WebM/Matroska video.'];
                }
                return ['ok' => true, 'mime' => 'video/webm'];
        }

        return ['ok' => false, 'reason' => 'Unsupported file extension (.' . $ext . ').'];
    }
}

if (!function_exists('bc_kyc_av_scan_signatures')) {
    /**
     * Scan raw bytes for malware, executables, trojans, webshells, and dangerous PDF active objects.
     */
    function bc_kyc_av_scan_signatures(string $filepath, string $ext): array
    {
        $size = @filesize($filepath);
        if ($size === false || $size <= 0) {
            return ['clean' => false, 'threat' => 'empty_file', 'reason' => 'Blank (0-byte) file rejected.'];
        }

        $max_scan = (int)min($size, 8 * 1024 * 1024);
        $fh = @fopen($filepath, 'rb');
        if (!$fh) {
            return ['clean' => false, 'threat' => 'unreadable', 'reason' => 'Uploaded file could not be read for virus scanning.'];
        }
        $bytes = (string)@fread($fh, $max_scan);
        @fclose($fh);

        // 1. Standard EICAR antivirus test signature
        if (strpos($bytes, 'X5O!P%@AP[4\\PZX54(P^)7CC)7}$EICAR-STANDARD-ANTIVIRUS-TEST-FILE!$H+H*') !== false) {
            return ['clean' => false, 'threat' => 'eicar_malware_signature', 'reason' => 'Malware signature detected (EICAR test virus). Upload blocked.'];
        }

        // 2. Binary executable / trojan headers (at start or polyglot embedded PE/ELF)
        if (substr($bytes, 0, 2) === 'MZ' || substr($bytes, 0, 4) === "\x7FELF"
            || substr($bytes, 0, 4) === "\xFE\xED\xFA\xCE" || substr($bytes, 0, 4) === "\xFE\xED\xFA\xCF"
            || substr($bytes, 0, 4) === "\xCF\xFA\xED\xFE" || substr($bytes, 0, 4) === "\xCA\xFE\xBA\xBE"
        ) {
            return ['clean' => false, 'threat' => 'executable_trojan', 'reason' => 'Executable/trojan binary file detected. Upload blocked.'];
        }
        // Embedded PE header inside disguised document
        $mz_pos = strpos($bytes, "MZ");
        if ($mz_pos !== false && strpos(substr($bytes, $mz_pos, 512), "PE\x00\x00") !== false) {
            return ['clean' => false, 'threat' => 'embedded_pe_trojan', 'reason' => 'Embedded Windows executable (trojan/polyglot) detected inside file. Upload blocked.'];
        }
        if (strpos($bytes, "\x7FELF") !== false) {
            return ['clean' => false, 'threat' => 'embedded_elf_trojan', 'reason' => 'Embedded Linux executable detected inside file. Upload blocked.'];
        }

        // 3. Embedded web-shell / script / macro trojan payloads (skip binary video streams for generic substrings except explicit PHP/script tags)
        $is_video = in_array($ext, ['mp4', 'webm', 'mov', 'mkv'], true);
        $script_patterns = [
            'php_webshell'      => '/<\?(?:php|=)\b/i',
            'html_script_tag'   => '/<script\b[^>]*>/i',
            'js_uri_payload'    => '/(?:javascript|vbscript)\s*:/i',
            'php_exec_payload'  => '/\b(?:eval|assert|shell_exec|passthru|proc_open|popen)\s*\(/i',
            'wsh_powershell'    => '/(?:WScript\.Shell|CreateObject\s*\(|powershell(?:\.exe)?\s+-(?:enc|e|nop|w)|cmd\.exe\s*\/c)/i',
        ];
        foreach ($script_patterns as $threat_name => $rx) {
            if ($is_video && in_array($threat_name, ['php_exec_payload'], true)) {
                continue;
            }
            if (preg_match($rx, $bytes)) {
                return [
                    'clean'  => false,
                    'threat' => $threat_name,
                    'reason' => 'Suspicious script/trojan payload detected inside the uploaded file. Upload blocked.',
                ];
            }
        }

        // 4. PDF active-content / exploit object check + blank PDF check
        if ($ext === 'pdf') {
            $dangerous_pdf = [
                '/\/JavaScript\b/i'     => 'Embedded PDF JavaScript',
                '/\/JS\b/i'             => 'Embedded PDF JS action',
                '/\/Launch\b/i'         => 'PDF external program launch action',
                '/\/OpenAction\b/i'     => 'PDF automatic OpenAction payload',
                '/\/AA\b/'              => 'PDF Additional Action trigger',
                '/\/EmbeddedFile\b/i'   => 'PDF embedded executable/file payload',
                '/\/RichMedia\b/i'      => 'PDF Flash/RichMedia payload',
                '/\/SubmitForm\b/i'     => 'PDF hidden form submission payload',
                '/\/ImportData\b/i'     => 'PDF external data import payload',
            ];
            foreach ($dangerous_pdf as $rx => $label) {
                if (preg_match($rx, $bytes)) {
                    return [
                        'clean'  => false,
                        'threat' => 'malicious_pdf_action',
                        'reason' => "Security threat blocked: {$label} is not permitted in KYC/KYB PDFs.",
                    ];
                }
            }

            // Detect blank / empty PDFs
            $has_pages = preg_match('/\/Type\s*\/Page\b/', $bytes);
            $has_stream = (strpos($bytes, 'stream') !== false && strpos($bytes, 'endstream') !== false);
            $has_xobject = (stripos($bytes, '/XObject') !== false || stripos($bytes, '/Image') !== false || stripos($bytes, '/Font') !== false);
            if ($size < 600 || !$has_pages || (!$has_stream && !$has_xobject)) {
                return [
                    'clean'    => false,
                    'is_blank' => true,
                    'threat'   => 'blank_pdf',
                    'reason'   => 'The uploaded PDF appears to be blank or incomplete. Please upload a clear, readable document.',
                ];
            }

            // If there are only tiny/empty streams and no embedded images/fonts, inspect stream contents
            if (!$has_xobject && preg_match_all('/stream\r?\n(.*?)\r?\n?endstream/s', $bytes, $m)) {
                $non_empty_drawing = false;
                foreach ($m[1] as $raw_stream) {
                    $decoded = function_exists('gzuncompress') ? @gzuncompress(trim($raw_stream)) : false;
                    $content = ($decoded !== false) ? $decoded : $raw_stream;
                    // Look for actual text operators (Tj / TJ) or visible vector paths with non-trivial bytes
                    if (preg_match('/\((?:[^()\\\\]|\\\\.)+\)\s*Tj|\[[^\]]+\]\s*TJ|\bDo\b|\bBI\b/', $content) || strlen(trim($content)) > 120) {
                        $non_empty_drawing = true;
                        break;
                    }
                }
                if (!$non_empty_drawing) {
                    return [
                        'clean'    => false,
                        'is_blank' => true,
                        'threat'   => 'blank_pdf',
                        'reason'   => 'The uploaded PDF contains no readable text or image content (blank page). Upload rejected.',
                    ];
                }
            }
        }

        return ['clean' => true, 'threat' => '', 'reason' => ''];
    }
}

if (!function_exists('bc_kyc_av_check_blank_image')) {
    /**
     * Detect blank, solid-color, or tiny/corrupt images using GD pixel sampling.
     */
    function bc_kyc_av_check_blank_image(string $filepath, int $min_dim = 120): array
    {
        $info = @getimagesize($filepath);
        if (!$info || empty($info[0]) || empty($info[1])) {
            return ['ok' => false, 'is_blank' => true, 'reason' => 'File is not a valid readable image.'];
        }
        $w = (int)$info[0];
        $h = (int)$info[1];
        if ($w < $min_dim || $h < $min_dim) {
            return [
                'ok'       => false,
                'is_blank' => true,
                'reason'   => "Image resolution ({$w}x{$h}) is too small or blank. Minimum {$min_dim}x{$min_dim}px required.",
            ];
        }

        if (!function_exists('imagecreatefromstring')) {
            return ['ok' => true, 'is_blank' => false, 'width' => $w, 'height' => $h];
        }

        $raw = @file_get_contents($filepath);
        $im = $raw !== false ? @imagecreatefromstring($raw) : false;
        if (!$im) {
            return ['ok' => false, 'is_blank' => true, 'reason' => 'Corrupt or unreadable image file rejected.'];
        }

        $samples = 24;
        $lums = [];
        $buckets = [];
        for ($gx = 0; $gx < $samples; $gx++) {
            $px = (int)min($w - 1, max(0, round(($gx + 0.5) * $w / $samples)));
            for ($gy = 0; $gy < $samples; $gy++) {
                $py = (int)min($h - 1, max(0, round(($gy + 0.5) * $h / $samples)));
                $rgb = @imagecolorat($im, $px, $py);
                if ($rgb === false) continue;
                $r = ($rgb >> 16) & 0xFF;
                $g = ($rgb >> 8) & 0xFF;
                $b = $rgb & 0xFF;
                $lums[] = (0.299 * $r) + (0.587 * $g) + (0.114 * $b);
                $bucket = (($r >> 4) << 8) | (($g >> 4) << 4) | ($b >> 4);
                $buckets[$bucket] = true;
            }
        }
        @imagedestroy($im);

        if (count($lums) < 16) {
            return ['ok' => false, 'is_blank' => true, 'reason' => 'Could not verify image content.'];
        }

        $mean = array_sum($lums) / count($lums);
        $var = 0.0;
        $min_l = 255.0;
        $max_l = 0.0;
        foreach ($lums as $l) {
            $var += ($l - $mean) * ($l - $mean);
            if ($l < $min_l) $min_l = $l;
            if ($l > $max_l) $max_l = $l;
        }
        $stddev = sqrt($var / count($lums));
        $range = $max_l - $min_l;
        $distinct_buckets = count($buckets);

        if ($stddev < 4.5 || $range < 14.0 || $distinct_buckets <= 3) {
            return [
                'ok'       => false,
                'is_blank' => true,
                'reason'   => 'Blank, solid-color, or empty image detected. Please upload or capture a clear, legible document/photo.',
                'metrics'  => ['stddev' => round($stddev, 2), 'range' => round($range, 2), 'buckets' => $distinct_buckets],
            ];
        }

        return [
            'ok'       => true,
            'is_blank' => false,
            'width'    => $w,
            'height'   => $h,
            'metrics'  => ['stddev' => round($stddev, 2), 'range' => round($range, 2), 'buckets' => $distinct_buckets],
        ];
    }
}

if (!function_exists('bc_kyc_av_run_clamav')) {
    /**
     * Run ClamAV (clamdscan / clamscan) if installed on the server.
     * Safe no-op when ClamAV binary is not present.
     */
    function bc_kyc_av_run_clamav(string $filepath): array
    {
        if (!function_exists('proc_open')) {
            return ['ran' => false, 'clean' => true];
        }
        $bin = '';
        foreach (['/usr/bin/clamdscan', '/usr/local/bin/clamdscan', '/usr/bin/clamscan', '/usr/local/bin/clamscan'] as $candidate) {
            if (@is_executable($candidate)) {
                $bin = $candidate;
                break;
            }
        }
        if ($bin === '') {
            return ['ran' => false, 'clean' => true];
        }

        $cmd = escapeshellcmd($bin) . ' --no-summary ' . escapeshellarg($filepath);
        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = @proc_open($cmd, $descriptors, $pipes);
        if (!is_resource($proc)) {
            return ['ran' => false, 'clean' => true];
        }
        @fclose($pipes[0]);
        $out = (string)@stream_get_contents($pipes[1]);
        $err = (string)@stream_get_contents($pipes[2]);
        @fclose($pipes[1]);
        @fclose($pipes[2]);
        $code = @proc_close($proc);

        if ($code === 1 || stripos($out, 'FOUND') !== false) {
            return [
                'ran'    => true,
                'clean'  => false,
                'engine' => basename($bin),
                'reason' => 'Antivirus engine detected malware (' . trim($out ?: $err) . '). Upload blocked.',
            ];
        }

        return ['ran' => true, 'clean' => true, 'engine' => basename($bin)];
    }
}

if (!function_exists('bc_kyc_av_ai_inspect')) {
    /**
     * Stage 2: AI-powered pre-upload threat, blank-page & document-validity scan.
     */
    function bc_kyc_av_ai_inspect(string $filepath, string $ext, string $doc_kind): array
    {
        if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif', 'pdf'], true)) {
            return ['ran' => false, 'clean' => true, 'reason' => 'AI pre-scan skipped for video stream (covered by binary + frame check).'];
        }
        if (!function_exists('ai_engine') || !function_exists('bc_kyc_ai_image_to_base64')) {
            return ['ran' => false, 'clean' => true, 'reason' => 'AI engine helper unavailable.'];
        }

        $data_url = bc_kyc_ai_image_to_base64($filepath);
        if (!$data_url) {
            return ['ran' => false, 'clean' => true, 'reason' => 'Could not encode file for AI pre-scan.'];
        }

        $ai = ai_engine();
        $model = $ai->getVisionModel();
        $provider = $ai->getVisionProvider();

        $prompt = "You are a cybersecurity & KYC/KYB document firewall scanner.\n"
            . "A user is attempting to upload a '{$doc_kind}' file (.{$ext}) to the server.\n"
            . "Inspect this file BEFORE it is accepted into server storage and return ONLY valid JSON:\n"
            . "{\n"
            . '  "is_blank_or_empty": true/false, true if the page/image is blank, erased, pure white/black, or contains no readable document/face content,\n'
            . '  "is_corrupt_or_unreadable": true/false, true if completely garbled/corrupted/unreadable noise,\n'
            . '  "is_suspicious_or_malicious": true/false, true if this looks like code/terminal/spam/phishing/malware screenshot or disguised non-KYC payload,\n'
            . '  "threat_level": "safe" or "suspicious" or "malicious",\n'
            . '  "security_score": 0-100,\n'
            . '  "reason": "short 1-sentence security assessment"\n'
            . "}";

        $res = $ai->chatWithVision($model, $prompt, [$data_url], ['temperature' => 0.05, 'num_predict' => 300]);
        if (($res['status'] ?? '') !== 'success') {
            return [
                'ran'      => false,
                'clean'    => true,
                'provider' => $provider,
                'model'    => $model,
                'reason'   => 'AI pre-scan unavailable (' . ($res['message'] ?? 'timeout') . '); standard antivirus enforced.',
            ];
        }

        $parsed = function_exists('bc_kyc_ai_parse_json_response')
            ? bc_kyc_ai_parse_json_response((string)($res['response'] ?? ''))
            : json_decode((string)($res['response'] ?? ''), true);

        if (!is_array($parsed)) {
            return [
                'ran'      => true,
                'clean'    => true,
                'provider' => $provider,
                'model'    => $model,
                'reason'   => 'AI pre-scan inconclusive; standard antivirus passed.',
            ];
        }

        $is_blank = !empty($parsed['is_blank_or_empty']);
        $is_corrupt = !empty($parsed['is_corrupt_or_unreadable']);
        $is_mal = !empty($parsed['is_suspicious_or_malicious']) || in_array(($parsed['threat_level'] ?? ''), ['suspicious', 'malicious'], true);

        if ($is_blank) {
            return [
                'ran'            => true,
                'clean'          => false,
                'is_blank'       => true,
                'threat'         => 'ai_blank_document',
                'provider'       => $provider,
                'model'          => $model,
                'security_score' => (int)($parsed['security_score'] ?? 0),
                'reason'         => 'AI Security Scanner rejected a blank or empty upload: ' . ($parsed['reason'] ?? 'No valid content found.'),
            ];
        }
        if ($is_corrupt || $is_mal) {
            return [
                'ran'            => true,
                'clean'          => false,
                'is_blank'       => false,
                'threat'         => 'ai_' . ($parsed['threat_level'] ?? 'suspicious'),
                'provider'       => $provider,
                'model'          => $model,
                'security_score' => (int)($parsed['security_score'] ?? 10),
                'reason'         => 'AI Security Scanner blocked this file: ' . ($parsed['reason'] ?? 'Suspicious or invalid upload content.'),
            ];
        }

        return [
            'ran'            => true,
            'clean'          => true,
            'is_blank'       => false,
            'provider'       => $provider,
            'model'          => $model,
            'security_score' => max(70, (int)($parsed['security_score'] ?? 95)),
            'reason'         => (string)($parsed['reason'] ?? 'AI security scan passed.'),
        ];
    }
}

if (!function_exists('bc_kyc_av_scan_upload')) {
    /**
     * Master pre-upload antivirus, trojan, and blank-file gate.
     * MUST be called on the PHP temporary upload path BEFORE move_uploaded_file().
     *
     * @param string $tmp_path
     * @param string $original_name
     * @param string $expected_ext
     * @param array  $options ['connection' => mysqli|null, 'vendor_id' => int, 'doc_kind' => string, 'min_dim' => int, 'user_id' => int]
     * @return array{allowed:bool,clean:bool,scan_mode:string,threat_found:bool,is_blank:bool,security_score:int,reason:string,checks:array}
     */
    function bc_kyc_av_scan_upload(string $tmp_path, string $original_name, string $expected_ext, array $options = []): array
    {
        $ext = strtolower(trim($expected_ext));
        $doc_kind = (string)($options['doc_kind'] ?? 'document');
        $min_dim = (int)($options['min_dim'] ?? ($doc_kind === 'selfie' ? 160 : 120));
        $conn = $options['connection'] ?? null;
        $vendor_id = (int)($options['vendor_id'] ?? 0);
        $user_id = (int)($options['user_id'] ?? 0);
        $subject_type = (string)($options['subject_type'] ?? (strpos($doc_kind, 'kyb') !== false ? 'kyb' : 'kyc'));
        $file_size_stat = ($tmp_path !== '' && is_file($tmp_path)) ? (int)@filesize($tmp_path) : 0;
        $detected_mime = '';
        $ai_tokens_charged = 0;
        $checks = [];

        $reject = function (string $reason, bool $threat_found, bool $is_blank, string $mode, array $checks, string $threat_code = '') use ($tmp_path, $conn, $vendor_id, $user_id, $doc_kind, $original_name, $subject_type, &$file_size_stat, &$detected_mime, &$ai_tokens_charged) {
            if ($tmp_path !== '' && is_file($tmp_path)) {
                @unlink($tmp_path);
            }
            if (function_exists('bc_sec_audit')) {
                bc_sec_audit($vendor_id, 'user', 'user#' . $user_id, 'upload_blocked_av', $doc_kind . ':' . basename($original_name), '[' . $mode . '] ' . $reason);
            }
            if ($conn instanceof mysqli && $vendor_id > 0) {
                $tcode = $threat_code !== '' ? $threat_code : ($is_blank ? 'blank_file' : ($threat_found ? 'malware_or_spoof' : 'rejected'));
                bc_kyc_av_log_scan($conn, [
                    'vendor_id'      => $vendor_id,
                    'user_id'        => $user_id,
                    'subject_type'   => $subject_type,
                    'doc_key'        => $doc_kind,
                    'filename'       => $original_name,
                    'file_size'      => $file_size_stat,
                    'mime_type'      => $detected_mime,
                    'scan_mode'      => $mode,
                    'verdict'        => 'blocked',
                    'threat_code'    => $tcode,
                    'reason'         => $reason,
                    'ai_checked'     => ($mode === 'ai+antivirus'),
                    'tokens_charged' => $ai_tokens_charged,
                ]);
                if ($threat_found && function_exists('bc_log_security_event')) {
                    $uq = @mysqli_query($conn, "SELECT username FROM sas_users WHERE id='$user_id' LIMIT 1");
                    $uname = ($uq && ($ur = mysqli_fetch_assoc($uq))) ? (string)$ur['username'] : ('user#' . $user_id);
                    bc_log_security_event('AV_BLOCKED', 'upload_av_threat_' . $tcode, $vendor_id . ':' . $uname, $doc_kind . ': ' . $reason);
                    if (function_exists('bc_ai_sentinel_settings') && function_exists('bc_ai_sentinel_auto_block') && $uname !== '' && strpos($uname, 'user#') !== 0) {
                        $ss = bc_ai_sentinel_settings($conn, $vendor_id);
                        if (($ss['sentinel_auto_block'] ?? '0') === '1' && ($ss['sentinel_av_auto_flag'] ?? '1') === '1') {
                            bc_ai_sentinel_auto_block($conn, $vendor_id, $uname, 'Malicious upload blocked by Antivirus (' . $tcode . ')', 10);
                        }
                    }
                }
            }
            return [
                'allowed'        => false,
                'clean'          => false,
                'scan_mode'      => $mode,
                'threat_found'   => $threat_found,
                'is_blank'       => $is_blank,
                'security_score' => 0,
                'reason'         => $reason,
                'checks'         => $checks,
                'tokens_charged' => $ai_tokens_charged,
            ];
        };

        // 0. Filename hygiene (double dangerous extensions / null bytes)
        if ($original_name !== '' && (strpos($original_name, "\0") !== false
            || preg_match('/\.(?:php\d*|phtml|phar|exe|dll|bat|cmd|sh|js|vbs|ps1|scr|com|msi|jar|html?)\./i', $original_name))
        ) {
            $checks['filename'] = 'failed';
            return $reject('Suspicious filename pattern detected (double executable/script extension). Upload blocked.', true, false, 'standard_antivirus', $checks, 'double_extension');
        }
        $checks['filename'] = 'passed';

        // 1. Existence & non-empty minimum byte size
        if ($tmp_path === '' || !is_file($tmp_path)) {
            $checks['size'] = 'failed';
            return $reject('Uploaded temporary file is missing or unreadable.', false, true, 'standard_antivirus', $checks, 'missing_file');
        }
        $size = (int)@filesize($tmp_path);
        $file_size_stat = $size;
        $min_bytes = in_array($ext, ['mp4', 'webm', 'mov', 'mkv'], true) ? 1024 : 256;
        if ($size === 0 || $size < $min_bytes) {
            $checks['size'] = 'failed';
            return $reject('Blank or empty file rejected (' . $size . ' bytes). Please upload a valid non-empty file.', false, true, 'standard_antivirus', $checks, 'blank_size');
        }
        $checks['size'] = 'passed';

        // 2. Magic-byte header & MIME verification
        $magic = bc_kyc_av_check_magic_bytes($tmp_path, $ext);
        $detected_mime = (string)($magic['mime'] ?? '');
        if (!$magic['ok']) {
            $checks['magic_bytes'] = 'failed';
            return $reject($magic['reason'], true, false, 'standard_antivirus', $checks, 'mime_spoof');
        }
        $checks['magic_bytes'] = 'passed';

        // 3. Malware, executable, webshell, and dangerous PDF scan
        $sig = bc_kyc_av_scan_signatures($tmp_path, $ext);
        if (!$sig['clean']) {
            $checks['signatures'] = 'failed:' . ($sig['threat'] ?? 'unknown');
            return $reject($sig['reason'], empty($sig['is_blank']), !empty($sig['is_blank']), 'standard_antivirus', $checks, (string)($sig['threat'] ?? 'signature'));
        }
        $checks['signatures'] = 'passed';

        // 4. Blank / solid-color image inspection
        if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true)) {
            $img_check = bc_kyc_av_check_blank_image($tmp_path, $min_dim);
            if (!$img_check['ok']) {
                $checks['blank_image'] = 'failed';
                return $reject($img_check['reason'], false, true, 'standard_antivirus', $checks, 'blank_image');
            }
            $checks['blank_image'] = 'passed';
        }

        // 5. Host ClamAV daemon/scanner if installed
        $clam = bc_kyc_av_run_clamav($tmp_path);
        if (!empty($clam['ran'])) {
            $checks['clamav'] = $clam['clean'] ? 'passed (' . ($clam['engine'] ?? 'clamav') . ')' : 'failed';
            if (!$clam['clean']) {
                return $reject($clam['reason'], true, false, 'standard_antivirus', $checks, 'clamav_hit');
            }
        } else {
            $checks['clamav'] = 'not_installed';
        }

        // 6. Stage 2: AI-Powered Security & Blank-File Inspection (if AI is enabled by bc-spadmin & bc-admin)
        $ai_gate = bc_kyc_is_ai_enabled($conn, $vendor_id);
        $scan_mode = 'standard_antivirus';
        $security_score = 92;

        if ($ai_gate['enabled']) {
            $ai_res = bc_kyc_av_ai_inspect($tmp_path, $ext, $doc_kind);
            if (!empty($ai_res['ran'])) {
                $scan_mode = 'ai+antivirus';
                if ($conn instanceof mysqli && $vendor_id > 0 && function_exists('bc_ai_charge_vendor_tokens')) {
                    $bill = bc_ai_charge_vendor_tokens($conn, $vendor_id, 'av_scan', 'user#' . $user_id, 'Pre-upload AI AV Scan (' . $doc_kind . ': ' . basename($original_name) . ')', (string)($ai_res['model'] ?? ''), 0);
                    $ai_tokens_charged = (int)($bill['tokens_charged'] ?? 0);
                }
                $checks['ai_security'] = $ai_res['clean'] ? 'passed' : 'failed:' . ($ai_res['threat'] ?? 'flagged');
                if (!$ai_res['clean']) {
                    return $reject($ai_res['reason'], empty($ai_res['is_blank']), !empty($ai_res['is_blank']), 'ai+antivirus', $checks, (string)($ai_res['threat'] ?? 'ai_flagged'));
                }
                $security_score = (int)($ai_res['security_score'] ?? 96);
            } else {
                $scan_mode = 'standard_antivirus_fallback';
                $checks['ai_security'] = 'fallback: ' . ($ai_res['reason'] ?? 'unavailable');
            }
        } else {
            $checks['ai_security'] = 'disabled: ' . $ai_gate['reason'];
        }

        if ($conn instanceof mysqli && $vendor_id > 0) {
            bc_kyc_av_log_scan($conn, [
                'vendor_id'      => $vendor_id,
                'user_id'        => $user_id,
                'subject_type'   => $subject_type,
                'doc_key'        => $doc_kind,
                'filename'       => $original_name,
                'file_size'      => $file_size_stat,
                'mime_type'      => $detected_mime,
                'scan_mode'      => $scan_mode,
                'verdict'        => 'clean',
                'threat_code'    => '',
                'reason'         => $scan_mode === 'ai+antivirus' ? 'Passed AI + Antivirus & blank-file security scan.' : 'Passed standard Antivirus & blank-file security scan.',
                'ai_checked'     => ($scan_mode === 'ai+antivirus'),
                'tokens_charged' => $ai_tokens_charged,
            ]);
        }

        return [
            'allowed'        => true,
            'clean'          => true,
            'scan_mode'      => $scan_mode,
            'threat_found'   => false,
            'is_blank'       => false,
            'security_score' => $security_score,
            'reason'         => $scan_mode === 'ai+antivirus'
                ? 'Passed AI + Antivirus & blank-file security scan.'
                : 'Passed standard Antivirus & blank-file security scan.',
            'checks'         => $checks,
            'tokens_charged' => $ai_tokens_charged,
        ];
    }
}
