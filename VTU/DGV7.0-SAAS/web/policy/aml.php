<?php
include_once '_bootstrap.php';
$policy_title = 'Anti-Money Laundering (AML) Policy';
include_once '_header.php';
?>

<div class="notice-box"><strong>Compliance Commitment.</strong> <?php echo htmlspecialchars($_policy_site_title); ?> is committed to full compliance with Nigeria's Money Laundering (Prevention and Prohibition) Act 2022, the Terrorism (Prevention and Prohibition) Act, and all related CBN AML/CFT guidelines.</div>

<h2>1. Purpose</h2>
<p>This Anti-Money Laundering (AML) Policy establishes the framework by which <?php echo htmlspecialchars($_policy_site_title); ?> detects, prevents, and reports money laundering and terrorist financing activities on our platform. All users, agents, and staff are bound by this policy.</p>

<h2>2. Know Your Customer (KYC) Requirements</h2>
<p>We require identity verification before users can access certain services or exceed transaction thresholds. Accepted identity documents include:</p>
<ul>
    <li>National Identification Number (NIN) / NIN slip</li>
    <li>Bank Verification Number (BVN)</li>
    <li>Valid government-issued photo ID (National ID card, Driver's licence, International passport, Voter's card)</li>
    <li>Proof of address (utility bill or bank statement not older than 3 months)</li>
</ul>

<h2>3. Customer Due Diligence (CDD)</h2>
<p>We apply a risk-based approach to CDD:</p>
<ul>
    <li><strong>Simplified CDD:</strong> for low-risk transactions below regulatory thresholds</li>
    <li><strong>Standard CDD:</strong> full identity and address verification for regular users</li>
    <li><strong>Enhanced CDD:</strong> for high-value transactions, politically exposed persons (PEPs), or users from high-risk jurisdictions</li>
</ul>

<h2>4. Transaction Monitoring</h2>
<p>Our platform implements automated monitoring for:</p>
<ul>
    <li>Unusual transaction patterns or volumes inconsistent with a user's profile</li>
    <li>Rapid succession of large transactions designed to evade reporting thresholds (structuring)</li>
    <li>Transactions involving jurisdictions subject to FATF blacklists or Nigerian sanctions</li>
    <li>Multiple accounts sharing a device, IP address, or bank account</li>
</ul>

<h2>5. Suspicious Activity Reporting (SAR)</h2>
<p>Where a transaction or user behaviour raises reasonable suspicion of money laundering or terrorist financing, we are legally obligated to file a Suspicious Transaction Report (STR) with the Nigerian Financial Intelligence Unit (NFIU). We will not "tip off" the subject of such a report.</p>

<h2>6. Record Keeping</h2>
<p>We retain all customer identification records, transaction records, and STR filings for a minimum of 5 years from the date of the transaction or account closure, in line with CBN AML/CFT Regulations.</p>

<h2>7. Prohibited Persons &amp; Entities</h2>
<p>We will not onboard or process transactions for persons or entities that are:</p>
<ul>
    <li>Subject to UN, OFAC, EU, or Nigerian government sanctions</li>
    <li>Listed as designated terrorists or persons of concern</li>
    <li>Shell companies with no identifiable beneficial owner</li>
</ul>

<h2>8. Employee &amp; Agent Training</h2>
<p>All agents and relevant staff receive AML/CFT awareness training. Failure to comply with this policy may result in account termination and reporting to authorities.</p>

<h2>9. Non-Compliance Consequences</h2>
<p>Users found to be in breach of this policy — including providing false identity documents or conducting structured transactions — will have their accounts immediately suspended, funds frozen pending investigation, and the matter reported to relevant law enforcement and regulatory authorities.</p>

<h2>10. Contact</h2>
<p>For compliance-related enquiries, contact our compliance team via the live chat on our homepage.</p>

<?php include_once '_footer.php'; ?>
