<?php
/**
 * §32 — transaction receipt payload (shared by the user console, bc-admin and
 * bc-spadmin JSON endpoints behind showTransactionDetails()).
 *
 * A receipt is the customer's proof of purchase, so the USER payload never
 * exposes provider internals (gateway host, provider reference, raw provider
 * wording, API anything). Staff views additionally receive the investigation
 * fields. No credential material exists in this payload at all.
 *
 * Both editions ship an identical copy of this file.
 */

if (!function_exists('bc_receipt_payload')) {
    /**
     * @param array|null $row  sas_transactions row (may carry product_name/api_type from joins)
     * @param bool       $admin true for bc-admin / bc-spadmin (staff) views
     * @param string     $site_title brand shown on the receipt
     */
    function bc_receipt_payload($row, $admin, $site_title)
    {
        if (!is_array($row) || empty($row['reference'])) {
            return array('ok' => false, 'message' => 'Transaction not found.');
        }

        // Friendly service name: product + service category — never the provider.
        if (!empty($row['product_name']) && !empty($row['api_type'])) {
            $service = ucwords($row['product_name'] . ' ' . str_replace(array('-', '_'), ' ', $row['api_type']));
        } elseif (!empty($row['type_alternative'])) {
            $service = ucwords($row['type_alternative']);
        } else {
            $service = 'Transaction';
        }

        $status_code = (int)($row['status'] ?? 3);
        $payload = array(
            'ok' => true,
            'admin' => (bool)$admin,
            'site' => (string)$site_title,
            'service' => $service,
            'recipient' => (string)($row['product_unique_id'] ?? ''),
            'reference' => (string)$row['reference'],
            'amount' => number_format((float)($row['discounted_amount'] ?? $row['amount'] ?? 0), 2),
            'face_amount' => number_format((float)($row['amount'] ?? 0), 2),
            'status_code' => $status_code,
            'status_label' => function_exists('tranStatus') ? tranStatus($status_code) : (string)$status_code,
            'date' => !empty($row['date']) ? date('M d, Y H:i', strtotime($row['date'])) : '',
            'balance_before' => isset($row['balance_before']) ? number_format((float)$row['balance_before'], 2) : null,
            'balance_after' => isset($row['balance_after']) ? number_format((float)$row['balance_after'], 2) : null,
        );

        // Only allowlisted, customer-facing utility details; never a raw provider body.
        $utility = $row['utility_purchase'] ?? null;
        if (is_array($utility)) {
            $prepaid = strtolower(trim((string)($utility['meter_type'] ?? ''))) === 'prepaid';
            $payload['utility'] = [
                'customer_name' => (string)($utility['meter_owner_name'] ?? ''),
                'customer_address' => (string)($utility['meter_owner_address'] ?? ''),
                'meter_number' => (string)($utility['meter_number'] ?? $payload['recipient']),
                'meter_type' => (string)($utility['meter_type'] ?? ''),
                // Suppress even a stale upstream token on postpaid/unknown/failed purchases.
                'token' => $prepaid && $status_code === 1 ? (string)($utility['meter_token'] ?? '') : '',
                'units' => $prepaid && $status_code === 1 ? (string)($utility['token_unit'] ?? '') : '',
            ];
        }

        if ($admin) {
            // Investigation fields — staff only, never sent to customer payloads.
            $payload['username'] = (string)($row['username'] ?? '');
            $payload['vendor_id'] = (int)($row['vendor_id'] ?? 0);
            if (!empty($row['vendor_name'])) {
                $payload['vendor_name'] = (string)$row['vendor_name'];
            } elseif (!empty($row['vendor_business_name'])) {
                $payload['vendor_name'] = (string)$row['vendor_business_name'];
            }
            if (!empty($row['vendor_email'])) {
                $payload['vendor_email'] = (string)$row['vendor_email'];
            }
            if (!empty($row['vendor_website'])) {
                $payload['vendor_website'] = (string)$row['vendor_website'];
            }
            if (!empty($row['_source'])) {
                $payload['source'] = (string)$row['_source'];
            }
            $payload['description'] = (string)($row['description'] ?? '');
            $payload['provider_reference'] = (string)($row['api_reference'] ?? '');
            $payload['provider_site'] = (string)($row['api_website'] ?? '');
            $payload['mode'] = (string)($row['mode'] ?? '');
        }

        return $payload;
    }
}

