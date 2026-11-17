<?php
// php-version/admin/reporting-mode.php
require_once '../includes/functions.php';

/*
 * Switches the platform admin's REPORTING LENS between live and test data.
 *
 * This is deliberately not the same thing as the merchant's environment switch in
 * includes/topbar.php. That one writes users.is_test_mode, which decides the account a
 * merchant operates in. This only decides which world the admin is looking at, so it is
 * session state and never touches the database - and it can never change what a
 * transaction is or whether it is honoured.
 */

if (!isLoggedIn() || !isAdmin()) {
    redirect('../login.php');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verify_csrf_token($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    exit('Invalid request');
}

$_SESSION['admin_reporting_mode'] = (($_POST['mode'] ?? 'live') === 'test') ? 'test' : 'live';

/*
 * Return to the page the switch was made from. Only ever a local path: no scheme, no
 * traversal, no control characters, so the value cannot be used to bounce the admin (or
 * inject a header) somewhere else.
 */
$return = (string)($_POST['return'] ?? '');
$return_is_safe = ($return !== '')
    && ($return[0] === '/')
    && (strpos($return, '://') === false)
    && (strpos($return, '..') === false)
    && (preg_match('/[\r\n]/', $return) !== 1);

redirect($return_is_safe ? $return : BASE_URL . 'admin/index.php');
