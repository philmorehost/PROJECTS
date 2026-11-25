<?php
/**
 * Support chat widget visibility, Hub.php?g=settings-help integration,
 * and bc-admin support chat quick-access widget verification.
 */
$root = dirname(__DIR__);
error_reporting(E_ALL);
ini_set('display_errors', '1');

$checks = 0;
$failures = 0;
function schk($cond, $label) {
    global $checks, $failures;
    $checks++;
    if (!$cond) {
        $failures++;
        echo "FAIL: $label\n";
    }
}

$editions = array('DGV7.0-SAAS', 'DGV7.0-NON-SAAS');

// 1. Parity across shared files
foreach (array(
    'func/bc-support-chat-widget.php',
    'func/bc-plate-app.php',
    'func/bc-plate-admin.php',
    'func/bc-mobile-catalogue.php',
    'web/Hub.php',
    'web/AI-Assistant.php',
    'web/ajax-support-chat.php',
    'bc-admin/ajax-support-chat.php',
    'jsfile/ai-assistant.js',
) as $rel) {
    schk(
        file_get_contents($root . '/DGV7.0-SAAS/' . $rel) === file_get_contents($root . '/DGV7.0-NON-SAAS/' . $rel),
        "edition parity for $rel"
    );
}

// 2. Source contracts across both editions
foreach ($editions as $ed) {
    $b = $root . '/' . $ed;

    // /web widget scope fix + Hub support section
    $app = file_get_contents($b . '/func/bc-plate-app.php');
    schk(strpos($app, 'global $get_logged_user_details, $connection_server;') !== false, "$ed bc_app_widgets declares globals for chat widget");
    schk(strpos($app, "'label' => 'Support', 'href' => 'Hub.php?g=settings-help#bcSupportSection'") !== false, "$ed menu places Support in Settings & Help");
    schk(strpos($app, "'AI-Assistant.php'") === false, "$ed menu no longer references AI-Assistant.php");

    $widget = file_get_contents($b . '/func/bc-support-chat-widget.php');
    schk(strpos($widget, "\$GLOBALS['get_logged_user_details']") !== false, "$ed widget falls back to GLOBALS user");
    schk(strpos($widget, "\$GLOBALS['connection_server']") !== false, "$ed widget falls back to GLOBALS connection");
    schk(strpos($widget, 'window.bcChatOpen') !== false, "$ed widget exposes window.bcChatOpen");
    schk(strpos($widget, 'bc_chat_ensure_schema') !== false, "$ed widget auto-ensures chat schema if missing");

    $hub = file_get_contents($b . '/web/Hub.php');
    schk(strpos($hub, "\$hub_slug === 'settings-help'") !== false, "$ed Hub.php handles settings-help support section");
    schk(strpos($hub, 'id="bcSupportSection"') !== false, "$ed Hub.php renders bcSupportSection");
    schk(strpos($hub, 'data-bc-open-chat') !== false, "$ed Hub.php renders working support chat buttons");
    schk(strpos($hub, 'window.bcChatOpen') !== false, "$ed Hub.php buttons invoke window.bcChatOpen");

    $old = file_get_contents($b . '/web/AI-Assistant.php');
    schk(strpos($old, 'Hub.php?g=settings-help') !== false && strpos($old, '<html') === false, "$ed AI-Assistant.php redirects to Hub.php?g=settings-help");

    $cat = file_get_contents($b . '/func/bc-mobile-catalogue.php');
    schk(strpos($cat, "Hub.php?g=settings-help") !== false, "$ed mobile catalogue routes assistant to Hub.php?g=settings-help");

    // bc-admin support chat quick-access widget (no VTU purchases)
    $adm = file_get_contents($b . '/func/bc-plate-admin.php');
    schk(strpos($adm, '__bc_admin_chat') !== false, "$ed bc-admin widgets configure __bc_admin_chat");
    schk(strpos($adm, 'ai-handler.php') === false, "$ed bc-admin shell drops retired VTU ai-handler.php");

    $js = file_get_contents($b . '/jsfile/ai-assistant.js');
    schk(strpos($js, 'bcAdmChat-launcher') !== false, "$ed ai-assistant.js renders bcAdmChat launcher");
    schk(strpos($js, 'bcAdmChat-panel') !== false, "$ed ai-assistant.js renders bcAdmChat panel");
    schk(strpos($js, "action: \"reply\"") !== false && strpos($js, "action: \"queue\"") !== false, "$ed ai-assistant.js talks to bc-admin support chat actions");
    foreach (array('Buy Airtime', 'Buy Data', 'Pay Utility', 'Buy Exam Pin', 'ai-handler.php', 'AI-Assistant.php') as $retired) {
        schk(strpos($js, $retired) === false, "$ed ai-assistant.js retires '$retired'");
    }

    $admAjax = file_get_contents($b . '/bc-admin/ajax-support-chat.php');
    schk(strpos($admAjax, "'open_threads'") !== false, "$ed bc-admin ajax-support-chat exposes open_threads");
    schk(strpos($admAjax, 'bc_chat_ensure_schema') !== false, "$ed bc-admin ajax-support-chat ensures schema");
}

// 3. Runtime scope test: bc_app_widgets() MUST render #bcChat-launcher when user + chat_enabled=1
if (!class_exists('mysqli')) {
    class mysqli {}
    function mysqli_query($conn, $sql) {
        if (stripos($sql, 'SHOW TABLES LIKE') !== false) return new class { public $n = 1; };
        if (stripos($sql, 'FROM sas_settings') !== false) {
            return new class {
                public $rows = array(array('setting_name' => 'chat_enabled', 'setting_value' => '1'));
                public $i = 0;
            };
        }
        return false;
    }
    function mysqli_num_rows($res) { return is_object($res) && isset($res->n) ? $res->n : 0; }
    function mysqli_fetch_assoc($res) {
        if (!is_object($res) || !isset($res->rows)) return null;
        if ($res->i >= count($res->rows)) return null;
        return $res->rows[$res->i++];
    }
    function mysqli_real_escape_string($conn, $s) { return addslashes((string)$s); }
}

if (!function_exists('isServiceEnabled')) {
    function isServiceEnabled($s, $v = 0) { return true; }
}

require_once $root . '/DGV7.0-SAAS/func/bc-plate-app.php';
$GLOBALS['connection_server'] = new mysqli();
$GLOBALS['get_logged_user_details'] = array('id' => 11, 'vendor_id' => 7, 'username' => 'ada');
$GLOBALS['web_http_host'] = 'https://example.test';
ob_start();
bc_app_widgets();
$widgetHtml = ob_get_clean();
schk(strpos($widgetHtml, 'id="bcChat-launcher"') !== false, 'runtime bc_app_widgets() renders #bcChat-launcher');
schk(strpos($widgetHtml, 'id="bcChat-panel"') !== false, 'runtime bc_app_widgets() renders #bcChat-panel');
schk(strpos($widgetHtml, 'window.bcChatOpen') !== false, 'runtime bc_app_widgets() defines window.bcChatOpen');

// 4. Runtime mobile catalogue destination check (strips query string before is_file)
require_once $root . '/DGV7.0-SAAS/func/bc-mobile-catalogue.php';
schk(bc_mobile_destination('assistant', 7) === '/web/Hub.php?g=settings-help', 'bc_mobile_destination resolves assistant to /web/Hub.php?g=settings-help');

echo "support_chat_widget_fix_test: $checks checks, $failures failures\n";
exit($failures > 0 ? 1 : 0);
