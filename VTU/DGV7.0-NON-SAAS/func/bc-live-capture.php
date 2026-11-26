<?php
require_once __DIR__.'/bc-video-evidence.php';
/** Camera UI is not biometric attestation. All evidence still requires independent review. */
function bc_live_capture_widget($name, $video = false) {
    $raw_name = (string)$name;
    $required = !empty($GLOBALS['bc_live_capture_required']) || !empty($GLOBALS['bc_live_capture_required_fields'][$raw_name]);
    $name = htmlspecialchars($raw_name, ENT_QUOTES, 'UTF-8');
    $kind = $video ? 'video' : 'photo';
    $reqAttr = $required ? ' data-kyc-required="1"' : '';
    echo '<div data-live-capture="'.$kind.'" class="border rounded p-2">'
        .'<input type="file" hidden tabindex="-1" aria-hidden="true" name="'.$name.'"'.$reqAttr.' accept="'.($video?'video/webm,video/mp4':'image/jpeg').'">'
        .'<video autoplay playsinline muted style="width:100%;max-width:480px" hidden></video>'
        .'<img alt="Captured photo preview" style="width:100%;max-width:480px" hidden>'
        .'<button type="button" data-camera-start class="btn btn-outline-primary btn-sm">Start camera'.($video?' & record':'').'</button> '
        .(!$video?'<button type="button" data-camera-shot class="btn btn-primary btn-sm" hidden>Take photo</button>':'')
        .'<button type="button" data-camera-reset class="btn btn-outline-secondary btn-sm" hidden>Retake</button>'
        .'<p role="status" class="small mb-0">'.($video?'Record a short 6-second video: say your full name and today’s date, then turn your head slowly. Maximum 3 MB.':'Take a clear live photo. JPEG up to 960 pixels, maximum 512 KB.').' Camera permission and HTTPS required. No gallery upload. Captures are subject to review.</p></div>';
    static $loaded = false;
    if (!$loaded) { echo '<script src="../asset/bc-live-capture.js" defer></script>'; $loaded = true; }
}
function bc_live_media_last_error($msg = null) {
    if ($msg !== null) {
        $GLOBALS['bc_live_media_last_error'] = (string)$msg;
    }
    return (string)($GLOBALS['bc_live_media_last_error'] ?? '');
}
/** Bound storage even for alternate API clients. Never trust filename or client flags as liveness. */
function bc_live_media_prepare($tmp, $kind) {
    bc_live_media_last_error('');
    if (!is_file($tmp)) { bc_live_media_last_error('Uploaded file is missing.'); return false; }
    $size = filesize($tmp);
    $max = ($kind === 'selfie' ? 512 * 1024 : 3 * 1024 * 1024);
    if ($size <= 0 || $size > $max) {
        bc_live_media_last_error($kind === 'selfie' ? 'Live photo must be 512 KB or smaller.' : 'Liveliness video must be 3 MB or smaller.');
        return false;
    }
    if ($kind !== 'selfie') {
        if (!class_exists('finfo')) { bc_live_media_last_error('PHP fileinfo extension is unavailable.'); return false; }
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($tmp);
        if ($mime === 'video/x-matroska') $mime = 'video/webm';
        elseif ($mime === 'video/quicktime' || $mime === 'application/mp4') $mime = 'video/mp4';
        $ok = bc_video_evidence_inspect($tmp, $mime);
        if (!$ok && bc_video_evidence_last_error() !== '') {
            bc_live_media_last_error(bc_video_evidence_last_error());
        }
        return $ok;
    }
    $info = @getimagesize($tmp);
    if (!$info || (int)$info[2] !== IMAGETYPE_JPEG || ($info['mime'] ?? '') !== 'image/jpeg' || $info[0] > 960 || $info[1] > 960 || min($info[0], $info[1]) < 160) {
        bc_live_media_last_error('Live photo must be a JPEG between 160px and 960px.');
        return false;
    }
    if (class_exists('finfo') && (new finfo(FILEINFO_MIME_TYPE))->file($tmp) !== 'image/jpeg') {
        bc_live_media_last_error('Live photo must be a valid JPEG.');
        return false;
    }
    // Strip metadata and recompress when GD is available; input limits apply even without GD.
    if (function_exists('imagecreatefromjpeg')) {
        $image = @imagecreatefromjpeg($tmp);
        if (!$image) { bc_live_media_last_error('Could not decode live JPEG.'); return false; }
        $ok = imagejpeg($image, $tmp, 78); imagedestroy($image);
        if (!$ok) { bc_live_media_last_error('Could not process live JPEG.'); return false; }
        clearstatcache(true, $tmp);
    }
    if (filesize($tmp) > 512 * 1024) {
        bc_live_media_last_error('Live photo exceeds 512 KB.');
        return false;
    }
    return true;
}
