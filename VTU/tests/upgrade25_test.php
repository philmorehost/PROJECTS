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
    // §28: swaps fail closed through the provider gate, so nothing completes and no
    // referral volume can be recorded from this page. The referral engine still only
    // counts COMPLETED crypto + intl_transfer volume (pinned in bc-fintech-services).
    u25(strpos($crypto, 'bc_referral_record_volume') === false, "$ed refused crypto swaps record no referral volume");

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

// ═══════════════ §28: commercialised APIs, portal, plisio removal, chat ═══════════════
// Edition markers: SAAS tree says saas, NON-SAAS says non-saas (deliberately NOT parity files).
u25(strpos(src($root . '/DGV7.0-SAAS/func/bc-edition.php'), "'saas'") !== false, 'SAAS edition marker');
u25(strpos(src($root . '/DGV7.0-NON-SAAS/func/bc-edition.php'), "'non-saas'") !== false, 'NON-SAAS edition marker');

foreach ($editions as $ed) {
    $b = $root . '/' . $ed;

    // Commercialised fintech API access layer: three providers, spadmin price, vendor grant.
    $acc = src($b . '/func/bc-fintech-api-access.php');
    foreach (array('flutterwave', 'klasha', 'platnova') as $slug) {
        u25(strpos($acc, "'$slug'") !== false, "$ed API access covers $slug");
    }
    u25(strpos($acc, 'fintech_api_price_') !== false && strpos($acc, '50000') !== false, "$ed API access defaults to the 50000 price point");
    u25(strpos($acc, 'fintech_api_access_') !== false, "$ed API access reads vendor grants");
    u25(strpos($acc, 'bc_is_saas_edition') !== false, "$ed API access is edition-aware (NON-SAAS included by default)");

    // Payment Gateway page: all three APIs purchasable/locked; spadmin prices them.
    $pg = src($b . '/bc-admin/PaymentGateway.php');
    u25(strpos($pg, 'buy-fintech-api') !== false, "$ed gateway page sells fintech API access");
    u25(strpos($pg, "array('flutterwave', 'platnova')") !== false, "$ed gateway cards lock unpurchased APIs");
    u25(strpos($pg, '"platnova"') !== false, "$ed gateway page lists Platnova");

    // Vendor portal: subscription + domain-change request flow exists in bc-admin.
    $portal = src($b . '/bc-admin/VendorPortal.php');
    u25(strpos($portal, 'sas_vendor_domain_requests') !== false, "$ed portal files domain change requests");
    u25(strpos($portal, 'submit_domain_request') !== false, "$ed portal submits domain requests");

    // Admin shell: Portal topbar action; dashboard no longer carries dead REFRESH/SYNC.
    $adm = src($b . '/func/bc-plate-admin.php');
    u25(strpos($adm, 'VendorPortal.php') !== false, "$ed admin topbar links the vendor portal");
    $adash = src($b . '/bc-admin/Dashboard.php');
    u25(strpos($adash, '"REFRESH"') === false && strpos($adash, '"SYNC"') === false, "$ed admin dashboard drops REFRESH/SYNC");
    u25(strpos($adash, 'ad2-hero__actions') !== false, "$ed admin dashboard carries the v3 hero actions");

    // Brand colour cache now invalidates on save.
    $cfg = src($b . '/func/bc-config.php');
    u25(strpos($cfg, 'brand_style_cache_ver') !== false, "$ed brand colour cache is version-checked");
    u25(strpos(src($b . '/bc-admin/AccountSettings.php'), 'brand_style_cache_ver') !== false, "$ed branding save busts the colour cache");

    // Readable brand-on-brand text: foreground tokens computed from luminance.
    $app = src($b . '/func/bc-plate-app.php');
    u25(strpos($app, '--brand-fg') !== false && strpos($app, '--brand-ink') !== false, "$ed shell computes brand foreground/ink tokens");
    u25(strpos($app, "'service' => 'referral'") !== false, "$ed Refer & Earn honours the referral service toggle");

    // Chat widget redesign: avatar, quick replies configurable from bc-admin.
    $chat = src($b . '/func/bc-support-chat-widget.php');
    u25(strpos($chat, 'bcChat-avatar') !== false && strpos($chat, 'bcChat-quick') !== false, "$ed chat widget has avatar + quick replies");
    u25(strpos($chat, 'bcChat-ico-close') !== false, "$ed chat launcher swaps to a close icon");
    u25(strpos(src($b . '/bc-admin/ChatSettings.php'), 'chat_quick_replies') !== false, "$ed bc-admin configures chat quick replies");
    u25(strpos(src($b . '/func/bc-support-chat-schema.php'), 'chat_quick_replies') !== false, "$ed chat settings persist quick replies");

    // Marketplace: eSIM filter chip + SAAS-only custom gateway listing.
    $mkt = src($b . '/bc-admin/MarketPlace.php');
    u25(strpos($mkt, '"esim" => "eSIM"') !== false, "$ed marketplace filters include eSIM");
    u25(strpos($mkt, 'bc_is_saas_edition') !== false && strpos($mkt, 'Custom gateway API') !== false, "$ed marketplace custom-gateway listing is SAAS-gated");

    // Plisio total removal on customer paths; retirement spine preserved.
    $chub = src($b . '/web/CryptoHub.php');
    u25(strpos($chub, 'createPlisioInvoice') === false && strpos($chub, 'plisioCashOut') === false && strpos($chub, 'getPlisio') === false, "$ed crypto hub calls no retired provider");
    $api = src($b . '/web/api/crypto.php');
    $apiActionAt = strpos($api, "\$action = \$input['action']");
    $apiGuardAt  = strpos($api, "bc_gateway_is_retired('plisio')");
    u25($apiActionAt !== false && $apiGuardAt !== false && $apiActionAt < $apiGuardAt, "$ed crypto API defines the action before the retired guard (guard is live)");
    u25(strpos($api, 'createPlisioInvoice') === false, "$ed crypto API creates no retired-provider invoices");
    $hook = src($b . '/users-plisio.php');
    u25(strpos($hook, '410') !== false, "$ed retired webhook still answers 410 for reconciliation");
    u25(strpos(src($b . '/func/bc-crypto-func.php'), 'RECONCILIATION') !== false || strpos(src($b . '/func/bc-crypto-func.php'), 'reconciled') !== false, "$ed legacy crypto helpers are reconciliation-only");
}

