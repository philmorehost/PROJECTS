<?php
/** Must run before session_start; do not trust arbitrary forwarded-protocol headers. */
function bc_secure_session_start() {
    if (session_status() === PHP_SESSION_ACTIVE) return true;
    if (PHP_SAPI !== 'cli') {
        ini_set('session.use_strict_mode','1');
        ini_set('session.use_only_cookies','1');
        session_set_cookie_params(array('lifetime'=>0,'path'=>'/','secure'=>true,'httponly'=>true,'samesite'=>'Lax'));
    }
    return session_start();
}
