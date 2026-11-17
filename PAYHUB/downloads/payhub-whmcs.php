<?php
/**
 * Payhub WHMCS Payment Gateway Module
 */

if (!defined("WHMCS")) die("This file cannot be accessed directly");

function payhub_MetaData() {
    return array(
        'DisplayName' => 'Payhub',
        'APIVersion' => '1.1',
    );
}

function payhub_config() {
    return array(
        'FriendlyName' => array('Type' => 'System', 'Value' => 'Payhub'),
        'publicKey' => array('FriendlyName' => 'Public Key', 'Type' => 'text', 'Size' => '40', 'Default' => ''),
        'secretKey' => array('FriendlyName' => 'Secret Key', 'Type' => 'password', 'Size' => '40', 'Default' => ''),
        'testMode' => array('FriendlyName' => 'Test Mode', 'Type' => 'yesno', 'Description' => 'Tick to enable test mode'),
    );
}

function payhub_link($params) {
    // Note: This module is intended for production. Replace BASE_URL with your actual domain if needed.
    $baseUrl = "https://payhub.com";

    $code = '<script src="' . $baseUrl . '/inline.js"></script>
    <form onsubmit="payWithPayhub(); return false;">
        <script>
            function payWithPayhub() {
                var handler = PayhubPop.setup({
                    key: "' . $params['publicKey'] . '",
                    email: "' . $params['clientdetails']['email'] . '",
                    amount: ' . ($params['amount'] * 100) . ',
                    currency: "' . $params['currency'] . '",
                    ref: "' . $params['invoiceid'] . '_' . time() . '",
                    callback: function(response) {
                        window.location.href = "' . $params['systemurl'] . '/modules/gateways/callback/payhub.php?invoiceid=' . $params['invoiceid'] . '&ref=" + response.reference;
                    }
                });
                handler.openIframe();
            }
        </script>
        <input type="submit" value="' . $params['langpaynow'] . '" class="btn btn-primary" />
    </form>';

    return $code;
}
