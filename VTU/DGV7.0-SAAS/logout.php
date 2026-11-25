<?php
require_once __DIR__ . '/func/bc-secure-session.php';
 bc_secure_session_start();
    if (!empty($_SESSION['bc_web_session_token']) && is_file(__DIR__ . '/func/db-dtl.php')) {
        mysqli_report(MYSQLI_REPORT_OFF);
        include __DIR__ . '/func/db-dtl.php';
        $conn = @mysqli_connect($mySqlServer ?? '', $mySqlUser ?? '', $mySqlPass ?? '', $mySqlDBName ?? '');
        if ($conn instanceof mysqli) {
            $hash = hash('sha256', (string)$_SESSION['bc_web_session_token']);
            if ($st = @mysqli_prepare($conn, "UPDATE sas_web_sessions SET ended_at = NOW(), end_reason = 'logout' WHERE session_token_hash = ? AND ended_at IS NULL")) {
                mysqli_stmt_bind_param($st, 's', $hash);
                @mysqli_stmt_execute($st);
                mysqli_stmt_close($st);
            }
            @mysqli_close($conn);
        }
    }
    unset($_SESSION["user_session"], $_SESSION['bc_web_session_token'], $_SESSION['bc_web_session_touch_ts']);
    unset($_SESSION["reset-security-detail"]);
    header("Location: /web/Login.php");
    exit();
?>