<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/DGV7.0-SAAS/func/bc-ai-engine.php';
require_once $root . '/DGV7.0-SAAS/func/bc-kyc-av-scanner.php';
require_once $root . '/DGV7.0-SAAS/func/bc-kyc-ai-analyzer.php';

$pass = 0;
function kchk(bool $ok, string $msg): void
{
    global $pass;
    if (!$ok) {
        throw new RuntimeException("FAIL: $msg");
    }
    $pass++;
}

// Stub getSuperAdminOption if not loaded
if (!function_exists('getSuperAdminOption')) {
    $GLOBALS['__test_super_opts'] = ['ai_global_enabled' => '0'];
    function getSuperAdminOption(string $k, $default = '')
    {
        return $GLOBALS['__test_super_opts'][$k] ?? $default;
    }
}

$tmpDir = sys_get_temp_dir() . '/kyc_av_test_' . bin2hex(random_bytes(4));
@mkdir($tmpDir, 0755, true);

// 1. Standard antivirus blocks 0-byte & undersized files (AI OFF)
$emptyFile = $tmpDir . '/empty.jpg';
file_put_contents($emptyFile, '');
$res = bc_kyc_av_scan_upload($emptyFile, 'empty.jpg', 'jpg');
kchk($res['allowed'] === false && $res['is_blank'] === true, '0-byte file blocked as blank');
kchk(!file_exists($emptyFile), 'blocked 0-byte temp file removed before storage');

// 2. Blocks double dangerous extension
$doubleExt = $tmpDir . '/shell.php.jpg';
file_put_contents($doubleExt, str_repeat("\xFF\xD8\xFF\xE0", 120));
$res = bc_kyc_av_scan_upload($doubleExt, 'shell.php.jpg', 'jpg');
kchk($res['allowed'] === false && $res['threat_found'] === true, 'double php.jpg extension blocked');

// 3. Blocks spoofed JPEG (HTML/PHP renamed to .jpg)
$spoofJpg = $tmpDir . '/spoof.jpg';
file_put_contents($spoofJpg, str_repeat("<html><script>alert(1)</script></html>", 20));
$res = bc_kyc_av_scan_upload($spoofJpg, 'spoof.jpg', 'jpg');
kchk($res['allowed'] === false && $res['threat_found'] === true, 'HTML/script disguised as .jpg blocked');

// 4. Blocks EICAR malware signature disguised as JPEG
$eicarJpg = $tmpDir . '/eicar.jpg';
file_put_contents($eicarJpg, "\xFF\xD8\xFF\xE0" . str_repeat("\x00", 60) . 'X5O!P%@AP[4\PZX54(P^)7CC)7}$EICAR-STANDARD-ANTIVIRUS-TEST-FILE!$H+H*' . str_repeat("\x00", 300));
$res = bc_kyc_av_scan_upload($eicarJpg, 'eicar.jpg', 'jpg');
kchk($res['allowed'] === false && $res['threat_found'] === true, 'EICAR signature inside JPEG blocked');

// 5. Blocks embedded PE/EXE trojan inside JPEG
$peJpg = $tmpDir . '/trojan.jpg';
file_put_contents($peJpg, "\xFF\xD8\xFF\xE0" . str_repeat("A", 120) . "MZ" . str_repeat("\x00", 64) . "PE\x00\x00" . str_repeat("B", 200));
$res = bc_kyc_av_scan_upload($peJpg, 'trojan.jpg', 'jpg');
kchk($res['allowed'] === false && $res['threat_found'] === true, 'embedded PE trojan inside JPEG blocked');

// 6. Blocks embedded PHP webshell inside PNG
$phpPng = $tmpDir . '/webshell.png';
file_put_contents($phpPng, "\x89PNG\r\n\x1a\n" . str_repeat("\x00", 120) . '<?php system($_GET["c"]); ?>' . str_repeat("\x00", 200));
$res = bc_kyc_av_scan_upload($phpPng, 'webshell.png', 'png');
kchk($res['allowed'] === false && $res['threat_found'] === true, 'embedded PHP webshell inside PNG blocked');