if (!function_exists('bc_receipt_fetch')) {
    /** Fetch one transaction (with friendly-name joins) inside a caller-built scope. */
    function bc_receipt_fetch($connection_server, $scope_sql, $reference)
    {
        if (!($connection_server instanceof mysqli)) {
            return null;
        }
        $ref = mysqli_real_escape_string($connection_server, (string)$reference);
        $q = mysqli_query($connection_server, "SELECT t.*, p.product_name, a.api_type
            FROM sas_transactions t
            LEFT JOIN sas_products p ON p.vendor_id = t.vendor_id AND p.id = t.product_id
            LEFT JOIN sas_apis a ON a.vendor_id = t.vendor_id AND a.id = t.api_id
            WHERE $scope_sql AND t.reference = '$ref' LIMIT 1");
        if (!$q) {
            return null;
        }
        $row = mysqli_fetch_assoc($q);
        if ($row) {
            // Scope utility lookup to the SAME fetched transaction owner and tenant.
            $vid=(int)$row['vendor_id'];
            $uname=mysqli_real_escape_string($connection_server,(string)$row['username']);
            $utility=mysqli_query($connection_server,"SELECT * FROM sas_electric_purchaseds WHERE vendor_id='$vid' AND username='$uname' AND reference='$ref' ORDER BY id DESC LIMIT 1");
            if ($utility && ($detail=mysqli_fetch_assoc($utility))) $row['utility_purchase']=$detail;
        }
        return $row ?: null;
    }
}

if (!function_exists('bc_receipt_fetch_spadmin')) {
    /**
     * Fetch either a user transaction (sas_transactions) or a vendor transaction
     * (sas_vendor_transactions) with vendor identity joined for super-admin receipts.
     */
    function bc_receipt_fetch_spadmin($connection_server, $reference, $source = '')
    {
        if (!($connection_server instanceof mysqli)) {
            return null;
        }
        $ref = mysqli_real_escape_string($connection_server, (string)$reference);
        $source = strtolower(trim((string)$source));

        $fetch_user = function () use ($connection_server, $ref) {
            $q = mysqli_query($connection_server, "SELECT t.*, p.product_name, a.api_type,
                TRIM(CONCAT(COALESCE(v.firstname,''), ' ', COALESCE(v.lastname,''))) AS vendor_name,
                '' AS vendor_business_name,
                v.email AS vendor_email,
                v.website_url AS vendor_website
                FROM sas_transactions t
                LEFT JOIN sas_vendors v ON v.id = t.vendor_id
                LEFT JOIN sas_products p ON p.vendor_id = t.vendor_id AND p.id = t.product_id
                LEFT JOIN sas_apis a ON a.vendor_id = t.vendor_id AND a.id = t.api_id
                WHERE t.reference = '$ref' LIMIT 1");
            $row = $q ? mysqli_fetch_assoc($q) : null;
            if ($row) {
                $row['_source'] = 'user';
                $vid = (int)$row['vendor_id'];
                $uname = mysqli_real_escape_string($connection_server, (string)($row['username'] ?? ''));
                $utility = mysqli_query($connection_server, "SELECT * FROM sas_electric_purchaseds WHERE vendor_id='$vid' AND username='$uname' AND reference='$ref' ORDER BY id DESC LIMIT 1");
                if ($utility && ($detail = mysqli_fetch_assoc($utility))) {
                    $row['utility_purchase'] = $detail;
                }
            }
            return $row ?: null;
        };

        $fetch_vendor = function () use ($connection_server, $ref) {
            $q = mysqli_query($connection_server, "SELECT t.*,
                TRIM(CONCAT(COALESCE(v.firstname,''), ' ', COALESCE(v.lastname,''))) AS vendor_name,
                '' AS vendor_business_name,
                v.email AS vendor_email,
                v.website_url AS vendor_website
                FROM sas_vendor_transactions t
                LEFT JOIN sas_vendors v ON v.id = t.vendor_id
                WHERE t.reference = '$ref' LIMIT 1");
            $row = $q ? mysqli_fetch_assoc($q) : null;
            if ($row) {
                $row['_source'] = 'vendor';
            }
            return $row ?: null;
        };

        if ($source === 'vendor') {
            return $fetch_vendor() ?: $fetch_user();
        }
        if ($source === 'user') {
            return $fetch_user() ?: $fetch_vendor();
        }
        return $fetch_user() ?: $fetch_vendor();
    }
}

/** Shared customer receipt details for native screens/PDF/image exporters. */
function bc_receipt_detail_lines($payload) {
    $lines=[];
    if (!empty($payload['recipient'])) $lines[]=['label'=>'Credited number / destination','value'=>(string)$payload['recipient']];
    if (!empty($payload['utility'])) {
        foreach (['customer_name'=>'Customer name','customer_address'=>'Customer address','meter_number'=>'Meter number','meter_type'=>'Meter type'] as $key=>$label) {
            $lines[]=['label'=>$label,'value'=>(string)($payload['utility'][$key] ?: 'Not supplied')];
        }
        foreach (['token'=>'Prepaid token','units'=>'Units'] as $key=>$label) {
            if (!empty($payload['utility'][$key])) $lines[]=['label'=>$label,'value'=>(string)$payload['utility'][$key]];
        }
    }
    return $lines;
}
