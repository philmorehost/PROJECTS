<?php
include_once '_bootstrap.php';
$policy_title = 'Terms of Service';
include_once '_header.php';
?>

<div class="notice-box"><strong>Please read these Terms carefully.</strong> By registering an account or using any service on <?php echo htmlspecialchars($_policy_site_title); ?>, you agree to be bound by these Terms of Service.</div>

<h2>1. Eligibility</h2>
<p>You must be at least 18 years old and a resident of Nigeria (or another country where our services are legally available) to create an account. By using the platform you warrant that you meet these requirements.</p>

<h2>2. Account Registration</h2>
<p>You agree to provide accurate, complete information during registration. You are responsible for maintaining the security of your username and password. You must notify us immediately of any unauthorised access to your account.</p>

<h2>3. Airtime &amp; Data Services</h2>
<p>Airtime and data top-ups are delivered instantly via network operators. Ensure the recipient phone number is correct before confirming. Completed transactions cannot be reversed or refunded as they are processed immediately by the network operator.</p>

<h2>4. Utility Bill Payments</h2>
<p>Electricity tokens and cable TV subscriptions are processed in real-time. You must verify your Meter Number, IUC, or Smartcard number before payment. <?php echo htmlspecialchars($_policy_site_title); ?> is not liable for payments made to incorrect account numbers.</p>

<h2>5. Wallet Funding &amp; Withdrawals</h2>
<ul>
    <li>Wallet funding via approved gateways (Paystack, Monnify, etc.) is instant.</li>
    <li>For manual bank transfer funding, submit accurate proof of payment. Processing may take up to 24 hours.</li>
    <li>Withdrawals are subject to account verification status and daily limits set by the vendor.</li>
    <li>We reserve the right to place a hold on wallet funds pending AML/fraud investigation.</li>
</ul>

<h2>6. Crypto Hub &amp; Gift Cards</h2>
<p>Crypto transactions are subject to blockchain network speeds and market volatility. Gift card codes are digital assets — secure them immediately upon delivery. P2P gift card trades are moderated but users bear responsibility for exercising due diligence on counterparties.</p>

<h2>7. Virtual Cards</h2>
<p>Virtual cards are for legitimate international online payments. Use in prohibited platforms (gambling, adult content, sanctioned entities) may result in card termination without refund of the issuance fee.</p>

<h2>8. Bulk SMS &amp; Print Hub</h2>
<p>Bulk SMS delivery depends on the recipient network's DND status. Card-printing services supply digital E-pins — physical cards are not provided. Delivery of E-pins is instant upon successful payment.</p>

<h2>9. Prohibited Activities</h2>
<p>You must not use the platform for:</p>
<ul>
    <li>Money laundering, terrorist financing, or any activity prohibited under Nigerian law</li>
    <li>Providing false identity information or impersonating another person</li>
    <li>Unauthorised access or attempts to compromise platform security</li>
    <li>Commercial reselling of services without an approved agent account</li>
</ul>

<h2>10. Suspension &amp; Termination</h2>
<p>We reserve the right to suspend or permanently close accounts that violate these Terms, breach AML/KYC regulations, or engage in fraudulent activity. Wallet balances may be frozen pending investigation.</p>

<h2>11. Limitation of Liability</h2>
<p><?php echo htmlspecialchars($_policy_site_title); ?> acts as an intermediary between users and service providers. While we maintain 99.9% uptime, we are not liable for losses caused by third-party operator downtime, incorrect user input, or force majeure events beyond our control.</p>

<h2>12. Governing Law</h2>
<p>These Terms are governed by the laws of the Federal Republic of Nigeria. Any disputes shall be resolved in the courts of Nigeria.</p>

<h2>13. Changes to Terms</h2>
<p>We may revise these Terms at any time. Continued use of the platform after changes constitutes acceptance of the updated Terms.</p>

<?php include_once '_footer.php'; ?>