// 7. Blocks weaponized PDF with /JavaScript or /OpenAction
$badPdf = $tmpDir . '/malicious.pdf';
file_put_contents($badPdf, "%PDF-1.4\n1 0 obj << /Type /Catalog /OpenAction << /S /JavaScript /JS (app.alert(1)) >> >> endobj\n" . str_repeat(" ", 600) . "%%EOF");
$res = bc_kyc_av_scan_upload($badPdf, 'malicious.pdf', 'pdf');
kchk($res['allowed'] === false && $res['threat_found'] === true, 'weaponized PDF with /JavaScript & /OpenAction blocked');

// 8. Blocks blank PDF (no content / empty page)
$blankPdf = $tmpDir . '/blank.pdf';
file_put_contents($blankPdf, "%PDF-1.4\n1 0 obj << /Type /Page >> endobj\nstream\n   \nendstream\n" . str_repeat(" ", 650) . "%%EOF");
$res = bc_kyc_av_scan_upload($blankPdf, 'blank.pdf', 'pdf');
kchk($res['allowed'] === false && $res['is_blank'] === true, 'blank PDF without content rejected');

// 9. Accepts valid clean PDF with real text stream (standard_antivirus mode when AI is OFF)
$goodPdf = $tmpDir . '/utility_bill.pdf';
file_put_contents($goodPdf, "%PDF-1.4\n1 0 obj << /Type /Page /Font << /F1 2 0 R >> >> endobj\nstream\nBT /F1 12 Tf 72 720 Td (IKEJA ELECTRICITY BILL 2026-11-01 12 ADEOLA ODEKU) Tj ET\nendstream\n" . str_repeat(" ", 600) . "%%EOF");
$res = bc_kyc_av_scan_upload($goodPdf, 'utility_bill.pdf', 'pdf');
kchk($res['allowed'] === true && $res['scan_mode'] === 'standard_antivirus', 'valid clean PDF allowed in standard antivirus mode');

// 10. GD blank white image vs textured real image
if (function_exists('imagecreatetruecolor')) {
    $blankPng = $tmpDir . '/blank_white.png';
    $im = imagecreatetruecolor(240, 240);
    $white = imagecolorallocate($im, 255, 255, 255);
    imagefill($im, 0, 0, $white);
    imagepng($im, $blankPng);
    imagedestroy($im);
    $res = bc_kyc_av_scan_upload($blankPng, 'blank_white.png', 'png');
    kchk($res['allowed'] === false && $res['is_blank'] === true, 'blank pure-white PNG image rejected');

    $realPng = $tmpDir . '/valid_doc.png';
    $im2 = imagecreatetruecolor(300, 200);
    $bg = imagecolorallocate($im2, 245, 247, 250);
    imagefill($im2, 0, 0, $bg);
    $ink = imagecolorallocate($im2, 20, 40, 90);
    $accent = imagecolorallocate($im2, 10, 140, 70);
    imagefilledrectangle($im2, 20, 20, 100, 140, $ink);
    imagefilledrectangle($im2, 120, 30, 270, 50, $accent);
    imagefilledrectangle($im2, 120, 70, 250, 85, $ink);
    imagefilledrectangle($im2, 120, 100, 260, 115, $ink);
    imagepng($im2, $realPng);
    imagedestroy($im2);
    $res = bc_kyc_av_scan_upload($realPng, 'valid_doc.png', 'png');
    kchk($res['allowed'] === true && $res['clean'] === true, 'textured valid document PNG accepted');
}

// 11. AI gate respects bc-spadmin global toggle
$gateOff = bc_kyc_is_ai_enabled(null, 1);
kchk($gateOff['enabled'] === false && $gateOff['global_enabled'] === false, 'AI disabled when bc-spadmin ai_global_enabled=0');

