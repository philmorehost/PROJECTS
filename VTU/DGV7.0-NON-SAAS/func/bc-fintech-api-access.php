<?php
/**
 * DGV7 §28 — commercialised fintech API access (Flutterwave / Klasha / Bybit / Platnova).
 *
 * Business rule from the site owner:
 *   - NON-SAAS (single owner): these APIs are included by default.
 *   - SAAS (multi-vendor): each API is a paid add-on. The super admin sets the
 *     price in bc-spadmin → Payment Gateway (Bybit default ₦150,000, others ₦50,000); a vendor
 *     buys access from bc-admin → Payment Gateway, charged to the vendor wallet.
 *     Until then the API cannot be selected as the active provider and its
 *     credentials cannot be saved.
 *
 * Prices live in sas_super_admin_options as fintech_api_price_<slug>; a grant
 * lives in the vendor options as fintech_api_access_<slug> ("<iso-date>|<ref>").
 * A price of 0 makes the API free (access granted without purchase).
 *
 * Provider names appear in this file and in the admin screens that sell and
 * configure the APIs — never in customer-facing pages.
 */

require_once(__DIR__ . '/bc-edition.php');

if (!function_exists('bc_fintech_api_providers')) {
    /** The commercialised APIs, slug => admin-facing label + what it powers. */
    function bc_fintech_api_providers() {
        return [
            'flutterwave' => ['default_price'=>50000, 'label' => 'Flutterwave', 'powers' => 'Global payments, transfers, bills, virtual accounts & POS requests'],
            'klasha'      => ['default_price'=>50000, 'label' => 'Klasha',      'powers' => 'Payout to China, wire transfers, multi-currency & stablecoin wallets'],
            'bybit'       => ['default_price'=>150000, 'label'=>'Bybit', 'powers'=>'Crypto API configuration; live activation remains paused'],
            'platnova'    => ['default_price'=>50000, 'label' => 'Platnova',    'powers' => 'Tuition worldwide, cards, payments & partner eSIM'],
        ];
    }
}

if (!function_exists('bc_fintech_api_price')) {
    /** Current price in NGN for one API. Super-admin option, default ₦50,000. */
    function bc_fintech_api_price($slug) {
        $slug = strtolower(trim((string)$slug));
        if (!isset(bc_fintech_api_providers()[$slug])) return 0.0;
        $raw = function_exists('getSuperAdminOption') ? getSuperAdminOption('fintech_api_price_' . $slug, '') : '';
        if(isset($GLOBALS['connection_server']) && $GLOBALS['connection_server'] instanceof mysqli){
            $q=mysqli_query($GLOBALS['connection_server'],"SELECT option_value FROM sas_super_admin_options WHERE option_name='fintech_api_price_$slug' LIMIT 2");
            if(!$q || mysqli_num_rows($q)>1)return (float)bc_fintech_api_providers()[$slug]['default_price'];
            $row=mysqli_fetch_assoc($q);$raw=$row?$row['option_value']:'';
        }
        if ($raw === '' || $raw === null || !is_numeric($raw)) return (float)bc_fintech_api_providers()[$slug]['default_price'];
        if(!is_finite((float)$raw) || (float)$raw<0)return (float)bc_fintech_api_providers()[$slug]['default_price'];
        return (float)$raw;
    }
}

if (!function_exists('bc_fintech_api_access')) {
    /**
     * May this vendor use the API at all?
     * NON-SAAS: always. SAAS: only after purchase (or while the price is 0).
     */
    function bc_fintech_api_access($vendor_id, $slug) {
        $slug = strtolower(trim((string)$slug));
        if (!isset(bc_fintech_api_providers()[$slug])) return false;
        if (!bc_is_saas_edition()) return true;
        if (bc_fintech_api_price($slug) <= 0) return true;
        if(isset($GLOBALS['connection_server']) && $GLOBALS['connection_server'] instanceof mysqli){
            $vid=(int)$vendor_id;
            $q=mysqli_query($GLOBALS['connection_server'],"SELECT setting_value FROM sas_settings WHERE vendor_id=$vid AND setting_name='fintech_api_access_$slug' LIMIT 2");
            return $q && mysqli_num_rows($q)===1 && trim((string)mysqli_fetch_assoc($q)['setting_value'])!=='';
        }
        $grant = function_exists('getVendorOption') ? trim((string)getVendorOption((int)$vendor_id, 'fintech_api_access_' . $slug, '')) : '';
        return $grant !== '';
    }
}

if (!function_exists('bc_fintech_api_grant')) {
    /** Record a purchased (or comped) API access grant for a vendor. */
    function bc_fintech_api_grant($vendor_id, $slug, $reference) {
        $slug = strtolower(trim((string)$slug));
        if (!isset(bc_fintech_api_providers()[$slug])) return false;
        if (!function_exists('setVendorOption')) return false;
        $value = date('c') . '|' . trim((string)$reference);
        return (bool)setVendorOption((int)$vendor_id, 'fintech_api_access_' . $slug, $value);
    }
}

if (!function_exists('bc_fintech_api_accessible_providers')) {
    /** Slugs from bc_fintech_api_providers() this vendor may actually use. */
    function bc_fintech_api_accessible_providers($vendor_id) {
        $out = [];
        foreach (array_keys(bc_fintech_api_providers()) as $slug) {
            if (bc_fintech_api_access($vendor_id, $slug)) $out[] = $slug;
        }
        return $out;
    }
}
