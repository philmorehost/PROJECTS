<?php
/**
 * Shared post-registration / post-KYC / security onboarding helpers for Web and Mobile APIs.
 * Pure query/policy composition; safe when optional helpers are not loaded.
 */

if (!function_exists('bc_onboarding_flags')) {
    function bc_onboarding_flags($connection_server, $user, $vendor_id, $vendor = null) {
        $vid = (int)$vendor_id;
        if ($vid <= 0 && is_array($user)) {
            $vid = (int)($user['vendor_id'] ?? 0);
        }
        $user = is_array($user) ? $user : array();

        if (!is_array($vendor) && ($connection_server instanceof mysqli) && $vid > 0) {
            $q_v = @mysqli_query($connection_server, "SELECT id, force_security_pin, ai_status FROM sas_vendors WHERE id='$vid' LIMIT 1");
            if ($q_v && mysqli_num_rows($q_v) === 1) {
                $vendor = mysqli_fetch_assoc($q_v);
            }
        }

        $password_change_required = !empty($user['force_password_change']);

        $totp_required = false;
        if (function_exists('bc_enrollment_policy') && $vid > 0) {
            try {
                $ep = bc_enrollment_policy($vid);
                $totp_required = !empty($ep['user']);
            } catch (Throwable $e) {
                $totp_required = false;
            }
        }
        $totp_enabled = function_exists('bc_gauth_enrolled')
            ? bc_gauth_enrolled($user)
            : (((string)($user['two_factor_type'] ?? 'none') === 'totp') && !empty($user['totp_secret']));

        $kyc_enforced = false;
        $kyc_approved = function_exists('bc_kyc_approval_current')
            ? bc_kyc_approval_current($user)
            : ((int)($user['kyc_status'] ?? 0) === 2 && empty($user['kyc_refresh_required']));
        $kyc_gate_locked = false;
        if (function_exists('isKYCEnforced') && $vid > 0 && ($connection_server instanceof mysqli)) {
            try {
                $checks = function_exists('bc_kyc_enabled_checks') ? bc_kyc_enabled_checks($connection_server, $vid) : array();
                $kyc_enforced = (bool)isKYCEnforced($vid) && !empty($checks);
                $kyc_gate_locked = function_exists('bc_kyc_app_confinement_required')
                    ? (bool)bc_kyc_app_confinement_required($user)
                    : ($kyc_enforced && !$kyc_approved);
            } catch (Throwable $e) {
                $kyc_gate_locked = $kyc_enforced && !$kyc_approved;
            }
        }

        $pin_set = !empty($user['security_pin']) || !empty($user['transaction_pin']);
        $pin_required = is_array($vendor) && ((int)($vendor['force_security_pin'] ?? 0) === 1);

        $sq_web = false;
        $sq_app = false;
        if (function_exists('bc_security_question_policy') && $vid > 0) {
            try {
                $sqp = bc_security_question_policy($vid);
                $sq_web = !empty($sqp['web']);
                $sq_app = !empty($sqp['app']);
            } catch (Throwable $e) {
                $sq_web = false;
                $sq_app = false;
            }
        }
        $sq_ans = trim((string)($user['security_answer'] ?? ''));
        $sq_quest = trim((string)($user['security_quest'] ?? ''));
        $security_quest_set = ($sq_quest !== '' && $sq_ans !== '' && strlen($sq_ans) >= 3 && strlen($sq_ans) <= 20);

        return array(
            'password_change_required' => $password_change_required,
            'totp_required' => $totp_required,
            'totp_enabled' => $totp_enabled,
            'kyc_enforced' => $kyc_enforced,
            'kyc_approved' => $kyc_approved,
            'kyc_gate_locked' => $kyc_gate_locked,
            'pin_set' => $pin_set,
            'pin_required' => $pin_required,
            'security_quest_required_web' => $sq_web,
            'security_quest_required_app' => $sq_app,
            'security_quest_set' => $security_quest_set,
        );
    }
}

if (!function_exists('bc_web_next_onboarding_url')) {
    function bc_web_next_onboarding_url($connection_server, $user, $vendor_id, $vendor = null) {
        $f = bc_onboarding_flags($connection_server, $user, $vendor_id, $vendor);
        if ($f['password_change_required']) {
            return '/web/SecuritySetup.php';
        }
        if ($f['totp_required'] && !$f['totp_enabled']) {
            return '/web/SecuritySetup.php';
        }
        if ($f['kyc_enforced'] && !$f['kyc_approved']) {
            return '/web/KYCVerification.php';
        }
        if (!$f['pin_set']) {
            return '/web/SecurityPIN.php';
        }
        if ($f['security_quest_required_web']) {
            $cookie_ans = (string)($_COOKIE['security_answer'] ?? '');
            $user_ans = (string)($user['security_answer'] ?? '');
            if (!$f['security_quest_set'] || $cookie_ans === '' || $cookie_ans !== $user_ans) {
                return '/web/SecurityQuest.php';
            }
        }
        return '/web/Dashboard.php?onboarding=1';
    }
}

if (!function_exists('bc_app_onboarding_fields')) {
    function bc_app_onboarding_fields($connection_server, $user, $vendor_id, $vendor = null) {
        $f = bc_onboarding_flags($connection_server, $user, $vendor_id, $vendor);
        return array(
            'security_pin_set' => $f['pin_set'],
            'pin_required' => $f['pin_required'],
            'security_quest_required' => $f['security_quest_required_app'],
            'security_quest_set' => $f['security_quest_set'],
            'totp_required' => $f['totp_required'],
            'totp_enabled' => $f['totp_enabled'] ? 1 : 0,
            'kyc_enforced' => $f['kyc_enforced'],
            'kyc_approved' => $f['kyc_approved'],
            'kyc_gate_locked' => $f['kyc_gate_locked'],
        );
    }
}
