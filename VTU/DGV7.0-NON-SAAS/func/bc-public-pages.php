<?php
/** Shared, read-only public directory. No financial routes are enabled here. */
if (!function_exists('bc_public_catalog')) {
function bc_public_catalog() {
    return array(
        'bills' => array('Bills & airtime', '/BillsAndAirtime.php', 'Everyday essentials in one place.', 'Explore airtime, data bundles, cable TV, electricity and exam PIN services. Choose a service to see its dedicated guide and account entry point.', 'Verify the phone, meter, smartcard or other beneficiary details before payment. Keep the transaction reference for support and status checks.', '/Services.php', 'Browse bill payment services'),
        'wallet' => array('Multi-currency wallet', '/MultiCurrencyWallet.php', 'Keep your money organised across currencies.', 'View the currencies and balances available to your verified account in one place. Availability depends on your country, account tier and enabled providers.', 'Review the currency, conversion quote and fees before confirming. Marketing examples are not exchange-rate guarantees.', '/web/Dashboard.php', 'Open my wallet'),
        'savings' => array('Savings', '/Savings.php', 'Build a habit around your next goal.', 'Separate everyday spending from future plans and review the money you can safely set aside.', 'Savings availability and any applicable terms must be confirmed in your account. No interest rate, deposit insurance or investment return is promised here.', '/web/Dashboard.php', 'Review my account'),
        'business' => array('Business account', '/Business.php', 'One place for your business money.', 'Explore invoicing, incoming payments and payment records for your business. Business verification and service eligibility apply.', 'Complete the requested business checks before using business-only features. Limits, currencies and charges are shown in your account.', '/web/BusinessUpgrade.php', 'Explore business access'),
        'payment-links' => array('Payment links', '/PaymentLinks.php', 'Give your customer a clear way to pay.', 'Create a payment request through the Receive workflow and share the generated link with your customer.', 'Confirm the beneficiary, amount and currency. A shared link is a request, not proof of settlement; rely on your transaction history for payment status.', '/web/Receive.php', 'Create a payment request'),
        'invoices' => array('Invoices', '/Invoices.php', 'Send professional payment requests.', 'Use Receive to prepare an invoice and share it by email or link. Keep the reference with your customer records.', 'Only verified, completed payments count as settled. Pending, failed or disputed payments require review before delivering goods or issuing refunds.', '/web/Receive.php', 'Create an invoice'),
        'payouts' => array('Payouts', '/Payouts.php', 'Understand your outgoing payment options.', 'Review supported transfer destinations, recipient details and account limits before requesting a payout.', 'Payouts may be paused or unavailable while security checks, provider readiness or reconciliation are pending. This page does not enable transfers or bypass existing holds.', '/web/IntlTransfer.php', 'Review transfer options'),
        'about' => array('About us', '/AboutUs.php', 'Everyday payments, thoughtfully connected.', 'Our platform brings everyday bills and eligible digital-money services into a single account, with transaction records and support close at hand.', 'Services vary by location, verification level and provider availability. Consult the Terms and your account for the current service conditions.', '/Services.php', 'Explore services'),
        'careers' => array('Careers', '/Careers.php', 'Help improve everyday money experiences.', 'We value careful engineering, clear communication and customer-focused operations.', 'No vacancies are advertised on this page at present. Contact support to ask about current opportunities. Never pay a recruitment fee or share your PIN or one-time codes with a recruiter.', '/Contact.php', 'Contact the team'),
        'contact' => array('Contact', '/Contact.php', 'Get help with your account or a transaction.', 'Sign in and open support chat to contact the support team. For a transaction question, keep its reference, date and amount ready.', 'Never send your password, PIN, authenticator secret, full card number or CVV. If you cannot sign in, use the recovery options on the login page.', '/web/Login.php', 'Sign in for support'),
        'cookies' => array('Cookie policy', '/CookiePolicy.php', 'How browser storage supports the website.', 'Essential cookies keep your signed-in session and security checks working. Some pages use browser storage to remember interface choices, such as search focus or an app-prompt dismissal.', 'You can clear cookies and browser storage in your browser settings. Doing so may sign you out or reset preferences. External font or other third-party services may receive normal connection data; see the Privacy Policy for the wider data notice.', '/Privacy.php', 'Read the Privacy Policy'),
        'pos' => array('POS terminals', '/POSTerminals.php', 'Explore in-person payment acceptance.', 'Review terminal options and submit an enquiry through your account where the POS service is available.', 'Terminal availability, onboarding, pricing and settlement arrangements require confirmation. An enquiry is not approval or a guaranteed delivery date.', '/web/POSRequest.php', 'Enquire about a terminal'),
        'tuition' => array('Tuition payments', '/Tuition.php', 'Prepare your education payment carefully.', 'Check the institution, student details, invoice currency and available payment route before submitting a tuition request.', 'Institutions shown in marketing illustrations are not endorsements or guaranteed supported destinations. Confirm eligibility, fees and processing estimates in your account.', '/web/Tuition.php', 'Review tuition options'),
        'download' => array('Download the app', '/DownloadApp.php', 'Your account, on the move.', 'Use the published download destination configured by this website’s operator. You can also access your account in your browser.', 'Only install from a destination you trust. If a platform-specific app is not published, use the web console rather than an unofficial download.', '/web/Login.php', 'Continue in browser'),
        'developers' => array('Developers & API', '/Developers.php', 'Programmatic VTU, bills, and payout integrations.', 'Integrate airtime, data bundles, utility payments, recipient validation, and payouts using server-side API keys and webhook signatures.', 'Keep API secrets on your server, use idempotency keys for purchase retries, and verify service availability in your business console.', '/web/Login.php', 'Sign in for API keys'),
        'security' => array('Security & trust', '/Security.php', 'Multi-layer controls protecting your wallet and records.', 'Protect sign-in and sensitive actions with security PINs, Google Authenticator or email OTP verification, KYC checks, and auditable session controls.', 'Never share your password, PIN, authenticator code, or full card details. Official support will never ask for one-time passcodes.', '/Contact.php', 'Contact support'),
        'resellers' => array('Merchant & resellers', '/Resellers.php', 'Branded storefronts, POS requests, bulk SMS, and print hubs.', 'Run a VTU and digital services business with tiered reseller pricing, printable recharge & data cards, bulk SMS sender IDs, and POS enquiries.', 'Service availability, pricing tiers, and terminal onboarding depend on account verification and Site Owner Service Control settings.', '/web/BusinessUpgrade.php', 'Explore reseller access'),
    );
}
function bc_public_directory() {
    return array_merge(array(
        array('Home', '/'), array('Freelancers', '/Freelancers.php'), array('All services', '/Services.php'),
        array('International transfers', '/services/international-transfer.php'), array('Cards', '/services/virtual-cards.php'),
        array('Bills & airtime', '/BillsAndAirtime.php'), array('Airtime', '/services/airtime.php'), array('Data bundles', '/services/data.php'), array('Cable TV', '/services/cable-tv.php'), array('Electricity', '/services/electricity.php'), array('Exam PINs', '/services/exam-pins.php'), array('Betting', '/services/betting.php'), array('Crypto', '/services/crypto.php'), array('Travel eSIM', '/services/esim.php'), array('Bulk SMS', '/services/bulk-sms.php'), array('Gift cards', '/services/gift-cards.php'),
        array('Refer & Earn', '/ReferAndEarn.php'), array('Convert coins', '/services/CoinConversion.php'),
        array('Blog', '/blog.php'), array('Privacy policy', '/Privacy.php'), array('Terms of use', '/Terms.php'),
        array('Create account', '/web/Register.php'), array('Log in', '/web/Login.php'), array('Delete account', '/web/DeleteAccount.php'),
    ), array_map(function ($p) { return array($p[0], $p[1]); }, array_values(bc_public_catalog())));
}
function bc_public_directory_groups() {
    // Labels remain sourced from the canonical directory; each URL appears once.
    $routes = array(
        'Discover' => array('/', '/Services.php', '/Freelancers.php', '/MultiCurrencyWallet.php', '/Savings.php', '/DownloadApp.php'),
        'Money & cards' => array('/services/international-transfer.php', '/services/virtual-cards.php', '/services/crypto.php', '/services/gift-cards.php', '/services/esim.php', '/Tuition.php'),
        'Bills & utilities' => array('/BillsAndAirtime.php', '/services/airtime.php', '/services/data.php', '/services/cable-tv.php', '/services/electricity.php', '/services/exam-pins.php', '/services/betting.php', '/services/bulk-sms.php'),
        'For business' => array('/Business.php', '/PaymentLinks.php', '/Invoices.php', '/Payouts.php', '/POSTerminals.php', '/Developers.php', '/Resellers.php'),
        'Company & rewards' => array('/AboutUs.php', '/Security.php', '/Careers.php', '/blog.php', '/Contact.php', '/ReferAndEarn.php', '/services/CoinConversion.php'),
        'Account & legal' => array('/web/Register.php', '/web/Login.php', '/Privacy.php', '/Terms.php', '/CookiePolicy.php', '/web/DeleteAccount.php'),
    );
    $links = array();
    foreach (bc_public_directory() as $link) $links[$link[1]] = $link;
    $groups = array();
    foreach ($routes as $title => $urls) {
        $groups[$title] = array();
        foreach ($urls as $url) {
            if (isset($links[$url])) { $groups[$title][] = $links[$url]; unset($links[$url]); }
        }
    }
    // Future catalogue additions remain visible rather than being silently dropped.
    if ($links) $groups['More to explore'] = array_values($links);
    return $groups;
}
function bc_public_directory_html() {
    static $styles_printed = false;
    if (!$styles_printed) {
        $styles_printed = true;
        ?>
<style>
.public-directory{display:block;position:static;box-sizing:border-box;flex:1 1 100%;width:100%;max-width:1240px;min-width:0;margin:32px auto 0;padding:28px 24px;border-top:1px solid currentColor;border-top-color:color-mix(in srgb,currentColor 20%,transparent);font-family:inherit;color:inherit;text-align:left}
.public-directory *{box-sizing:border-box}
.public-directory .public-directory__intro{display:flex;align-items:baseline;justify-content:space-between;flex-wrap:wrap;gap:8px 24px;margin:0 0 24px}
.public-directory .public-directory__title{margin:0!important;padding:0;font-family:inherit;font-size:22px!important;font-weight:750;line-height:1.3;letter-spacing:-.02em;color:inherit;text-transform:none}
.public-directory .public-directory__description{margin:0!important;font-size:13px;line-height:1.6;color:inherit}
.public-directory .public-directory__grid{display:grid;grid-template-columns:repeat(6,minmax(0,1fr));gap:26px 20px;align-items:start}
.public-directory .public-directory__group{min-width:0;margin:0;padding:0;text-align:left}
.public-directory .public-directory__heading{margin:0 0 10px!important;font-family:inherit;font-size:13px!important;font-weight:750;line-height:1.5;letter-spacing:.025em;color:inherit;text-transform:none}
.public-directory .public-directory__list{display:flex;flex-direction:column;gap:2px;list-style:none!important;margin:0!important;padding:0!important}
.public-directory .public-directory__list li{display:block;margin:0!important;padding:0!important;list-style:none!important;color:inherit}
.public-directory .public-directory__link{display:flex!important;align-items:center;min-height:40px;max-width:100%;margin:0!important;padding:8px 10px!important;border:0;border-radius:8px;font-family:inherit;font-size:13px!important;font-weight:400;line-height:1.5;color:inherit!important;text-decoration:none!important;overflow-wrap:anywhere;transition:background-color .15s ease}
.public-directory .public-directory__link:hover{background:color-mix(in srgb,currentColor 9%,transparent);text-decoration:underline!important;text-underline-offset:3px}
.public-directory .public-directory__link:focus-visible{outline:2px solid currentColor;outline-offset:2px;text-decoration:underline!important;text-underline-offset:3px}
@media(max-width:1100px){.public-directory .public-directory__grid{grid-template-columns:repeat(3,minmax(0,1fr));gap:28px 24px}}
@media(max-width:600px){.public-directory{padding:24px 0}.public-directory .public-directory__grid{grid-template-columns:repeat(2,minmax(0,1fr));gap:26px 16px}.public-directory .public-directory__intro{display:block}.public-directory .public-directory__description{margin-top:8px!important}.public-directory .public-directory__link{min-height:44px;padding:9px 4px!important}}
@media(max-width:340px){.public-directory .public-directory__grid{grid-template-columns:minmax(0,1fr)}}
@media(prefers-reduced-motion:reduce){.public-directory .public-directory__link{transition:none}}
</style>
<?php
    }
    echo '<nav class="public-directory" aria-label="Explore more"><div class="public-directory__intro"><h2 class="public-directory__title">Explore more</h2><p class="public-directory__description">Find the right service, resources and support.</p></div><div class="public-directory__grid">';
    foreach (bc_public_directory_groups() as $title => $links) {
        echo '<div class="public-directory__group"><h3 class="public-directory__heading">'.htmlspecialchars($title, ENT_QUOTES, 'UTF-8').'</h3><ul class="public-directory__list">';
        foreach ($links as $link) echo '<li><a class="public-directory__link" href="'.htmlspecialchars($link[1], ENT_QUOTES, 'UTF-8').'">'.htmlspecialchars($link[0], ENT_QUOTES, 'UTF-8').'</a></li>';
        echo '</ul></div>';
    }
    echo '</div></nav>';
}
/** No javascript:, protocol-relative or attacker-controlled host redirects. */
function bc_public_safe_url($url) {
    $url = trim((string)$url);
    if ($url === '' || preg_match('/[\x00-\x20\x7f\\\\]/', $url)) return '';
    if (substr($url, 0, 1) === '/' && substr($url, 0, 2) !== '//') return $url;
    $parts = parse_url($url);
    return is_array($parts) && strtolower($parts['scheme'] ?? '') === 'https' && !empty($parts['host']) && empty($parts['user']) && empty($parts['pass']) ? $url : '';
}
function bc_public_download_url($vendor_id) {
    global $connection_server;
    $vid = (int)$vendor_id;
    if ($vid < 1 || !($connection_server instanceof mysqli)) return '';
    $q = mysqli_query($connection_server, "SELECT apk_download_url FROM sas_site_details WHERE vendor_id='$vid' LIMIT 1");
    $row = $q ? mysqli_fetch_assoc($q) : null;
    return bc_public_safe_url($row['apk_download_url'] ?? '');
}
function bc_public_render($ctx, $key) {
    $pages = bc_public_catalog();
    if (!isset($pages[$key])) { http_response_code(404); return; }
    $p = $pages[$key];
    require_once __DIR__ . '/bc-public-meridian.php';
    $biz_keys = array('business', 'payment-links', 'invoices', 'payouts', 'pos', 'developers', 'resellers');
    $world = in_array($key, $biz_keys, true) ? 'business' : 'personal';
    bc_landing_head($ctx, $p[0], $p[2], $world);
    bc_landing_nav($ctx, $key, $world);

    if ($key === 'business') {
        $vid = (int)($ctx['vendor_id'] ?? 0);
        $site_e = bc_landing_e($ctx['site_name']);
        ?>
        <div class="wrap">
            <section class="m-sub-hero">
                <div class="m-page-split">
                    <div>
                        <p class="eyebrow"><i></i><?php echo $site_e; ?> Business · Dark Workspace</p>
                        <h1><?php echo bc_landing_e($p[0]); ?></h1>
                        <p class="lede">One workspace for collections, payouts, POS terminals, and wholesale VTU APIs — gated live by your platform’s Service Control Center.</p>
                        <div style="display:flex;gap:12px;flex-wrap:wrap;margin-top:24px">
                            <a class="btn primary" href="/web/BusinessUpgrade.php">Open business access</a>
                            <a class="btn ghost" href="/Developers.php">View VTU &amp; API guide</a>
                            <a class="btn ghost" href="/web/Register.php">Create account</a>
                        </div>
                        <div class="bz-trust">
                            <span>Payment Links &amp; Invoices</span>
                            <span>Single &amp; Bulk Payouts</span>
                            <span>POS Enquiries</span>
                            <span>VTU Reseller APIs</span>
                        </div>
                        <?php echo bc_meridian_support_bar($ctx); ?>
                    </div>
                    <div class="biz-hero-media">
                        <figure class="photo">
                            <img src="/asset/meridian-1/images/obj-business.webp" alt="<?php echo $site_e; ?> Business workspace preview">
                        </figure>
                    </div>
                </div>

                <div class="m-biz-pillars">
                    <article class="m-pillar"><b>Collections</b><span>Shareable payment links, virtual accounts, and structured customer invoices.</span></article>
                    <article class="m-pillar"><b>Payouts &amp; FX</b><span>Local settlements, bulk disbursements, China supplier wires, and tuition routes.</span></article>
                    <article class="m-pillar"><b>POS &amp; Resellers</b><span>Terminal enquiries, branded bulk SMS, and printable recharge or data cards.</span></article>
                    <article class="m-pillar"><b>Wholesale APIs</b><span>Server-side airtime, data, cable, power, and exam vending with webhooks.</span></article>
                </div>
            </section>

            <section class="bstory" id="bstory">
                <div class="bs-pin">
                    <nav class="bs-rail" aria-label="Business workflow chapters">
                        <button type="button" aria-current="step"><b>01</b><span>Collections &amp; Links</span></button>
                        <button type="button"><b>02</b><span>Wallets &amp; FX</span></button>
                        <button type="button"><b>03</b><span>Payouts &amp; Cross-Border</span></button>
                        <button type="button"><b>04</b><span>POS &amp; Reseller Tools</span></button>
                        <button type="button"><b>05</b><span>Invoices &amp; Security</span></button>
                        <button type="button"><b>06</b><span>VTU APIs &amp; Integration</span></button>
                    </nav>
                    <div class="bs-chs">
                        <article class="bs-ch is-active">
                            <div class="bs-copy"><span class="bs-time">01 · Collections &amp; Payment Links</span><h2>Collect payments with clarity and tracking.</h2><p class="lede">Share payment links and virtual collection accounts so customers can settle invoices cleanly while your ledger tracks every reference.</p><ul class="bs-proof"><li><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 12l5 5L20 7"/></svg><span>Dedicated virtual inflow accounts where enabled</span></li><li><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 12l5 5L20 7"/></svg><span>Instant reference history for every settled payment</span></li></ul><a class="bz-link" href="/PaymentLinks.php">Explore Payment Links →</a></div>
                            <div class="bs-scene"><figure class="biz-visual"><img src="/asset/meridian-1/images/obj-business.webp" alt="Business collections preview" loading="lazy"><figcaption>Collections &amp; payment links</figcaption></figure></div>
                        </article>
                        <article class="bs-ch">
                            <div class="bs-copy"><span class="bs-time">02 · Multi-Currency Wallets</span><h2>Hold and track balances across currencies.</h2><p class="lede">Organize NGN, USD, GBP, and EUR business balances alongside live FX quotes where enabled.</p><ul class="bs-proof"><li><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 12l5 5L20 7"/></svg><span>Transparent rates shown prior to confirmation</span></li><li><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 12l5 5L20 7"/></svg><span>Clear separation of personal and merchant flows</span></li></ul><a class="bz-link" href="/MultiCurrencyWallet.php">Explore Wallets →</a></div>
                            <div class="bs-scene"><figure class="biz-visual"><img src="/asset/meridian-1/images/obj-accounts.webp" alt="Multi-currency accounts" loading="lazy"><figcaption>Multi-currency accounts</figcaption></figure></div>
                        </article>
                        <article class="bs-ch">
                            <div class="bs-copy"><span class="bs-time">03 · Transfers &amp; Payouts</span><h2>Move supplier and team payouts smoothly.</h2><p class="lede">Run single or bulk disbursements locally or internationally, including China settlement and institutional tuition routes.</p><ul class="bs-proof"><li><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 12l5 5L20 7"/></svg><span>Recipient verification before disbursement</span></li><li><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 12l5 5L20 7"/></svg><span>PIN &amp; step-up verification on outgoing funds</span></li></ul><a class="bz-link" href="/Payouts.php">Explore Payouts →</a></div>
                            <div class="bs-scene"><figure class="biz-visual"><img src="/asset/meridian-1/images/obj-swap.webp" alt="Global payouts" loading="lazy"><figcaption>Local &amp; global payouts</figcaption></figure></div>
                        </article>
                        <article class="bs-ch">
                            <div class="bs-copy"><span class="bs-time">04 · POS &amp; Merchant Resellers</span><h2>In-person acceptance and VTU resale.</h2><p class="lede">Request POS terminals, send branded bulk SMS campaigns, and print recharge or data card batches for walk-in customers.</p><ul class="bs-proof"><li><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 12l5 5L20 7"/></svg><span>Tiered reseller pricing aligned with account level</span></li><li><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 12l5 5L20 7"/></svg><span>Printable card batches and branded SMS alerts</span></li></ul><a class="bz-link" href="/POSTerminals.php">Explore POS &amp; Resellers →</a></div>
                            <div class="bs-scene"><figure class="biz-visual"><img src="/asset/meridian-1/images/obj-biz-pos.webp" alt="POS terminals" loading="lazy"><figcaption>POS &amp; merchant tools</figcaption></figure></div>
                        </article>
                        <article class="bs-ch">
                            <div class="bs-copy"><span class="bs-time">05 · Invoices, Records &amp; Security</span><h2>Audit trails, PINs, and verification built in.</h2><p class="lede">Manage customer invoices, reconciliation records, transaction PINs, and authenticator second-factor verification from one console.</p><ul class="bs-proof"><li><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 12l5 5L20 7"/></svg><span>Only verified settled records mark invoices paid</span></li><li><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 12l5 5L20 7"/></svg><span>Session management &amp; 2FA security controls</span></li></ul><a class="bz-link" href="/Invoices.php">Explore Invoices →</a></div>
                            <div class="bs-scene"><figure class="biz-visual"><img src="/asset/meridian-1/images/obj-biz-invoice.webp" alt="Business invoices" loading="lazy"><figcaption>Invoices &amp; records</figcaption></figure></div>
                        </article>
                        <article class="bs-ch">
                            <div class="bs-copy"><span class="bs-time">06 · VTU Wholesale &amp; APIs</span><h2>Automate airtime, data, cable, and power.</h2><p class="lede">Connect approved merchant API keys to vend digital utilities with idempotency and server-side verification.</p><ul class="bs-proof"><li><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 12l5 5L20 7"/></svg><span>Dedicated API key management in your dashboard</span></li><li><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 12l5 5L20 7"/></svg><span>Requery endpoints for reliable status checks</span></li></ul><a class="bz-link" href="/Developers.php">Read Developer Guide →</a></div>
                            <div class="bs-scene"><figure class="biz-visual"><img src="/asset/meridian-1/images/obj-refill.webp" alt="VTU wholesale API" loading="lazy"><figcaption>VTU reseller APIs</figcaption></figure></div>
                        </article>
                    </div>
                </div>
            </section>
        </div>

        <?php
        echo bc_meridian_render_catalog_section(
            'business',
            $vid,
            'Business feature catalogue',
            'Every category below updates dynamically from the Site Owner Service Control Center. Disabled or pending services are moved to Coming Soon.'
        );
        ?>

        <div class="wrap">
            <section class="chapter tight" id="vtu-api">
                <div class="api-layout">
                    <section class="api-block">
                        <p class="eyebrow"><i></i>VTU Business &amp; API</p>
                        <h3>Server-side integration built for reliability</h3>
                        <p class="small">Keep API keys strictly on your backend, validate recipients before debiting, attach unique references to every request, and verify status via requery or webhooks.</p>
                        <pre class="m-code-block">POST /web/api/airtime.php
{
  "api_key": "YOUR_SERVER_API_KEY",
  "network": "mtn",
  "phone_number": "08031234567",
  "amount": 1000,
  "reference": "ord_9f3c2a1b"
}</pre>
                        <div style="margin-top:18px;display:flex;gap:10px;flex-wrap:wrap">
                            <a class="btn primary" href="/Developers.php">Open API documentation</a>
                            <a class="btn ghost" href="/Resellers.php">Merchant &amp; reseller tools</a>
                        </div>
                    </section>
                    <section class="api-block">
                        <p class="eyebrow"><i></i>Onboarding</p>
                        <h3>Four steps to activate Business access</h3>
                        <ul class="m-steps-list">
                            <li><i>01</i><span>Create your account and request Business upgrade from your console.</span></li>
                            <li><i>02</i><span>Complete KYB &amp; identity verification checks required for higher limits.</span></li>
                            <li><i>03</i><span>Confirm approved services, fee schedules, and reseller pricing tiers.</span></li>
                            <li><i>04</i><span>Launch collections, payouts, POS enquiries, or API vending.</span></li>
                        </ul>
                    </section>
                </div>
            </section>
        </div>
        <?php
        bc_landing_foot($ctx);
        return;
    }

    // Structured Meridian-1 subpage rendering for all 16 PublicPage.php routes
    $highlights = array(
        array('Access level', $world === 'business' ? 'Business & Merchant' : 'Personal Account'),
        array('Verification', 'PIN & account checks apply'),
        array('Records', 'Full receipt & status history'),
    );
    $extra_cards = array(
        array('01 · Check eligibility', 'Sign in and complete the verification requested for your account and service tier.'),
        array('02 · Review the details', 'Read the current fees, limits, FX quotes, and terms before confirming any request.'),
        array('03 · Keep your records', 'Use transaction history and support chat for status updates. Never share your security codes.'),
    );
    if ($key === 'wallet') {
        $r = $ctx['rates'] ?? array();
        $highlights = array(
            array('USD / NGN quote', !empty($r['USD']) ? '₦' . number_format((float)$r['USD'], 2) : 'Shown in wallet'),
            array('GBP / NGN quote', !empty($r['GBP']) ? '₦' . number_format((float)$r['GBP'], 2) : 'Shown in wallet'),
            array('EUR / NGN quote', !empty($r['EUR']) ? '₦' . number_format((float)$r['EUR'], 2) : 'Shown in wallet'),
        );
    } elseif ($key === 'developers') {
        $highlights = array(
            array('Auth model', 'Server-side API key'),
            array('Protection', 'Idempotent reference checks'),
            array('Coverage', 'Airtime, Data, Cable, Electric, Exam'),
        );
        $extra_cards = array(
            array('01 · Generate server key', 'Sign in to your account console and generate an API key restricted to your server.'),
            array('02 · Validate & requery', 'Always verify smartcards or meters before debiting and requery pending transactions.'),
            array('03 · Protect secrets', 'Never embed API keys in mobile apps, frontend JavaScript, or public repositories.'),
        );
    } elseif ($key === 'security') {
        $highlights = array(
            array('Login & session', 'Hashed passwords + session controls'),
            array('Step-up auth', 'Security PIN & 2FA / Email OTP'),
            array('Identity KYC', 'Tiered BVN / NIN checks'),
        );
        $extra_cards = array(
            array('01 · Transaction PIN', 'Transfers and sensitive settings require your personal security PIN before execution.'),
            array('02 · Two-factor verification', 'Enable Google Authenticator or email OTP protection for sign-in and payouts.'),
            array('03 · Never share codes', 'Official support will never request your password, PIN, OTP, or full card CVV.'),
        );
    } elseif ($key === 'resellers') {
        $highlights = array(
            array('Pricing tiers', 'Account-level reseller margins'),
            array('Print hub', 'Recharge & data card batches'),
            array('Acceptance', 'POS requests & Bulk SMS'),
        );
    }

    $secondary_href = $world === 'business' ? '/Business.php' : '/Services.php';
    $secondary_label = $world === 'business' ? 'Business workspace' : 'All services';
    ?>
    <div class="wrap">
        <section class="m-sub-hero">
            <div class="m-page-split">
                <div>
                    <p class="eyebrow"><i></i><?php echo bc_landing_e($ctx['site_name']); ?><?php echo $world === 'business' ? ' · Business' : ''; ?></p>
                    <h1><?php echo bc_landing_e($p[0]); ?></h1>
                    <p class="lede"><?php echo bc_landing_e($p[2]); ?></p>
                    <p style="margin-top:12px;color:var(--text2,#CBD5E1);line-height:1.65"><?php echo bc_landing_e($p[3]); ?></p>
                    <div style="display:flex;gap:12px;flex-wrap:wrap;margin-top:24px">
                        <a class="btn primary" href="<?php echo bc_landing_e($p[5]); ?>"><?php echo bc_landing_e($p[6]); ?></a>
                        <a class="btn ghost" href="<?php echo bc_landing_e($secondary_href); ?>"><?php echo bc_landing_e($secondary_label); ?></a>
                    </div>
                    <?php if ($key === 'download'): ?>
                        <?php $url = bc_public_download_url($ctx['vendor_id']); ?>
                        <?php if ($url !== ''): ?>
                            <div style="margin-top:18px"><a class="btn primary" href="<?php echo bc_landing_e($url); ?>" rel="noopener noreferrer">Open official app download</a></div>
                        <?php else: ?>
                            <p class="m-notice" role="status" style="margin-top:18px">An app download has not been published for this website yet. Please use the browser option above or contact support.</p>
                        <?php endif; ?>
                        <p class="small" style="margin-top:10px">App Store and Google Play availability depends on the operator’s published release. No store listing is assumed here.</p>
                    <?php endif; ?>
                    <?php if ($key === 'contact'): ?>
                        <?php echo bc_meridian_support_bar($ctx); ?>
                    <?php endif; ?>
                </div>
                <aside class="m-showcase-card">
                    <p class="eyebrow"><i></i>At a Glance</p>
                    <?php foreach ($highlights as $hl): ?>
                    <div class="m-kv-row"><span><?php echo bc_landing_e($hl[0]); ?></span><strong><?php echo bc_landing_e($hl[1]); ?></strong></div>
                    <?php endforeach; ?>
                    <p class="m-notice" style="margin:4px 0 0"><?php echo bc_landing_e($p[4]); ?></p>
                </aside>
            </div>
        </section>

        <?php if ($key === 'developers'): ?>
        <section class="chapter tight">
            <div class="api-layout">
                <section class="api-block">
                    <p class="eyebrow"><i></i>Sample Request</p>
                    <h3>Server-side VTU endpoint</h3>
                    <pre class="m-code-block">POST /web/api/data.php
{
  "api_key": "YOUR_SERVER_API_KEY",
  "network": "mtn",
  "phone_number": "08031234567",
  "plan_code": "MTN_SME_1GB",
  "reference": "tx_01J9Z4K"
}</pre>
                </section>
                <section class="api-block">
                    <p class="eyebrow"><i></i>Best Practices</p>
                    <h3>Integration checklist</h3>
                    <ul class="m-steps-list">
                        <li><i>01</i><span>Store API keys in server environment variables, never client bundles.</span></li>
                        <li><i>02</i><span>Send unique client references to protect against duplicate debits.</span></li>
                        <li><i>03</i><span>Always requery pending responses before marking an order failed.</span></li>
                    </ul>
                </section>
            </div>
        </section>
        <?php endif; ?>

        <section class="chapter tight">
            <div class="chap-head">
                <p class="eyebrow"><i></i>Guidance</p>
                <h2 style="margin-top:10px">Before you continue</h2>
            </div>
            <div class="m-cards3">
                <?php foreach ($extra_cards as $ec): ?>
                <article class="m-card">
                    <div class="m-step-num"><?php echo bc_landing_e(explode(' · ', $ec[0])[0]); ?></div>
                    <h3><?php echo bc_landing_e(explode(' · ', $ec[0])[1] ?? $ec[0]); ?></h3>
                    <p><?php echo bc_landing_e($ec[1]); ?></p>
                </article>
                <?php endforeach; ?>
            </div>
        </section>

        <div class="m-cta-band">
            <h2>Ready to take the next step?</h2>
            <p><?php echo bc_landing_e($p[2]); ?></p>
            <div style="display:flex;gap:12px;justify-content:center;flex-wrap:wrap">
                <a class="btn primary" href="<?php echo bc_landing_e($p[5]); ?>"><?php echo bc_landing_e($p[6]); ?></a>
                <a class="btn ghost" href="/Contact.php">Contact support</a>
            </div>
        </div>
    </div>
    <?php
    bc_landing_foot($ctx);
}

}