// 12. Document ratings & decision engine: video face mismatch & POA > 3 months
$decisionMismatch = bc_kyc_ai_make_decision([
    'govt_id' => [
        'status' => 'success', 'document_type' => 'National ID', 'full_name' => 'Adebayo Kunle',
        'is_valid' => true, 'is_expired' => false, 'name_matches' => true, 'photo_present' => true,
        'doc_rating' => 92, 'confidence' => 94,
    ],
    'selfie' => [
        'status' => 'success', 'face_detected' => true, 'is_live_photo' => true,
        'face_matches_id' => true, 'face_similarity' => 91, 'doc_rating' => 90, 'confidence' => 92,
    ],
    'liveliness_video' => [
        'status' => 'success', 'face_detected' => true, 'is_live_video' => true,
        'face_matches_id' => false, 'face_match_score' => 22, 'doc_rating' => 20, 'confidence' => 88,
    ],
    'proof_of_address' => [
        'status' => 'success', 'is_valid_utility_bill' => true, 'bill_date' => date('Y-m-d', strtotime('-140 days')),
        'bill_age_days' => 140, 'is_under_3_months' => false, 'address_matches' => true,
        'service_provider' => 'IKEDC', 'doc_rating' => 30, 'confidence' => 90,
    ],
], ['govt_id', 'liveliness_picture', 'liveliness_video', 'proof_of_address'], 'Adebayo Kunle', []);

kchk($decisionMismatch['status'] === 'reject', 'decision rejects on video face mismatch & >3m POA');
kchk(strpos($decisionMismatch['reason'], 'liveliness video does not match') !== false, 'decision reports video face mismatch');
kchk(strpos($decisionMismatch['reason'], 'older than 3 months') !== false, 'decision reports >3 months POA');
kchk(($decisionMismatch['ratings']['liveliness_video']['verdict'] ?? '') === 'fail', 'video rating marked fail');
kchk(($decisionMismatch['ratings']['proof_of_address']['verdict'] ?? '') === 'fail', 'POA rating marked fail');
kchk(($decisionMismatch['ratings']['govt_id']['score'] ?? 0) === 92, 'govt_id keeps 92 score');

$decisionPass = bc_kyc_ai_make_decision([
    'govt_id' => [
        'status' => 'success', 'document_type' => 'Passport', 'full_name' => 'Adebayo Kunle',
        'is_valid' => true, 'is_expired' => false, 'name_matches' => true, 'photo_present' => true,
        'doc_rating' => 94, 'confidence' => 95,
    ],
    'selfie' => [
        'status' => 'success', 'face_detected' => true, 'is_live_photo' => true,
        'face_matches_id' => true, 'face_similarity' => 93, 'doc_rating' => 92, 'confidence' => 94,
    ],
    'liveliness_video' => [
        'status' => 'success', 'face_detected' => true, 'is_live_video' => true,
        'face_matches_id' => true, 'face_match_score' => 91, 'movement_detected' => true,
        'doc_rating' => 91, 'confidence' => 90,
    ],
    'proof_of_address' => [
        'status' => 'success', 'is_valid_utility_bill' => true, 'bill_date' => date('Y-m-d', strtotime('-20 days')),
        'bill_age_days' => 20, 'is_under_3_months' => true, 'address_matches' => true,
        'service_provider' => 'EKEDC', 'doc_rating' => 90, 'confidence' => 92,
    ],
], ['govt_id', 'liveliness_picture', 'liveliness_video', 'proof_of_address'], 'Adebayo Kunle', []);

kchk($decisionPass['status'] === 'approve', 'valid matching KYC checks produce approve recommendation');
kchk($decisionPass['overall_rating'] >= 90 && $decisionPass['overall_grade'] === 'A', 'valid matching KYC gets Grade A overall rating');

// 13. KYB rating helper check
$kybRate = bc_kyc_ai_rate_document('kyb_document', [
    'status' => 'success',
    'is_valid_business_doc' => true,
    'is_blank_or_tampered' => false,
    'business_name_matches' => true,
    'reg_no_matches' => false,
    'doc_rating' => 85,
]);
kchk($kybRate['verdict'] === 'fail' && $kybRate['score'] <= 25, 'KYB rating fails when reg_no mismatches');

