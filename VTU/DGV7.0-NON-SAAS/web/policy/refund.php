<?php
include_once '_bootstrap.php';
$policy_title = 'Refund Policy';
include_once '_header.php';
?>

<div class="notice-box"><strong>Please read before transacting.</strong> Due to the instant nature of digital services, most transactions on <?php echo htmlspecialchars($_policy_site_title); ?> are irreversible once processed. This policy explains the limited circumstances under which refunds may be granted.</div>

<h2>1. General Principle</h2>
<p>Because airtime, data, electricity tokens, and other digital services are delivered instantly to third-party networks and operators, completed transactions cannot generally be reversed. We strongly advise you to double-check all recipient details before confirming any transaction.</p>

<h2>2. Eligible Refund Scenarios</h2>
<p>A refund or wallet credit may be issued in the following circumstances:</p>
<ul>
    <li><strong>Failed &amp; Undelivered:</strong> You were debited but the service was not delivered, and the network operator confirms non-delivery after a requery.</li>
    <li><strong>Double Debit:</strong> Your wallet was debited twice for the same transaction due to a platform or gateway error.</li>
    <li><strong>Service Unavailability:</strong> The relevant service provider was unavailable and we were unable to deliver the service within 24 hours of the transaction.</li>
    <li><strong>Overpayment:</strong> You funded your wallet with more than the intended amount due to a platform error (gateway errors are managed by the respective payment provider).</li>
</ul>

<h2>3. Non-Refundable Transactions</h2>
<p>No refund will be issued for:</p>
<ul>
    <li>Airtime, data, electricity tokens, or cable TV payments that were successfully delivered to the correct recipient</li>
    <li>Transactions where the wrong phone number, meter number, or IUC was entered by the user</li>
    <li>Exam PIN purchases once the PIN has been generated and delivered</li>
    <li>Gift card trades where the card codes have been delivered and the buyer has confirmed receipt</li>
    <li>Virtual card issuance fees</li>
    <li>Crypto swap or withdrawal transactions that have been broadcast to the blockchain</li>
    <li>Bulk SMS credits once messages have been submitted for delivery</li>
</ul>

<h2>4. Wallet Balance Refunds</h2>
<p>Approved refunds are credited directly to your platform wallet, not to your original payment method. Refunds to the original payment source (card/bank transfer) are not supported as we do not store card details.</p>

<h2>5. How to Request a Refund</h2>
<ol>
    <li>Log in to your account and navigate to <strong>Transaction History</strong>.</li>
    <li>Locate the affected transaction and use the <strong>Requery</strong> feature to check the delivery status.</li>
    <li>If the requery confirms non-delivery, the amount will be auto-reversed to your wallet within 15 minutes.</li>
    <li>For cases not resolved by the requery feature, contact support via live chat with your transaction reference number.</li>
</ol>

<h2>6. Processing Time</h2>
<p>Auto-reversed amounts are credited within 15–30 minutes. Manually reviewed refunds are processed within 1–3 business days after confirmation of eligibility.</p>

<h2>7. Dispute Resolution</h2>
<p>If you disagree with a refund decision, you may escalate the matter in writing to our compliance team. Unresolved disputes may be referred to the relevant consumer protection or financial regulatory authority.</p>

<?php include_once '_footer.php'; ?>
