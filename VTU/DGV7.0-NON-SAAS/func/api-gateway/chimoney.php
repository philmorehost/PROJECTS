<?php
/**
 * Chimoney credentials lookup — retained ONLY as an exchange-rate source for
 * gift-card pricing (bc-giftcard-func.php). All Chimoney VIRTUAL CARD
 * functions were removed in DGV7 §25: virtual cards are Flutterwave-only
 * (func/bc-virtualcard-flutterwave.php).
 */
require_once(__DIR__ . "/../../includes/ChimoneyBridge.php");

function getChimoneyDetails($vid = null) {
    global $connection_server;
    if ($vid === null) $vid = resolveVendorID();

    // Check for "chimoney" in sas_apis for this vendor
    $q = mysqli_query($connection_server, "SELECT * FROM sas_apis WHERE vendor_id='$vid' AND api_type='chimoney' LIMIT 1");
    if ($q && $r = mysqli_fetch_assoc($q)) {
        return $r;
    }

    // Fallback to platform keys if no vendor keys are present
    $q_p = mysqli_query($connection_server, "SELECT * FROM sas_apis WHERE vendor_id='0' AND api_type='chimoney' LIMIT 1");
    if ($q_p && $r_p = mysqli_fetch_assoc($q_p)) {
        return $r_p;
    }

    return null;
}
