<?php
/**
 * DGV7.0 AI Edition — Support Sentinel
 * Monitors failed transactions and proactively resolves them.
 */

include_once(__DIR__ . "/bc-connect.php");

class AiSupportSentinel {
    private $db;

    public function __construct($db) {
        $this->db = $db;
    }

    /**
     * Scans for failed transactions and prepares autonomous resolution
     */
    public function monitorFailedTransactions() {
        $q = mysqli_query($this->db, "SELECT * FROM sas_transactions
            WHERE status=3 AND date >= NOW() - INTERVAL 10 MINUTE
            AND ai_sentinel_processed=0");

        while ($row = mysqli_fetch_assoc($q)) {
            $this->processFailure($row);
        }
    }

    private function processFailure($row) {
        $ref = $row['reference'];
        $username = $row['username'];
        $amount = $row['amount'];
        $description = $row['description'];

        // 1. Verify if user was refunded
        $refund_q = mysqli_query($this->db, "SELECT * FROM sas_transactions
            WHERE username='$username' AND type_alternative='refund'
            AND description LIKE '%$ref%' LIMIT 1");
        $is_refunded = mysqli_num_rows($refund_q) > 0;

        // 2. Get user contact details
        $u_q = mysqli_query($this->db, "SELECT vendor_id, username, firstname, email FROM sas_users WHERE username='$username' LIMIT 1");
        $user = mysqli_fetch_assoc($u_q);

        if ($user && !empty($user['email'])) {
            $subject = "AI Support Sentinel: Transaction Update";
            $body = "Hello " . ($user['firstname'] ?: $username) . ",<br><br>"
                 . "I noticed your transaction <b>" . htmlspecialchars($description) . "</b> failed.<br><br>"
                 . ($is_refunded ? "<b>Status:</b> Your wallet has been automatically refunded.<br>" : "<b>Status:</b> Refund is processing.<br>")
                 . "<br>Log in to retry using a backup route or choose a different plan.<br><br>"
                 . "&mdash; Resolving issues before you even ask.";

            $GLOBALS['vendor_id'] = (int)$user['vendor_id'];
            resolveVendorID(true);
            global $get_logged_user_details;
            $get_logged_user_details = $user;
            sendVendorEmail($user['email'], $subject, $body);

            bc_notify_user($this->db, $user['vendor_id'], $username, "Transaction Failed", $description . ($is_refunded ? " — refunded" : " — refund processing"), "");
        }

        // Mark as processed
        mysqli_query($this->db, "UPDATE sas_transactions SET ai_sentinel_processed=1 WHERE id=".$row['id']);
    }

    /**
     * High-Value Failure Alert for Admin
     */
    public function alertAdmin($row) {
        if ($row['amount'] >= 5000) { // Configurable threshold
            $subject = "High-Value Failure Alert";
            $body = "User: " . htmlspecialchars($row['username']) . "<br>"
                 . "Amount: &#8358;" . number_format($row['amount'], 2) . "<br>"
                 . "Service: " . htmlspecialchars($row['description']) . "<br>"
                 . "Ref: " . htmlspecialchars($row['reference']) . "<br><br>"
                 . "AI Sentinel is currently monitoring this user.";

            $admin_q = mysqli_query($this->db, "SELECT id, email FROM sas_vendors WHERE id=".(int)$row['vendor_id']);
            $admin = mysqli_fetch_assoc($admin_q);
            if ($admin && !empty($admin['email'])) {
                $GLOBALS['vendor_id'] = (int)$admin['id'];
                resolveVendorID(true);
                global $get_logged_user_details;
                $get_logged_user_details = ['vendor_id' => $admin['id'], 'username' => ''];
                sendVendorEmail($admin['email'], $subject, $body);
            }
        }
    }
}

/**
 * Procedural Hook for UI Evaluation (used in bc-admin controllers)
 */
function ai_sentinel_evaluate($username, $vendor_id, $api_type, $amount) {
    global $connection_server;

    // 1. Check spending anomaly
    require_once(__DIR__ . "/bc-security.php");
    if (bc_check_spending_anomaly($username, $amount)) {
        return 'BLOCK';
    }

    // 2. Check bulk-submission velocity — many numbers queued in a short window
    // is a stronger fraud signal than a single purchase of the same size.
    if (bc_check_bulk_velocity_anomaly($username, $vendor_id)) {
        return 'FLAG_FOR_APPROVAL';
    }

    // 3. Check Trust Score
    $score = bc_calculate_user_trust_score($username);
    if ($score < 20) {
        return 'BLOCK';
    }

    if ($score < 40) {
        return 'FLAG_FOR_APPROVAL';
    }

    return 'ALLOW';
}

/**
 * Flags accounts queuing an unusually large number of bulk items in a short
 * window — a common precursor to card-testing / stolen-wallet abuse.
 */
function bc_check_bulk_velocity_anomaly($username, $vendor_id) {
    global $connection_server;
    if (!$connection_server) return false;
    bc_ensure_bulk_queue_schema($connection_server);

    $user_esc = mysqli_real_escape_string($connection_server, $username);
    $vendor_id_esc = (int)$vendor_id;

    $q = mysqli_query($connection_server, "SELECT COUNT(*) as c FROM sas_bulk_queue_items WHERE vendor_id='$vendor_id_esc' AND username='$user_esc' AND created_at >= NOW() - INTERVAL 15 MINUTE");
    $row = $q ? mysqli_fetch_assoc($q) : null;
    $recent_count = (int)($row['c'] ?? 0);

    // More than 100 queued numbers in 15 minutes is well outside normal bulk usage.
    return $recent_count > 100;
}