// 14. Integration & Edition Parity checks
foreach (['SAAS', 'NON-SAAS'] as $ed) {
    $base = $root . '/DGV7.0-' . $ed;
    foreach (['web/KYCVerification.php', 'web/BusinessUpgrade.php', 'web/api/kyc.php', 'bc-admin/AccountSettings.php'] as $f) {
        $src = file_get_contents($base . '/' . $f);
        kchk(strpos($src, 'bc_kyc_av_scan_upload(') !== false, "$ed/$f calls bc_kyc_av_scan_upload() before saving");
        kchk(strrpos($src, 'bc_kyc_av_scan_upload(') < strrpos($src, 'move_uploaded_file('), "$ed/$f scans BEFORE KYC/KYB move_uploaded_file()");
    }
    $kycMgmt = file_get_contents($base . '/bc-admin/KYCManagement.php');
    kchk(strpos($kycMgmt, 'run_kyc_ai_report') !== false && strpos($kycMgmt, 'toggle_kyc_ai') !== false, "$ed/bc-admin/KYCManagement.php has AI toggle & report");
    $kybMgmt = file_get_contents($base . '/bc-admin/BusinessKYB.php');
    kchk(strpos($kybMgmt, 'run_kyb_ai_review') !== false && strpos($kybMgmt, 'bc_kyb_ai_get_report(') !== false, "$ed/bc-admin/BusinessKYB.php displays AI KYB report & rating");
    $sec = file_get_contents($base . '/func/bc-security.php');
    kchk(strpos($sec, 'bc_kyb_ai_store_report') !== false && strpos($sec, 'bc_kyc_ai_ensure_schema') !== false, "$ed/func/bc-security.php has KYB report & schema helpers");
}

foreach (['func/bc-ai-engine.php', 'func/bc-kyc-av-scanner.php', 'func/bc-kyc-ai-analyzer.php', 'web/BusinessUpgrade.php', 'bc-admin/BusinessKYB.php', 'bc-admin/AISettings.php', 'bc-admin/AIMarketing.php', 'bc-admin/SendMail.php', 'func/bc-support-chat.php', 'func/bc-support-chat-schema.php', 'web/ajax-support-chat.php'] as $shared) {
    kchk(
        file_get_contents($root . '/DGV7.0-SAAS/' . $shared) === file_get_contents($root . '/DGV7.0-NON-SAAS/' . $shared),
        "edition parity for $shared"
    );
}

