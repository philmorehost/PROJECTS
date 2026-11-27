<?php
require_once __DIR__.'/bc-video-evidence.php';
/** Camera UI is not biometric attestation. All evidence still requires independent review. */
function bc_live_capture_widget($name, $video = false) {
    $raw_name = (string)$name;
    $required = !empty($GLOBALS['bc_live_capture_required']) || !empty($GLOBALS['bc_live_capture_required_fields'][$raw_name]);
    $name = htmlspecialchars($raw_name, ENT_QUOTES, 'UTF-8');
    $kind = $video ? 'video' : 'photo';
    $reqAttr = $required ? ' data-kyc-required="1"' : '';
    echo '<div data-live-capture="'.$kind.'" class="border rounded-4 p-3 bg-light bg-opacity-50">'
        .'<input type="file" hidden tabindex="-1" aria-hidden="true" name="'.$name.'"'.$reqAttr.' accept="'.($video?'video/webm,video/mp4':'image/jpeg').'">'
        .'<div data-camera-stage style="position:relative;max-width:480px;margin-bottom:8px;" hidden>'
            .'<video autoplay playsinline muted style="width:100%;max-width:480px;border-radius:14px;background:#0f172a;display:block;" hidden></video>'
            .'<div data-face-overlay style="pointer-events:none;position:absolute;inset:0;display:flex;flex-direction:column;align-items:center;justify-content:center;padding:12px;" hidden>'
                .'<div data-face-oval style="width:52%;max-width:210px;aspect-ratio:3/4;border:3px dashed rgba(255,255,255,0.85);border-radius:50%;box-shadow:0 0 0 9999px rgba(15,23,42,0.38);transition:border-color .2s ease;"></div>'
                .'<div data-prompt-pill style="position:relative;z-index:2;margin-top:10px;background:rgba(15,23,42,0.86);color:#fff;padding:6px 14px;border-radius:999px;font-size:12px;font-weight:700;text-align:center;">'.($video?'Look at camera &amp; say the word &ldquo;HI&rdquo;':'Center your face inside the oval').'</div>'
                .($video?'<div data-mic-wrap style="position:relative;z-index:2;margin-top:6px;display:flex;align-items:center;gap:6px;background:rgba(15,23,42,0.8);color:#e2e8f0;padding:4px 10px;border-radius:999px;font-size:11px;"><span>Mic</span><span style="display:inline-block;width:72px;height:6px;background:rgba(255,255,255,0.2);border-radius:999px;overflow:hidden;"><span data-mic-bar style="display:block;height:100%;width:8%;background:#10b981;transition:width .08s linear;"></span></span></div>':'')
            .'</div>'
        .'</div>'
        .'<img alt="Captured photo preview" style="width:100%;max-width:480px;border-radius:14px;margin-bottom:8px;" hidden>'
        .'<div class="d-flex flex-wrap gap-2 align-items-center mb-2">'
            .'<button type="button" data-camera-start class="btn btn-outline-primary btn-sm rounded-pill px-3 fw-bold">Start camera'.($video?' &amp; say "HI"':'').'</button>'
            .(!$video?'<button type="button" data-camera-shot class="btn btn-primary btn-sm rounded-pill px-3 fw-bold" hidden>Take photo now</button>':'')
            .'<button type="button" data-camera-reset class="btn btn-outline-secondary btn-sm rounded-pill px-3" hidden>Retake</button>'
        .'</div>'
        .'<p role="status" class="small text-muted mb-0">'.($video?'Click Start, look inside the oval, and say the word &ldquo;HI&rdquo; aloud (4-second live video with audio, max 3 MB).':'Click Start and hold your face inside the oval — it auto-captures once your face is clearly framed (JPEG up to 960px, max 512 KB).').' Camera permission and HTTPS required. No gallery upload. Captures are subject to review.</p></div>';
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
