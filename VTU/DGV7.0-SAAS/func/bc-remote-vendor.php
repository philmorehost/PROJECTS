<?php
/**
 * Same-script vendor support — one DGV7 install buying from another DGV7 install.
 *
 * The protocol has two halves, and both must be in place before the feature is usable:
 *
 *   PARENT (the seller)  api/app-backend/fetch-dgv7-plans.php
 *       Publishes a service's plan list as {"success":true,"plans":[{name,code,price,days}]}.
 *       Authenticates with the api_key of a sas_users row on the PARENT — i.e. the buyer registers
 *       as a customer of the seller, and the seller must have approved API access for that user
 *       (api_status=1). That approval step is the "activate another vendor's API" switch.
 *
 *   CHILD (the buyer)    bc-admin/ajax-fetch-plans.php → the "DGV7 API Fetch Protocol" branch
 *       Calls that endpoint over HTTPS and stores the rows through ajax-save-plans.php. The `code`
 *       it stores becomes `val_1`, which is what the child's purchase path sends upstream as the
 *       plan code — so the codes must be the SELLER's codes, which is exactly what the parent
 *       returns.
 *
 * The one thing missing was the CHOICE: the Variation Fetcher card in the ten service pages was
 * gated on `$is_external || $is_local`, and for a seller hosted on a different server both are
 * false (`$is_local` asks the buyer's OWN database for a `sas_vendors` row, which cannot exist for
 * another install). The card was therefore never rendered, so the dropdown and the button the
 * fetch branch needs were unreachable and the feature looked unimplemented.
 *
 * These two helpers decide "is this stored api_base_url another DGV7 install we could buy from?"
 * while rendering a page, so they stay cheap: no HTTP, only rules that exclude what must NOT be
 * offered. The fetch itself is what ultimately proves reachability, and it reports its own errors.
 */

/**
 * Host as stored is admin/marketplace-typed: it may carry a scheme, a "www." prefix, a trailing
 * slash or mixed case. func/bc-gateway-plan-parser.php and the local-server purchase gateways
 * normalise the same way; keep the three in step.
 */
function bc_remote_vendor_normalize_host($url) {
    $host = strtolower(trim((string)$url));
    $host = preg_replace('#^https?://#i', '', $host);
    $host = preg_replace('#^www\.#i', '', $host);
    $host = explode('/', $host);
    $host = $host[0];
    return trim($host, " \t\n\r\0\x0B");
}

/**
 * True when this api_base_url could be another DGV7 install: a real hostname, not this install
 * itself (a vendor cannot resell to itself) and not one of the third-party providers that have
 * their own dedicated fetcher branches. Everything else is offered to the admin; a wrong guess
 * costs one failed fetch with a message that names the host, not a hidden button.
 */
function bc_remote_vendor_is_candidate($url) {
    $host = bc_remote_vendor_normalize_host($url);
    if ($host === '' || strpos($host, '.') === false) {
        return false;
    }
    $own_host = bc_remote_vendor_normalize_host($_SERVER['HTTP_HOST'] ?? '');
    if ($own_host !== '' && $host === $own_host) {
        return false;
    }
    $third_party = array('vtpass.com', 'clubkonnect.com', 'nellobytesystems.com', 'naijaresultpins.com');
    foreach ($third_party as $provider) {
        if (stripos($host, $provider) !== false) {
            return false;
        }
    }
    return true;
}
