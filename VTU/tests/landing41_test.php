<?php
/**
 * §41 — public landing pages (Refer & Earn + per-service pages), landing menu
 * links, and the live bc-admin support chat.
 *
 * The referral page must be driven by the vendor's real programme terms
 * (bc_referral_settings + coin settings), never hard-coded numbers, so the
 * marketing copy cannot drift from the money rules. The admin chat must poll
 * the agent endpoint (queue/thread/reply) instead of reloading per reply.
 */
chdir(dirname(__DIR__));
$fail = 0;
function chk($cond, $label) { global $fail; echo ($cond ? 'PASS ' : 'FAIL ') . $label . PHP_EOL; if (!$cond) $fail++; }

$roots = ['SAAS' => 'DGV7.0-SAAS', 'NON-SAAS' => 'DGV7.0-NON-SAAS'];
$slugs = ['airtime','data','cable-tv','electricity','exam-pins','betting','gift-cards','crypto','international-transfer','virtual-cards','esim','bulk-sms'];

foreach ($roots as $name => $root) {
    $landing = file_get_contents($root . '/func/bc-landing-pages.php');
    chk(strpos($landing, 'function bc_landing_services') !== false, "$name: service catalogue defined");
    chk(strpos($landing, 'bc_referral_settings') !== false && strpos($landing, 'bc_get_coin_settings') !== false,
        "$name: referral page reads live programme terms (reward/qualifying/coins)");
    chk(strpos($landing, 'function bc_landing_render_referral') !== false, "$name: referral renderer exists");

    foreach (['ReferAndEarn.php', 'Services.php', 'ServicePage.php'] as $page) {
        chk(is_file($root . '/' . $page), "$name: $page exists");
    }
    foreach ($slugs as $slug) {
        $w = $root . '/services/' . $slug . '.php';
        chk(is_file($w) && strpos(file_get_contents($w), "\$bc_service_slug = '$slug';") !== false, "$name: dedicated page services/$slug.php");
        chk(strpos($landing, "'$slug' => array(") !== false, "$name: catalogue has '$slug'");
    }

    foreach ([1, 2, 3, 6] as $t) {
        $tpl = file_get_contents($root . "/index-bc-style-template-$t.php");
        chk(strpos($tpl, 'ReferAndEarn.php') !== false && strpos($tpl, 'Services.php') !== false,
            "$name template $t: menu links Services + Refer & Earn");
    }

    /* live admin chat */
    $ajax = file_get_contents($root . '/bc-admin/ajax-support-chat.php');
    foreach (['queue', 'thread', 'reply', 'claim', 'close', 'pause_ai'] as $act) {
        chk(strpos($ajax, "\$action === '$act'") !== false, "$name admin chat endpoint: action '$act'");
    }
    chk(strpos($ajax, 'bc_chat_post_message($vid, $thread_id, \'agent\'') !== false, "$name admin chat endpoint: replies go through the chat engine");
    $console = file_get_contents($root . '/bc-admin/SupportChat.php');
    chk(strpos($console, 'id="bcLiveMsgs"') !== false, "$name SupportChat: live message pane");
    chk(strpos($console, "fetch('ajax-support-chat.php'") !== false && strpos($console, 'setInterval(poll, 4000)') !== false, "$name SupportChat: polls + AJAX reply (no reload per message)");
    chk(strpos($console, 'id="bcReplyForm"') !== false, "$name SupportChat: reply form wired for AJAX (POST fallback intact)");
}

echo PHP_EOL . ($fail === 0 ? 'landing41: all checks passed' : "landing41: $fail FAILURES") . PHP_EOL;
exit($fail === 0 ? 0 : 1);
