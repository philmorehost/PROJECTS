<?php
/** Camera UI is not biometric attestation. All evidence still requires independent review. */
function bc_live_capture_widget($name, $video = false) {
    $name = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
    $kind = $video ? 'video' : 'photo';
    echo '<div data-live-capture="'.$kind.'" class="border rounded p-2">'
        .'<input type="file" hidden tabindex="-1" aria-hidden="true" name="'.$name.'" accept="'.($video?'video/webm,video/mp4':'image/jpeg').'">'
        .'<video autoplay playsinline muted style="width:100%;max-width:480px" hidden></video>'
        .'<img alt="Captured photo preview" style="width:100%;max-width:480px" hidden>'
        .'<button type="button" data-camera-start class="btn btn-outline-primary btn-sm">Start camera'.($video?' & record':'').'</button> '
        .(!$video?'<button type="button" data-camera-shot class="btn btn-primary btn-sm" hidden>Take photo</button>':'')
        .'<button type="button" data-camera-reset class="btn btn-outline-secondary btn-sm" hidden>Retake</button>'
        .'<p role="status" class="small mb-0">'.($video?'Record 8 seconds: say your full name and today’s date, then turn your head slowly. Maximum 3 MB.':'Take a clear live photo. JPEG up to 960 pixels, maximum 512 KB.').' Camera permission and HTTPS required. No gallery upload. Captures are subject to review.</p></div>';
    static $loaded = false;
    if (!$loaded) { echo '<script src="../asset/bc-live-capture.js" defer></script>'; $loaded = true; }
}
/** Bound storage even for alternate API clients. Never trust filename or client flags as liveness. */
function bc_live_media_prepare($tmp, $kind) {
    if (!is_file($tmp)) return false;
    $size = filesize($tmp);
    if ($size <= 0 || $size > ($kind === 'selfie' ? 512 * 1024 : 3 * 1024 * 1024)) return false;
    if (!class_exists('finfo')) return false;
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($tmp);
    if ($kind !== 'selfie') return in_array($mime, ['video/webm','video/mp4'], true);
    $info = @getimagesize($tmp);
    if (!$info || $mime !== 'image/jpeg' || $info[0] > 960 || $info[1] > 960 || min($info[0],$info[1]) < 160) return false;
    // Strip metadata and recompress when GD is available; input limits apply even without GD.
    if (function_exists('imagecreatefromjpeg')) {
        $image = @imagecreatefromjpeg($tmp);
        if (!$image) return false;
        $ok = imagejpeg($image, $tmp, 78); imagedestroy($image);
        if (!$ok) return false;
        clearstatcache(true, $tmp);
    }
    return filesize($tmp) <= 512 * 1024;
}
