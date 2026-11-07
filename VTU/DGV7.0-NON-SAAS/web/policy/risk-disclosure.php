<?php
include_once '_bootstrap.php';
$policy_title = 'Risk Disclosure Policy';
include_once '_header.php';
?>

<div class="notice-box"><strong>Important: Please read this disclosure before using our platform.</strong> Using <?php echo htmlspecialchars($_policy_site_title); ?> involves financial transactions and, in the case of crypto services, exposure to market risk. This disclosure is provided in the interest of transparency and in compliance with regulatory obligations.</div>

<h2>1. General Financial Risk</h2>
<p>All financial transactions carry inherent risk. While we strive to deliver services instantly and reliably, factors beyond our control — including network operator outages, payment gateway downtimes, and regulatory changes — may affect service delivery. You are advised to:</p>
<ul>
    <li>Verify all recipient details before confirming a transaction</li>
    <li>Maintain adequate records of all transactions</li>
    <li>Not fund your wallet with more than you intend to spend in the near term</li>
</ul>

<h2>2. Cryptocurrency Risk</h2>
<p>The Crypto Hub on our platform facilitates the holding, sending, swapping, and receiving of cryptocurrencies and stablecoins. By using the Crypto Hub, you acknowledge and accept:</p>
<ul>
    <li><strong>Price volatility:</strong> Cryptocurrency values can change dramatically in short periods. Even stablecoins carry de-pegging risk.</li>
    <li><strong>Irreversibility:</strong> Blockchain transactions, once broadcast, cannot be cancelled or reversed. Always verify wallet addresses carefully.</li>
    <li><strong>Regulatory risk:</strong> The regulatory landscape for crypto assets in Nigeria is evolving. Future restrictions may affect your ability to use crypto services.</li>
    <li><strong>Technology risk:</strong> Smart contract bugs, network congestion, and blockchain forks may result in delayed or failed transactions.</li>
    <li><strong>Custody risk:</strong> Custodial wallets on this platform are managed by our third-party crypto infrastructure provider. Users are advised not to store large amounts on any custodial platform long-term.</li>
</ul>

<h2>3. Gift Card Trading Risk</h2>
<p>P2P gift card trading exposes users to counterparty risk. While our platform mediates trades and holds funds in escrow, we cannot guarantee the authenticity or value of gift card codes until verified by the buyer. You accept that:</p>
<ul>
    <li>Gift card values are subject to the issuing company's terms and may expire or be voided</li>
    <li>Rates offered on the platform are market-driven and may fluctuate</li>
    <li>Fraudulent counterparties may attempt to misrepresent card details — always verify before releasing payment</li>
</ul>

<h2>4. Virtual Card Risk</h2>
<p>Virtual dollar cards are issued by our licensed card partner. Risks include:</p>
<ul>
    <li>Cards may be declined by certain merchants — functionality is not guaranteed on all platforms</li>
    <li>Chargebacks initiated by the card network may result in card suspension</li>
    <li>USD exchange rates used for card funding reflect the prevailing rate at the time of funding</li>
</ul>

<h2>5. Fraud &amp; Phishing Risk</h2>
<p>We will never ask for your password, PIN, or OTP via phone, SMS, or email. Be wary of phishing sites that mimic our platform. Always verify you are on the correct URL before entering credentials. Enable two-factor authentication (2FA) for added account security.</p>

<h2>6. Regulatory Compliance Risk</h2>
<p><?php echo htmlspecialchars($_policy_site_title); ?> operates in accordance with Nigerian law. Regulatory changes — including new CBN directives, FCCPC orders, or NFIU requirements — may affect the availability of specific services without prior notice.</p>

<h2>7. No Investment Advice</h2>
<p>Nothing on this platform constitutes financial, investment, or legal advice. All transactions are at your own risk and discretion. We strongly advise consulting a qualified financial adviser before making large-value transactions.</p>

<h2>8. Limitation of Liability</h2>
<p>To the maximum extent permitted by law, <?php echo htmlspecialchars($_policy_site_title); ?> shall not be liable for indirect, consequential, or incidental losses arising from your use of the platform, including but not limited to loss of profits, missed transactions, or data breaches caused by third-party providers.</p>

<?php include_once '_footer.php'; ?>