// 15. AI Business Suite consolidation, SAAS vs NON-SAAS split & in-dashboard AV reports
foreach (['SAAS', 'NON-SAAS'] as $ed) {
    $base = $root . '/DGV7.0-' . $ed;
    $aiSet = file_get_contents($base . '/bc-admin/AISettings.php');
    kchk(strpos($aiSet, 'ai_user_token_price') === false && strpos($aiSet, 'ai_paid_usage') === false, "$ed AISettings removes retired user token monetization");
    kchk(strpos($aiSet, 'Intelligence Hub') === false && strpos($aiSet, 'Voice Control') === false, "$ed AISettings removes Intelligence Hub and Voice Control");
    kchk(strpos($aiSet, 'id="tab-billing"') !== false && strpos($aiSet, 'id="tab-support"') !== false && strpos($aiSet, 'id="tab-sentinel"') !== false && strpos($aiSet, 'id="tab-reports"') !== false, "$ed AISettings has unified 4-tab AI Business Suite");
    kchk(strpos($aiSet, 'sentinel_auto_block') !== false && strpos($aiSet, 'sas_kyc_av_logs') !== false, "$ed AISettings exposes Security Sentinel auto-block and AV reports");

    $av = file_get_contents($base . '/func/bc-kyc-av-scanner.php');
    kchk(strpos($av, 'sas_kyc_av_logs') !== false && strpos($av, 'bc_ai_charge_vendor_tokens') !== false, "$ed AV scanner persists logs and charges SAAS tokens");

    $chat = file_get_contents($base . '/func/bc-support-chat.php');
    kchk(strpos($chat, 'bc_ai_platform_status') !== false && strpos($chat, 'support_reply') !== false, "$ed support chat connects to central AI engine & billing");

    kchk(strpos($aiSet, 'bc_kyc_ai_ensure_schema($connection_server)') !== false, "$ed AISettings passes connection_server to bc_kyc_ai_ensure_schema()");
    $secSrc = file_get_contents($base . '/func/bc-security.php');
    kchk(strpos($secSrc, 'function bc_kyc_ai_ensure_schema($connection_server = null)') !== false, "$ed bc-security.php makes bc_kyc_ai_ensure_schema connection optional");
    $analyzerSrc = file_get_contents($base . '/func/bc-kyc-ai-analyzer.php');
    kchk(strpos($analyzerSrc, 'bc_ai_platform_status($connection_server)') === false && strpos($analyzerSrc, '->isAvailable()') === false, "$ed bc-kyc-ai-analyzer.php passes vendor_id to bc_ai_platform_status and avoids undefined isAvailable()");

    foreach (['bc-admin/AIMarketing.php', 'bc-admin/SendMail.php'] as $copilotRel) {
        $copilotSrc = file_get_contents($base . '/' . $copilotRel);
        kchk(strpos($copilotSrc, "substr(\$generated, 0, 500)") === false, "$ed $copilotRel passes 8 args to bc_ai_charge_vendor_tokens without shifting model into duration_ms");
        kchk(strpos($copilotSrc, "\$billing['tokens_remaining']") !== false && strpos($copilotSrc, "\$billing['reason']") !== false, "$ed $copilotRel reads tokens_remaining and reason from bc_ai_charge_vendor_tokens()");
        kchk(strpos($copilotSrc, "\$billing['balance']") === false && strpos($copilotSrc, "\$billing['error']") === false, "$ed $copilotRel does not read nonexistent balance/error billing keys");
    }
    $aiEngineSrc = file_get_contents($base . '/func/bc-ai-engine.php');
    kchk(strpos($aiEngineSrc, ': string|false') === false, "$ed func/bc-ai-engine.php avoids PHP 8.0-only union return type syntax");
    kchk(strpos($aiEngineSrc, "if (class_exists('AIEngine')) return;") === false && strpos($aiEngineSrc, 'BC_AI_ENGINE_LOADED') !== false, "$ed func/bc-ai-engine.php guards via BC_AI_ENGINE_LOADED instead of early-bound class_exists()");
}
kchk(function_exists('bc_ai_is_saas') && function_exists('bc_ai_get_action_cost') && function_exists('bc_ai_platform_status') && function_exists('bc_ai_charge_vendor_tokens') && function_exists('bc_ai_sentinel_settings'), 'bc-ai-engine.php defines all bc_ai_* procedural helpers on load');
$spAi = file_get_contents($root . '/DGV7.0-SAAS/bc-spadmin/AIManagement.php');
kchk(strpos($spAi, 'av_scan_cost') !== false && strpos($spAi, 'kyc_report_cost') !== false && strpos($spAi, 'support_reply_cost') !== false && strpos($spAi, 'sas_kyc_av_logs') !== false, 'SAAS spadmin AIManagement configures AV/KYC/Support rates and views AV reports');
kchk(strpos($spAi, 'bc_kyc_ai_ensure_schema($connection_server)') !== false, 'SAAS spadmin AIManagement passes connection_server to bc_kyc_ai_ensure_schema()');

// Regression: AIEngine::buildPostHeaders() never emits duplicate Content-Type headers
$hDefault = AIEngine::buildPostHeaders([]);
kchk($hDefault === ['Content-Type: application/json'], 'AIEngine::buildPostHeaders defaults to a single Content-Type: application/json header');
$hDedup = AIEngine::buildPostHeaders([
    'Content-Type: application/json',
    'content-type: application/json',
    'Authorization: Bearer test-key',
]);
$ctCount = 0;
foreach ($hDedup as $hdr) {
    if (stripos($hdr, 'content-type:') === 0) $ctCount++;
}
kchk($ctCount === 1 && in_array('Authorization: Bearer test-key', $hDedup, true), 'AIEngine::buildPostHeaders deduplicates Content-Type headers while preserving auth headers');

// Cleanup
foreach (glob($tmpDir . '/*') ?: [] as $f) @unlink($f);
@rmdir($tmpDir);

echo "$pass KYC/KYB antivirus & AI compliance checks passed\n";
