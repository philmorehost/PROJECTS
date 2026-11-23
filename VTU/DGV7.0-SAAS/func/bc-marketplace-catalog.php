<?php
/** SAAS only: catalogue inventory, never tenant activation or provider verification. */
require_once __DIR__ . '/bc-retired-vtu-providers.php';
function bc_marketplace_bundled_catalog($directory = null) {
    $directory = $directory ?? __DIR__ . '/api-gateway';
    $categories = ['shared-data','sme-data','cg-data','dd-data','airtime','betting','datacard','rechargecard','electric','cable','exam','sms'];
    $catalog = [];
    foreach (glob($directory . '/*.php') ?: [] as $file) {
        $name = basename($file, '.php');
        foreach ($categories as $category) {
            $prefix = $category . '-';
            if (strpos($name, $prefix) !== 0) continue;
            $provider = substr($name, strlen($prefix));
            // Local dispatchers and SMS transport variants are not independent providers.
            if ($provider === 'localserver') break;
            if ($category === 'sms') $provider = preg_replace('/^(account|app)-/', '', $provider);
            $host = str_replace('-', '.', $provider);
            if (!preg_match('/\A[a-z0-9]+(?:\.[a-z0-9]+)+\z/D', $host)) break;
            if (bc_retired_vtu_provider($host)) break;
            $type = $category === 'sms' ? 'bulk-sms' : $category;
            $catalog[$type . ':' . $host] = ['type'=>$type, 'host'=>$host];
            break;
        }
    }
    ksort($catalog);
    return array_values($catalog);
}
/** Idempotent inventory sync. Existing prices/status/descriptions are NEVER overwritten. */
function bc_marketplace_sync_bundled($db) {
    $lock = mysqli_query($db, "SELECT GET_LOCK('bc_saas_bundled_catalog', 0) AS acquired");
    if (!$lock || (int)(mysqli_fetch_assoc($lock)['acquired'] ?? 0) !== 1) return false;
    try {
        foreach (bc_marketplace_bundled_catalog() as $entry) {
            $host = mysqli_real_escape_string($db, $entry['host']);
            $type = mysqli_real_escape_string($db, $entry['type']);
            $description = mysqli_real_escape_string($db, 'Bundled adapter; inactive/private until platform review. Configuration is not production verification.');
            // Private by default, and no sas_apis/entitlement/provider settings are touched.
            if (!mysqli_query($db, "INSERT INTO sas_api_marketplace_listings (api_website, api_type, price, description, status) SELECT '$host', '$type', '0', '$description', '2' WHERE NOT EXISTS (SELECT 1 FROM sas_api_marketplace_listings WHERE api_website='$host' AND api_type='$type')")) return false;
        }
        return true;
    } finally {
        mysqli_query($db, "SELECT RELEASE_LOCK('bc_saas_bundled_catalog')");
    }
}
