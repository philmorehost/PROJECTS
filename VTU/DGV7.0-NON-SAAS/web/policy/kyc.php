<?php
include_once '_bootstrap.php';
$policy_title = 'Know Your Customer (KYC) Policy';
include_once '_header.php';
?>

<div class="notice-box"><strong>Identity Verification is Required.</strong> In compliance with the CBN KYC Regulations, FCCPC requirements, and the Nigeria Data Protection Act 2023, <?php echo htmlspecialchars($_policy_site_title); ?> is required to verify the identity of all users accessing financial services on our platform.</div>

<h2>1. Why KYC is Required</h2>
<p>KYC (Know Your Customer) verification helps us:</p>
<ul>
    <li>Prevent identity fraud and impersonation</li>
    <li>Comply with anti-money laundering (AML) and counter-terrorist financing (CTF) laws</li>
    <li>Protect our users from unauthorised account access</li>
    <li>Meet regulatory requirements set by the CBN and FCCPC</li>
</ul>

<h2>2. KYC Tiers &amp; Transaction Limits</h2>
<p>We operate a tiered KYC system aligned with CBN guidelines:</p>
<ul>
    <li><strong>Tier 1 (Basic):</strong> Name, phone number, and email. Limited daily transaction value.</li>
    <li><strong>Tier 2 (Standard):</strong> BVN / NIN verification. Increased transaction limits.</li>
    <li><strong>Tier 3 (Enhanced):</strong> Full identity document + proof of address. Highest limits and access to all services.</li>
</ul>

<h2>3. Acceptable Identity Documents</h2>
<p>We accept the following as valid identity documents:</p>
<ul>
    <li>National Identification Number (NIN) or NIN slip</li>
    <li>Bank Verification Number (BVN)</li>
    <li>Nigerian International Passport (valid)</li>
    <li>Driver's Licence (valid)</li>
    <li>National Identity Card (NIMC)</li>
    <li>Permanent Voter's Card (PVC)</li>
</ul>

<h2>4. Document Submission Requirements</h2>
<ul>
    <li>Documents must be valid (not expired) at the time of submission</li>
    <li>Images must be clear, unedited, and fully visible (all four corners)</li>
    <li>The name on your identity document must match your registration details</li>
    <li>A selfie holding your ID may be required for facial matching</li>
</ul>

<h2>5. Data Processing &amp; Consent</h2>
<p>By submitting KYC documents, you consent to the processing of your biometric and identity data solely for verification purposes. We use licensed, CBN-approved identity verification providers. Your documents are encrypted in transit and at rest and are never shared with third parties beyond verification providers and regulators.</p>

<h2>6. Verification Timeline</h2>
<p>Automated BVN/NIN verification is instant. Manual document review is completed within 1–3 business days. You will receive a notification when your KYC status is updated.</p>

<h2>7. Rejection &amp; Re-submission</h2>
<p>If your KYC submission is rejected, you will receive a specific reason and may resubmit corrected documents. Three consecutive rejections may trigger an enhanced review or account restriction.</p>

<h2>8. Ongoing Monitoring</h2>
<p>We periodically re-verify user information to ensure accuracy, particularly when there are changes in transaction patterns or following a regulatory update. You may be asked to provide updated documents.</p>

<h2>9. Rights of Verified Users</h2>
<p>Verified users enjoy:</p>
<ul>
    <li>Higher daily and monthly transaction limits</li>
    <li>Access to wallet-to-bank withdrawals</li>
    <li>Eligibility for agent and API reseller accounts</li>
    <li>Priority customer support</li>
</ul>

<h2>10. Contact</h2>
<p>If you have questions about the KYC process or your verification status, contact our support team via the live chat button on our homepage.</p>

<?php include_once '_footer.php'; ?>
