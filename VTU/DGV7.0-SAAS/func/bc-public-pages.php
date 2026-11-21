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
function bc_public_directory_html() {
    echo '<div class="public-directory" style="flex:1 1 100%;margin-top:24px"><h3 style="font-size:16px">Explore more</h3><div style="display:flex;flex-wrap:wrap;gap:12px 22px">';
    foreach (bc_public_directory() as $link) echo '<a style="font-size:14px;color:inherit" href="'.htmlspecialchars($link[1], ENT_QUOTES, 'UTF-8').'">'.htmlspecialchars($link[0], ENT_QUOTES, 'UTF-8').'</a>';
    echo '</div></div>';
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
    bc_landing_head($ctx, $p[0], $p[2]); bc_landing_nav($ctx);
    echo '<main class="wrap"><section class="hero"><p class="eyebrow">'.bc_landing_e($ctx['site_name']).'</p><h1>'.bc_landing_e($p[0]).'</h1><p class="lead">'.bc_landing_e($p[2]).'</p><p>'.bc_landing_e($p[3]).'</p><p>'.bc_landing_e($p[4]).'</p><div class="hero-cta"><a class="btn btn-dark" href="'.bc_landing_e($p[5]).'">'.bc_landing_e($p[6]).'</a><a class="btn btn-brand" href="/Services.php">All services</a></div>';
    if ($key === 'download') {
        $url = bc_public_download_url($ctx['vendor_id']);
        if ($url !== '') echo '<p><a class="btn btn-brand" href="'.bc_landing_e($url).'" rel="noopener">Open official app download</a></p>';
        else echo '<p role="status">An app download has not been published for this website yet. Please use the browser option above or contact support.</p>';
        echo '<p>App Store and Google Play availability depends on the operator’s published release. No store listing is assumed here.</p>';
    }
    echo '</section><section class="section"><h2>Before you continue</h2><div class="cards3"><article class="card"><h3>1. Check eligibility</h3><p>Sign in and complete the verification requested for your account and service.</p></article><article class="card"><h3>2. Review the details</h3><p>Read the current fees, limits and terms before confirming any request.</p></article><article class="card"><h3>3. Keep your records</h3><p>Use transaction history and support for status updates. Never share your security codes.</p></article></div></section></main>';
    bc_landing_foot($ctx);
}

}