// Super-admin surface (SAAS only): fintech API prices, no crypto console.
$spg = src($root . '/DGV7.0-SAAS/bc-spadmin/PaymentGateway.php');
u25(strpos($spg, 'fintech_api_price_$fintech_slug') !== false && strpos($spg, "'flutterwave', 'klasha', 'platnova'") !== false, 'spadmin prices all three fintech APIs');
u25(strpos($spg, 'Fintech API Access Prices') !== false, 'spadmin shows fintech API price controls');
u25(!file_exists($root . '/DGV7.0-SAAS/bc-spadmin/CryptoHub.php'), 'spadmin crypto console is deleted');
u25(strpos(src($root . '/DGV7.0-SAAS/bc-spadmin/SiteMap.php'), "CryptoHub.php'") === false, 'spadmin sitemap drops the crypto console link');
$spUsers = src($root . '/DGV7.0-SAAS/bc-spadmin/Users.php');
u25(strpos($spUsers, 'data-bs-toggle="dropdown"') !== false, 'spadmin users keep the Manage dropdown markup');

// Template 6 remains untouched by §25 (CI-pinned byte-identical across editions).
u25(file_get_contents($root . '/DGV7.0-SAAS/index-bc-style-template-6.php') === file_get_contents($root . '/DGV7.0-NON-SAAS/index-bc-style-template-6.php'),
    'landing template 6 stays byte-identical across editions');

echo "upgrade25_test: $checks checks, $failures failures\n";
exit($failures > 0 ? 1 : 0);
