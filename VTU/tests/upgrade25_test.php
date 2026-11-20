<?php
/**
 * DGV7 §25 contract checks — Dashboard redesign, two-tier titles, provider-dynamic
 * services, Flutterwave-only virtual cards, free AI+human support, referral engine
 * and the client-template landing. Static/source-level: runs under `php -n` with no
 * database, mirroring the other suites.
 */

$checks = 0;
$failures = 0;
function u25($cond, $label) {
    global $checks, $failures;
    $checks++;
    if (!$cond) { $failures++; echo "FAIL: $label\n"; }
}
function src($path) {
    $c = file_get_contents($path);
    if ($c === false) throw new RuntimeException('Cannot read ' . $path);
    return $c;
}

$root = dirname(__DIR__);
$editions = array('DGV7.0-SAAS', 'DGV7.0-NON-SAAS');

foreach ($editions as $ed) {
    $b = $root . '/' . $ed;

    // ── Shell: footer clears the fixed sidebar; menu carries the new services ──
    $shell = src($b . '/func/bc-plate-app.php');
    u25(strpos($shell, 'margin-left: var(--side-w);') !== false, "$ed footer is offset from the fixed sidebar (no footer leak)");
    u25(strpos($shell, "'href' => 'Referral.php'") !== false, "$ed nav links the dedicated Referral page under Tools & API");
    u25(strpos($shell, "'label' => 'Support', 'href' => 'AI-Assistant.php'") !== false, "$ed nav renames AI-Assistant to Support");
    u25(strpos($shell, 'AISuite.php') === false, "$ed nav drops the retired AI Suite");
    u25(strpos($shell, 'ai-assistant.js') === false, "$ed shell no longer injects the VTU-purchase AI widget");
    foreach (array('IntlTransfer.php', 'Tuition.php', 'ESIM.php', 'POSRequest.php') as $pg) {
        u25(strpos($shell, "'" . $pg . "'") !== false, "$ed nav links $pg");
    }
    u25(strpos($shell, "bc_app_url('Referral.php')") !== false, "$ed topbar Refer button opens the Referral page");

    // ── Dashboard: removals, hub text links, readable wallet actions, new stats ──
    $dash = src($b . '/web/Dashboard.php');
    foreach (array('Meet Your AI Assistant', 'Autonomous AI', 'Refer and Earn</div>', 'Upgrade Account', 'handle_upgrade.php', 'AISuite.php') as $gone) {
        u25(strpos($dash, $gone) === false, "$ed Dashboard drops '$gone'");
    }
    foreach (array('Hub.php?g=pay-bills', 'Hub.php?g=wallet-transfers', 'Hub.php?g=verify-print', 'PrintHub.php', 'Hub.php?g=tools-api', 'Hub.php?g=history-tools', 'Hub.php?g=settings-help') as $link) {
        u25(strpos($dash, $link) !== false, "$ed Dashboard All Services links $link");
    }
    u25(strpos($dash, 'row row-cols-2 row-cols-sm-3 row-cols-lg-4') !== false && substr_count($dash, 'class="svc-tile"') >= 7 && strpos($dash, 'list-group-item') === false, "$ed Dashboard All Services renders as a responsive tile grid, not a list");
    u25(strpos($dash, '<span>Fund</span>') !== false && strpos($dash, '<span>Crypto</span>') !== false, "$ed Dashboard wallet actions render icon + text labels");
    u25(strpos($dash, 'All money in') !== false && strpos($dash, 'All money out') !== false, "$ed Dashboard redesigned Total Deposit / Total Spent cards");
    u25(strpos($dash, 'Send Money Abroad') !== false && strpos($dash, 'Pay Tuition Worldwide') !== false && strpos($dash, 'Travel eSIM') !== false, "$ed Dashboard features the new services");
    u25(strpos($dash, 'Crypto Hub') !== false && strpos($dash, 'Buy, sell &amp; swap crypto') !== false, "$ed Dashboard Crypto Hub feature card");
    u25(strpos($dash, 'Virtual Dollar Card') !== false && strpos($dash, 'Global payments, simplified') !== false, "$ed Dashboard Virtual Dollar Card feature card");
    u25(strpos($dash, 'Gift Cards') !== false && strpos($dash, 'Buy &amp; trade gift cards') !== false, "$ed Dashboard Gift Cards feature card");

    // ── Provider service layer ──
    $svc = src($b . '/func/bc-fintech-services.php');
    u25(strpos($svc, "'klasha'") !== false && strpos($svc, "'flutterwave'") !== false && strpos($svc, "'platnova'") !== false, "$ed provider catalogue covers Klasha/Flutterwave/Platnova");
    u25(strpos($svc, "'china_payout'") !== false, "$ed Klasha catalogue includes Payout to China");
    u25(strpos($svc, "'pos_request'") !== false, "$ed Flutterwave catalogue includes POS requests");
    u25(strpos($svc, "'btc_swap' => false") !== false, "$ed Klasha/Flutterwave BTC swap checked: not offered, pairs hidden");
    u25(strpos($svc, "bc_referral_record_volume") !== false && strpos($svc, "'crypto', 'intl_transfer'") !== false || strpos($svc, "return ['crypto', 'intl_transfer'];") !== false, "$ed referral volume counts Crypto & International Transfer only");
    u25(strpos($svc, 'qualifying_usd') !== false && strpos($svc, '100.00') !== false, "$ed referral threshold is \$100");
    u25(strpos($svc, 'sas_creator_applications') !== false, "$ed creator/influencer applications are stored");

    // ── New pages ──
    foreach (array('Referral.php', 'IntlTransfer.php', 'Tuition.php', 'ESIM.php', 'POSRequest.php') as $pg) {
        $page = src($b . '/web/' . $pg);
        u25(strpos($page, 'bc_app_sidebar(') !== false, "$ed $pg uses the app shell");
        u25(strpos($page, 'bc-fintech-services.php') !== false, "$ed $pg loads the provider layer");
    }
    $ref = src($b . '/web/Referral.php');
    u25(strpos($ref, 'You get $10. Your friend gets $10.') !== false, "$ed Referral page shows the \$10/\$10 diagram copy");
    u25(strpos($ref, 'apply_creator') !== false, "$ed Referral page has the creator application form");
    $intl = src($b . '/web/IntlTransfer.php');
    u25(strpos($intl, 'Payout to China in 3 steps') !== false, "$ed IntlTransfer visualises the 3-step China workflow");
    u25(strpos($intl, 'bc_svc_provider_services') !== false, "$ed IntlTransfer renders exactly the active provider's services");

    // ── Crypto adapts to the active provider ──
    $crypto = src($b . '/web/CryptoHub.php');
    u25(strpos($crypto, 'bc_svc_active_provider') !== false, "$ed Crypto reads the active provider");
    u25(strpos($crypto, 'crypto_provider_mode') !== false, "$ed Crypto has a provider mode that hides the legacy rail");
    u25(strpos($crypto, 'bc_referral_record_volume') !== false, "$ed completed crypto swaps feed referral volume");

    // ── Virtual card: Flutterwave ONLY ──
    $vc = src($b . '/func/bc-virtualcard-flutterwave.php');
    u25(strpos($vc, "bc_fintech_provider_request") !== false && strpos($vc, "'flutterwave'") !== false, "$ed virtual cards issue via the Flutterwave seam");
    u25(strpos($vc, 'issueVirtualCardFlutterwave') !== false && strpos($vc, 'fundVirtualCardFlutterwave') !== false, "$ed Flutterwave card adapter exposes issue/fund");
    foreach (array('web/VirtualCard.php', 'web/virtual-card-ajax.php', 'web/api/virtual-card.php', 'bc-admin/VirtualCard.php') as $f) {
        $s = src($b . '/' . $f);
        u25(strpos($s, 'bsicards.php') === false && strpos($s, 'BSICards(') === false && strpos($s, 'issueVirtualCardBSICards') === false, "$ed $f has no BSICards integration left");
        u25(strpos($s, 'issueVirtualCard(') === false && strpos($s, 'fundVirtualCardV2(') === false, "$ed $f calls no retired card adapter");
        u25(strpos($s, 'bc-virtualcard-flutterwave.php') !== false, "$ed $f loads the Flutterwave card adapter");
    }
    u25(!file_exists($b . '/func/api-gateway/bsicards.php'), "$ed BSICards gateway file removed");
    u25(!file_exists($b . '/includes/BSICardsBridge.php'), "$ed BSICards bridge removed");
    $chimoney = src($b . '/func/api-gateway/chimoney.php');
    u25(strpos($chimoney, 'function issueVirtualCard(') === false && strpos($chimoney, 'function getChimoneyDetails(') !== false, "$ed Chimoney keeps only the FX lookup; card functions removed");

    // ── Support: free, AI-first, ticketed, counted ──
    $support = src($b . '/web/AI-Assistant.php');
    u25(strpos($support, 'FREE SUPPORT') !== false, "$ed Support page states support is free");
    u25(strpos($support, 'ticket') !== false, "$ed Support page shows the user's ticket");
    u25(strpos($support, 'AI Assistant Terminal') === false && strpos($support, 'Buy 1GB MTN') === false, "$ed Support page drops the VTU-purchase terminal");
    $chat = src($b . '/func/bc-support-chat.php');
    u25(strpos($chat, 'ticket_no') !== false && strpos($chat, "'SUP-'") !== false, "$ed chat threads carry SUP- ticket numbers");
    u25(strpos($chat, 'bc_chat_context_provider') !== false, "$ed chat engine accepts account context via the web layer seam");
    u25(!preg_match('/(sas_transactions|UPDATE sas_users SET balance)/', $chat), "$ed chat engine itself stays money-blind");
    $ajax = src($b . '/web/ajax-support-chat.php');
    u25(strpos($ajax, 'bc_chat_user_context') !== false, "$ed support AI can inspect the customer's recent transactions");
    $widget = src($b . '/func/bc-support-chat-widget.php');
    u25(strpos($widget, 'unreadCount') !== false, "$ed chat widget shows a live unread response count");
    $adminChat = src($b . '/bc-admin/SupportChat.php');
    u25(strpos($adminChat, "ticket_no") !== false, "$ed admin support queue shows ticket numbers");

    // ── Two-tier titles ──
    $bcfunc = src($b . '/func/bc-func.php');
    u25(strpos($bcfunc, '1 => "Personal", 2 => "Personal (Agent legacy)", 3 => "Business"') !== false, "$ed account levels display as Personal/Business");
    $pricing = src($b . '/web/Pricing.php');
    u25(strpos($pricing, 'Personal') !== false && strpos($pricing, 'Business') !== false, "$ed Pricing page uses Personal/Business titles");
    u25(strpos($pricing, 'Smart User') === false && strpos($pricing, 'Agent Vendor') === false && strpos($pricing, 'API Vendor') === false, "$ed Pricing page drops Smart/Agent/API titles");
    $docs = src($b . '/web/APIDocs.php');
    u25(strpos($docs, 'API Vendor') === false, "$ed API docs use Business wording");

    // ── Service control: toggles + active provider switch ──
    $sc = src($b . '/bc-admin/ServiceControl.php');
    foreach (array('intl_transfer', 'tuition', 'esim', 'pos_request') as $slug) {
        u25(strpos($sc, "'" . $slug . "'") !== false, "$ed ServiceControl toggles $slug");
    }
    u25(strpos($sc, 'save-active-provider') !== false && strpos($sc, 'fintech_active_provider') !== false, "$ed ServiceControl switches the active provider");

    // ── Landing: client template structure, automated branding, China visual ──
    $tpl = src($b . '/index-bc-style-template-1.php');
    u25(strpos($tpl, 'MzeeVTU') === false, "$ed landing has no hardcoded client brand");
    u25(strpos($tpl, '--brand-red:<?php echo htmlspecialchars($brand_colour') !== false, "$ed landing brand colour comes from bc-admin primary colour");
    u25(strpos($tpl, 'id="who"') !== false && strpos($tpl, 'id="receive"') !== false && strpos($tpl, 'id="manage"') !== false && strpos($tpl, 'id="currencies"') !== false && strpos($tpl, 'id="spend"') !== false && strpos($tpl, 'id="faq"') !== false && strpos($tpl, 'id="signup"') !== false, "$ed landing keeps the client template section map");
    u25(strpos($tpl, 'floating-subnav') !== false, "$ed landing keeps the floating subnav");
    u25(strpos($tpl, 'STEP 01 · WALLET PROVISIONING') !== false && strpos($tpl, 'STEP 03 · CHINA SETTLEMENT') !== false, "$ed landing embosses the 3-step China payout workflow");
    u25(strpos($tpl, 'Refer &amp; earn $10 + $10') !== false, "$ed landing advertises the \$10/\$10 referral program");
    u25(strpos($tpl, '$lp_fx_live') !== false, "$ed landing keeps the gated live FX converter");
    u25(strpos($tpl, 'web/Register.php') !== false && strpos($tpl, 'web/Login.php') !== false, "$ed landing CTAs point at the real auth pages");
}

// Template 6 remains untouched by §25 (CI-pinned byte-identical across editions).
u25(file_get_contents($root . '/DGV7.0-SAAS/index-bc-style-template-6.php') === file_get_contents($root . '/DGV7.0-NON-SAAS/index-bc-style-template-6.php'),
    'landing template 6 stays byte-identical across editions');

echo "upgrade25_test: $checks checks, $failures failures\n";
exit($failures > 0 ? 1 : 0);
