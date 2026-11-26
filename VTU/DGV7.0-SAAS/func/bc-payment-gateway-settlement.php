<?php
/**
 * Shared server-verified payment gateway checkout, webhook, callback, and
 * Service-Control-enforced virtual bank helpers for DGV7.0-SAAS & DGV7.0-NON-SAAS.
 *
 * Security invariants:
 *  - Every checkout binds (reference, vendor_id, username, provider, currency, amount)
 *    in sas_secure_payment_intents + sas_user_payment_checkouts on the server.
 *  - Wallet credits occur ONLY after verifying the transaction server-to-server
 *    against the payment gateway API (or verified signature + provider check) and
 *    rejecting test/sandbox/unpaid/underpaid/cross-tenant deliveries.
 *  - All balance mutations go through bc_fintech_ledger_post() so InnoDB locking
 *    and sas_provider_settlement_claims prevent double-credits across concurrent
 *    webhooks, return callbacks, and mobile verify-funding polls.
 *  - Service Control Center (sas_service_control) and sas_payment_gateways.status=1
 *    are enforced consistently across web, API, and virtual-bank syncing/listing.
 */

if (!defined('BC_PG_SETTLEMENT_LOADED')) {
    define('BC_PG_SETTLEMENT_LOADED', true);

    require_once __DIR__ . '/bc-fintech-schema.php';
    require_once __DIR__ . '/bc-fintech-ledger.php';
    require_once __DIR__ . '/bc-fintech-providers.php';
    require_once __DIR__ . '/bc-fintech-webhook.php';
    require_once __DIR__ . '/bc-fintech-flutterwave.php';
    require_once __DIR__ . '/bc-incident-wallet.php';

    function bc_pg_supported_checkout_gateways()
    {
        return ['paystack', 'payhub', 'monnify', 'flutterwave', 'beewave'];
    }

    function bc_pg_supported_va_gateways()
    {
        return ['payhub', 'monnify', 'payvessel', 'beewave', 'paystack'];
    }

    function bc_pg_clamp_markup($value, $default = 0.5)
    {
        if ($value === null || $value === '' || !is_numeric($value)) {
            return round((float)$default, 2);
        }
        $num = (float)$value;
        if (!is_finite($num) || $num < 0.0) return 0.0;
        if ($num > 1.0) return 1.0;
        return round($num, 2);
    }

    function bc_pg_format_pricing_label(array $rule)
    {
        $pct = round((float)($rule['percent'] ?? 0), 2);
        $flat = round((float)($rule['flat'] ?? 0), 2);
        $waive = round((float)($rule['waive_flat_below'] ?? 0), 2);
        $cap = round((float)($rule['cap'] ?? 0), 2);
        $surcharge = round((float)($rule['checkout_surcharge_percent'] ?? 0), 2);
        $pct_str = rtrim(rtrim(number_format($pct, 2, '.', ''), '0'), '.') . '%';

        if (($rule['mode'] ?? '') === 'payhub_contract') {
            $parts = [];
            if ($surcharge > 0) {
                $parts[] = '~' . rtrim(rtrim(number_format($surcharge, 2, '.', ''), '0'), '.') . '% checkout';
            }
            $parts[] = $pct_str . ($flat > 0 ? (' + ₦' . number_format($flat, 0)) : '') . ' PayHub fee';
            if ($cap > 0) {
                $parts[] = 'cap ₦' . number_format($cap, 0);
            }
            return implode(' + ', $parts);
        }

        $label = $pct_str;
        if ($flat > 0) {
            $label .= ' + ₦' . number_format($flat, 0);
        }
        $notes = [];
        if ($flat > 0 && $waive > 0) {
            $notes[] = '₦' . number_format($flat, 0) . ' waived < ₦' . number_format($waive, 0);
        }
        if ($cap > 0) {
            $notes[] = 'cap ₦' . number_format($cap, 0);
        }
        if (!empty($notes)) {
            $label .= ' (' . implode(', ', $notes) . ')';
        }
        return $label;
    }

    function bc_pg_builtin_gateway_pricing()
    {
        $defaults = [
            'paystack' => [
                'percent' => 1.5,
                'flat' => 100.0,
                'waive_flat_below' => 2500.0,
                'cap' => 2000.0,
                'checkout_surcharge_percent' => 0.0,
                'mode' => 'checkout_fee',
            ],
            'monnify' => [
                'percent' => 1.5,
                'flat' => 0.0,
                'waive_flat_below' => 0.0,
                'cap' => 2000.0,
                'checkout_surcharge_percent' => 0.0,
                'mode' => 'checkout_fee',
            ],
            'flutterwave' => [
                'percent' => 1.4,
                'flat' => 0.0,
                'waive_flat_below' => 0.0,
                'cap' => 2000.0,
                'checkout_surcharge_percent' => 0.0,
                'mode' => 'checkout_fee',
            ],
            'beewave' => [
                'percent' => 1.5,
                'flat' => 0.0,
                'waive_flat_below' => 0.0,
                'cap' => 2000.0,
                'checkout_surcharge_percent' => 0.0,
                'mode' => 'checkout_fee',
            ],
            'payvessel' => [
                'percent' => 0.5,
                'flat' => 0.0,
                'waive_flat_below' => 0.0,
                'cap' => 1000.0,
                'checkout_surcharge_percent' => 0.0,
                'mode' => 'va_fee',
            ],
            'payhub' => [
                'percent' => 2.0,
                'flat' => 0.0,
                'waive_flat_below' => 0.0,
                'cap' => 2000.0,
                'checkout_surcharge_percent' => 1.53,
                'mode' => 'payhub_contract',
            ],
            'platnova' => [
                'percent' => 1.5,
                'flat' => 0.0,
                'waive_flat_below' => 0.0,
                'cap' => 2000.0,
                'checkout_surcharge_percent' => 0.0,
                'mode' => 'checkout_fee',
            ],
        ];
        foreach ($defaults as $k => $rule) {
            $defaults[$k]['label'] = bc_pg_format_pricing_label($rule);
        }
        return $defaults;
    }

    function bc_pg_default_gateway_pricing($db = null)
    {
        $defaults = bc_pg_builtin_gateway_pricing();
        if (!($db instanceof mysqli)) {
            return $defaults;
        }
        static $cached = [];
        $db_id = function_exists('spl_object_id') ? spl_object_id($db) : 1;
        if (isset($cached[$db_id])) {
            return $cached[$db_id];
        }
        $q = @mysqli_query($db, "SELECT option_value FROM sas_super_admin_options WHERE option_name='bc_pg_gateway_pricing' LIMIT 1");
        if ($q && ($row = mysqli_fetch_assoc($q))) {
            $decoded = json_decode((string)($row['option_value'] ?? ''), true);
            if (is_array($decoded)) {
                foreach ($decoded as $gw => $custom) {
                    $gw_key = strtolower(trim((string)$gw));
                    if (!isset($defaults[$gw_key]) || !is_array($custom)) continue;
                    foreach (['percent', 'flat', 'waive_flat_below', 'cap', 'checkout_surcharge_percent'] as $num_key) {
                        if (isset($custom[$num_key]) && is_numeric($custom[$num_key])) {
                            $defaults[$gw_key][$num_key] = max(0.0, round((float)$custom[$num_key], 4));
                        }
                    }
                    $defaults[$gw_key]['label'] = bc_pg_format_pricing_label($defaults[$gw_key]);
                }
            }
        }
        $cached[$db_id] = $defaults;
        return $defaults;
    }

    function bc_pg_save_gateway_pricing_overrides($db, array $pricing_map)
    {
        if (!($db instanceof mysqli)) return false;
        $base = bc_pg_builtin_gateway_pricing();
        $clean = [];
        foreach ($base as $gw => $def) {
            $in = is_array($pricing_map[$gw] ?? null) ? $pricing_map[$gw] : [];
            $clean[$gw] = [
                'percent' => isset($in['percent']) && is_numeric($in['percent']) ? max(0.0, min(10.0, round((float)$in['percent'], 3))) : $def['percent'],
                'flat' => isset($in['flat']) && is_numeric($in['flat']) ? max(0.0, min(5000.0, round((float)$in['flat'], 2))) : $def['flat'],
                'waive_flat_below' => isset($in['waive_flat_below']) && is_numeric($in['waive_flat_below']) ? max(0.0, min(100000.0, round((float)$in['waive_flat_below'], 2))) : $def['waive_flat_below'],
                'cap' => isset($in['cap']) && is_numeric($in['cap']) ? max(0.0, min(50000.0, round((float)$in['cap'], 2))) : $def['cap'],
                'checkout_surcharge_percent' => isset($in['checkout_surcharge_percent']) && is_numeric($in['checkout_surcharge_percent']) ? max(0.0, min(10.0, round((float)$in['checkout_surcharge_percent'], 3))) : $def['checkout_surcharge_percent'],
            ];
        }
        $json = mysqli_real_escape_string($db, json_encode($clean));
        return (bool)@mysqli_query(
            $db,
            "INSERT INTO sas_super_admin_options (option_name, option_value)
             VALUES ('bc_pg_gateway_pricing', '$json')
             ON DUPLICATE KEY UPDATE option_value='$json'"
        );
    }

    function bc_pg_sync_live_gateway_pricing($db, $vendor_id = 0)
    {
        $vid = (int)$vendor_id;
        $pricing = bc_pg_default_gateway_pricing($db);
        $live_synced = [];

        // If PayHub exposes a pricing/fees endpoint in the active environment, fetch and merge it safely.
        if (function_exists('makePayhubRequest') && $vid > 0) {
            try {
                $raw = makePayhubRequest('GET', 'api/pricing', [], $vid);
                $outer = is_string($raw) ? json_decode($raw, true) : (is_array($raw) ? $raw : null);
                $res = (is_array($outer) && !empty($outer['json_result']) && is_string($outer['json_result']))
                    ? json_decode($outer['json_result'], true)
                    : $outer;
                $pdata = is_array($res['data'] ?? null) ? $res['data'] : (is_array($res) ? $res : null);
                if (is_array($outer) && (($outer['status'] ?? '') === 'success') && is_array($pdata)) {
                    $updated = false;
                    if (isset($pdata['percent']) && is_numeric($pdata['percent'])) {
                        $pricing['payhub']['percent'] = max(0.0, min(10.0, round((float)$pdata['percent'], 3)));
                        $updated = true;
                    }
                    if (isset($pdata['checkout_surcharge_percent']) && is_numeric($pdata['checkout_surcharge_percent'])) {
                        $pricing['payhub']['checkout_surcharge_percent'] = max(0.0, min(10.0, round((float)$pdata['checkout_surcharge_percent'], 3)));
                        $updated = true;
                    }
                    if (isset($pdata['cap']) && is_numeric($pdata['cap'])) {
                        $pricing['payhub']['cap'] = max(0.0, min(50000.0, round((float)$pdata['cap'], 2)));
                        $updated = true;
                    }
                    if ($updated) {
                        bc_pg_save_gateway_pricing_overrides($db, $pricing);
                        $live_synced[] = 'payhub';
                    }
                }
            } catch (Throwable $e) {
                // Non-fatal fallback to built-in/super-admin pricing table.
            }
        }

        // Initialize any unset or invalid legacy (> 1%) markup on this vendor to default 0.5%.
        $migrated = 0;
        if (($db instanceof mysqli) && $vid > 0) {
            $q = @mysqli_query($db, "SELECT gateway_name, percentage FROM sas_payment_gateways WHERE vendor_id='$vid'");
            if ($q) {
                while ($r = mysqli_fetch_assoc($q)) {
                    $raw_pct = trim((string)($r['percentage'] ?? ''));
                    if ($raw_pct === '' || !is_numeric($raw_pct) || (float)$raw_pct > 1.0 || (float)$raw_pct < 0.0) {
                        $gw_esc = mysqli_real_escape_string($db, (string)$r['gateway_name']);
                        @mysqli_query($db, "UPDATE sas_payment_gateways SET percentage='0.5' WHERE vendor_id='$vid' AND gateway_name='$gw_esc'");
                        $migrated++;
                    }
                }
            }
        }

        return [
            'ok' => true,
            'live_synced' => $live_synced,
            'default_markups_initialized' => $migrated,
            'pricing' => bc_pg_default_gateway_pricing($db),
        ];
    }

    function bc_pg_compute_gateway_fee(array $rule, $gross_amount)
    {
        $gross = max(0.0, round((float)$gross_amount, 2));
        if ($gross <= 0.0) return 0.0;
        $pct = max(0.0, (float)($rule['percent'] ?? 0));
        $flat = max(0.0, (float)($rule['flat'] ?? 0));
        $waive_below = max(0.0, (float)($rule['waive_flat_below'] ?? 0));
        $cap = max(0.0, (float)($rule['cap'] ?? 0));

        $apply_flat = ($flat > 0.0 && ($waive_below <= 0.0 || $gross >= $waive_below)) ? $flat : 0.0;
        $fee = ($gross * ($pct / 100.0)) + $apply_flat;
        if ($cap > 0.0) {
            $fee = min($cap, $fee);
        }
        return round(max(0.0, $fee), 2);
    }

    function bc_pg_solve_gross_for_net(array $rule, $net_needed)
    {
        $net = max(0.0, round((float)$net_needed, 2));
        if ($net <= 0.0) return 0.0;
        $pct = max(0.0, (float)($rule['percent'] ?? 0));
        $rate = $pct / 100.0;
        $flat = max(0.0, (float)($rule['flat'] ?? 0));
        $waive_below = max(0.0, (float)($rule['waive_flat_below'] ?? 0));
        $cap = max(0.0, (float)($rule['cap'] ?? 0));

        if ($rate >= 0.99) {
            return $net;
        }

        $candidates = [];
        // Candidate 1: without flat fee (when waived below threshold)
        if ($rate > 0.0) {
            $c1 = round($net / (1.0 - $rate), 2);
            if ($waive_below <= 0.0 || $c1 < $waive_below) {
                $candidates[] = $c1;
            }
        } else {
            $candidates[] = $net;
        }
        // Candidate 2: with flat fee
        $c2 = round(($net + $flat) / (1.0 - $rate), 2);
        $candidates[] = $c2;
        // Candidate 3: capped fee
        if ($cap > 0.0) {
            $candidates[] = round($net + $cap, 2);
        }

        $best = null;
        foreach ($candidates as $cand) {
            if ($cand < $net) continue;
            // Nudge up by at most 2 kobo if rounding made net slightly short
            for ($k = 0; $k <= 3; $k++) {
                $try = round($cand + ($k * 0.01), 2);
                $fee = bc_pg_compute_gateway_fee($rule, $try);
                if (round($try - $fee, 2) >= $net) {
                    if ($best === null || $try < $best) {
                        $best = $try;
                    }
                    break;
                }
            }
        }
        return $best !== null ? $best : $net;
    }

    function bc_pg_calculate_fee_breakdown($db, $gateway, $entered_amount, $markup_pct = null, $fee_mode = 'customer_pays', $max_markup = 1.0)
    {
        $gw = strtolower(trim((string)$gateway));
        $entered = max(0.0, round((float)$entered_amount, 2));
        $fee_mode = ($fee_mode === 'deduct_from_deposit') ? 'deduct_from_deposit' : 'customer_pays';

        $pricing = bc_pg_default_gateway_pricing($db);
        $rule = $pricing[$gw] ?? [
            'percent' => 1.5,
            'flat' => 0.0,
            'waive_flat_below' => 0.0,
            'cap' => 2000.0,
            'checkout_surcharge_percent' => 0.0,
            'mode' => 'checkout_fee',
            'label' => '1.5% (capped at ₦2,000)',
        ];

        $markup = ((float)$max_markup > 1.0)
            ? bc_pg_clamp_platform_markup($markup_pct, 0.0)
            : bc_pg_clamp_markup($markup_pct, 0.5);
        $surcharge_pct = max(0.0, (float)($rule['checkout_surcharge_percent'] ?? 0));

        if ($fee_mode === 'customer_pays') {
            $wallet_credit = $entered;
            $vendor_markup = round($wallet_credit * ($markup / 100.0), 2);
            $net_needed = round($wallet_credit + $vendor_markup, 2);
            $request_amount = bc_pg_solve_gross_for_net($rule, $net_needed);
            $gateway_fee = round(max(0.0, $request_amount - $net_needed), 2);
            $checkout_surcharge = ($surcharge_pct > 0.0) ? round($request_amount * ($surcharge_pct / 100.0), 2) : 0.0;
            $user_pay_amount = round($request_amount + $checkout_surcharge, 2);
            $total_fee = round($gateway_fee + $checkout_surcharge + $vendor_markup, 2);
        } else {
            $request_amount = $entered;
            $checkout_surcharge = ($surcharge_pct > 0.0) ? round($request_amount * ($surcharge_pct / 100.0), 2) : 0.0;
            $user_pay_amount = round($request_amount + $checkout_surcharge, 2);
            $gateway_fee = bc_pg_compute_gateway_fee($rule, $request_amount);
            $vendor_markup = round($entered * ($markup / 100.0), 2);
            $wallet_credit = round(max(0.0, $request_amount - $gateway_fee - $vendor_markup), 2);
            $total_fee = round($gateway_fee + $checkout_surcharge + $vendor_markup, 2);
        }

        return [
            'gateway' => $gw,
            'fee_mode' => $fee_mode,
            'entered_amount' => $entered,
            'markup_percent' => $markup,
            'gateway_fee' => $gateway_fee,
            'checkout_surcharge' => $checkout_surcharge,
            'vendor_markup' => $vendor_markup,
            'total_fee' => $total_fee,
            'request_amount' => $request_amount,
            'charge_amount' => $request_amount,
            'user_pay_amount' => $user_pay_amount,
            'wallet_credit' => $wallet_credit,
            'gateway_fee_label' => (string)($rule['label'] ?? ''),
            'gateway_fee_rule' => [
                'percent' => (float)($rule['percent'] ?? 0),
                'flat' => (float)($rule['flat'] ?? 0),
                'waive_flat_below' => (float)($rule['waive_flat_below'] ?? 0),
                'cap' => (float)($rule['cap'] ?? 0),
                'checkout_surcharge_percent' => $surcharge_pct,
                'mode' => (string)($rule['mode'] ?? 'checkout_fee'),
            ],
        ];
    }

    function bc_pg_ensure_tables($db)
    {
        if (!($db instanceof mysqli)) return false;
        static $pg_schema_ready = [];
        $db_id = function_exists('spl_object_id') ? spl_object_id($db) : 1;
        if (!empty($pg_schema_ready[$db_id])) return true;
        if (function_exists('bc_fintech_ensure_schema') && !bc_fintech_ensure_schema()) {
            return false;
        }
        $ddls = [
            "CREATE TABLE IF NOT EXISTS sas_secure_payment_intents (
                reference VARCHAR(100) NOT NULL PRIMARY KEY,
                vendor_id INT UNSIGNED NOT NULL,
                username VARCHAR(225) NOT NULL,
                target VARCHAR(16) NOT NULL DEFAULT 'user',
                purpose VARCHAR(40) NOT NULL DEFAULT 'wallet_funding',
                provider VARCHAR(32) NOT NULL,
                currency VARCHAR(10) NOT NULL,
                amount DECIMAL(20,2) NOT NULL,
                provider_reference VARCHAR(100) NULL DEFAULT NULL,
                credit_amount DECIMAL(20,2) NULL DEFAULT NULL,
                gateway_fee DECIMAL(20,2) NULL DEFAULT NULL,
                vendor_markup DECIMAL(20,2) NULL DEFAULT NULL,
                fee_mode VARCHAR(32) NULL DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB",
            "CREATE TABLE IF NOT EXISTS sas_provider_settlement_claims (
                settlement_key CHAR(64) NOT NULL PRIMARY KEY,
                payload_hash CHAR(64) NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB",
            "CREATE TABLE IF NOT EXISTS sas_user_payment_checkouts (
                id INT NOT NULL AUTO_INCREMENT,
                vendor_id INT UNSIGNED NOT NULL,
                username VARCHAR(225) NOT NULL,
                reference VARCHAR(225) NOT NULL,
                status INT UNSIGNED NOT NULL,
                payment_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_upc_ref (reference),
                KEY idx_upc_vendor (vendor_id)
            ) ENGINE=InnoDB",
        ];
        foreach ($ddls as $sql) {
            if (@mysqli_query($db, $sql) === false) {
                return false;
            }
        }
        $intent_extra_cols = [
            'target' => "VARCHAR(16) NOT NULL DEFAULT 'user' AFTER username",
            'purpose' => "VARCHAR(40) NOT NULL DEFAULT 'wallet_funding' AFTER target",
            'provider_reference' => "VARCHAR(100) NULL DEFAULT NULL AFTER amount",
            'credit_amount' => "DECIMAL(20,2) NULL DEFAULT NULL AFTER provider_reference",
            'gateway_fee' => "DECIMAL(20,2) NULL DEFAULT NULL AFTER credit_amount",
            'vendor_markup' => "DECIMAL(20,2) NULL DEFAULT NULL AFTER gateway_fee",
            'fee_mode' => "VARCHAR(32) NULL DEFAULT NULL AFTER vendor_markup",
        ];
        foreach ($intent_extra_cols as $col_name => $col_def) {
            $chk_col = @mysqli_query($db, "SHOW COLUMNS FROM `sas_secure_payment_intents` LIKE '$col_name'");
            if ($chk_col && mysqli_num_rows($chk_col) === 0) {
                @mysqli_query($db, "ALTER TABLE `sas_secure_payment_intents` ADD COLUMN `$col_name` $col_def");
            }
        }
        $chk_ar = @mysqli_query($db, "SHOW COLUMNS FROM `sas_transactions` LIKE 'api_reference'");
        if ($chk_ar && mysqli_num_rows($chk_ar) === 0) {
            @mysqli_query($db, "ALTER TABLE `sas_transactions` ADD COLUMN api_reference VARCHAR(225) AFTER reference");
        }
        $innodb_tables = [
            'sas_users',
            'sas_vendors',
            'sas_transactions',
            'sas_vendor_transactions',
            'sas_wallet_mutation_events',
            'sas_fintech_ledger',
            'sas_webhook_events',
            'sas_provider_settlement_claims',
            'sas_secure_payment_intents',
            'sas_user_payment_checkouts',
        ];
        $in_list = "'" . implode("','", $innodb_tables) . "'";
        $q_eng = @mysqli_query($db, "SELECT TABLE_NAME, ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ($in_list)");
        if ($q_eng) {
            $non_innodb = [];
            while ($r_eng = mysqli_fetch_assoc($q_eng)) {
                if (strtoupper((string)($r_eng['ENGINE'] ?? '')) !== 'INNODB') {
                    $non_innodb[] = $r_eng['TABLE_NAME'];
                }
            }
            mysqli_free_result($q_eng);
            if (!empty($non_innodb)) {
                $orig_mode = null;
                $qm = @mysqli_query($db, "SELECT @@SESSION.sql_mode AS m");
                if ($qm && ($rm = mysqli_fetch_assoc($qm))) $orig_mode = $rm['m'];
                foreach ($non_innodb as $tbl) {
                    if (!@mysqli_query($db, "ALTER TABLE `$tbl` ENGINE=InnoDB")) {
                        if ($orig_mode !== null) @mysqli_query($db, "SET SESSION sql_mode=''");
                        @mysqli_query($db, "ALTER TABLE `$tbl` ENGINE=InnoDB");
                        if ($orig_mode !== null) @mysqli_query($db, "SET SESSION sql_mode='" . mysqli_real_escape_string($db, $orig_mode) . "'");
                    }
                }
            }
        }
        $pg_schema_ready[$db_id] = true;
        return true;
    }

    function bc_pg_infer_bank_gateway(array $row)
    {
        $gw = strtolower(trim((string)($row['gateway_name'] ?? '')));
        if ($gw !== '') return $gw;

        $bank_code = strtoupper(trim((string)($row['bank_code'] ?? '')));
        $bank_name = strtoupper(trim((string)($row['bank_name'] ?? '')));
        $acct_name = strtoupper(trim((string)($row['account_name'] ?? '')));
        $ref = strtoupper(trim((string)($row['reference'] ?? '')));

        if (strcasecmp($bank_code, 'PayHub') === 0 || strpos($bank_name, 'PAYHUB') !== false || strpos($ref, 'PAYHUB') !== false) {
            return 'payhub';
        }
        if ($bank_code === '110072' || strpos($bank_name, 'BEEWAVE') !== false || strpos($acct_name, 'BEEWAVE') !== false) {
            return 'beewave';
        }
        if (strpos($bank_name, 'PAYSTACK') !== false || strcasecmp($bank_code, 'Paystack') === 0) {
            return 'paystack';
        }
        if ($bank_code === '101' || $bank_code === '120001' || strpos($bank_name, '9PSB') !== false || strpos($bank_name, '9 PAYMENT') !== false || strpos($bank_name, 'GLOBUS') !== false || strpos($bank_name, 'TITAN') !== false || strpos($bank_name, 'PAYVESSEL') !== false) {
            return 'payvessel';
        }
        if (strpos($bank_name, 'WEMA') !== false || strpos($bank_name, 'VFD') !== false || strpos($bank_name, 'MONNIFY') !== false || strpos($bank_name, 'STERLING') !== false || strpos($bank_name, 'MONIEPOINT') !== false) {
            return 'monnify';
        }
        return '';
    }

    function bc_pg_gateway_config($db, $vendor_id, $gateway, $require_active = true)
    {
        if (!($db instanceof mysqli)) return null;
        $vid = (int)$vendor_id;
        $gw = strtolower(trim((string)$gateway));
        if ($vid <= 0 || $gw === '') return null;
        $gw_esc = mysqli_real_escape_string($db, $gw);
        $status_clause = $require_active ? " AND status='1'" : "";
        $q = mysqli_query(
            $db,
            "SELECT * FROM sas_payment_gateways
             WHERE vendor_id='$vid' AND LOWER(TRIM(gateway_name))='$gw_esc'$status_clause
             ORDER BY (TRIM(COALESCE(secret_key,''))<>'' OR TRIM(COALESCE(public_key,''))<>'') DESC, id DESC
             LIMIT 1"
        );
        if (!$q || mysqli_num_rows($q) !== 1) return null;
        return mysqli_fetch_assoc($q);
    }

    function bc_pg_is_gateway_enabled($db, $vendor_id, $gateway)
    {
        $vid = (int)$vendor_id;
        $gw = strtolower(trim((string)$gateway));
        if ($vid <= 0 || $gw === '') return false;
        if (function_exists('isServiceEnabled') && !isServiceEnabled($gw, $vid)) {
            return false;
        }
        return bc_pg_gateway_config($db, $vid, $gw, true) !== null;
    }

    function bc_pg_bank_account_allowed($db, $vendor_id, array $row)
    {
        $vid = (int)$vendor_id;
        if ($vid <= 0) return false;
        if (isset($row['status']) && (int)$row['status'] !== 1) return false;
        if (function_exists('isServiceEnabled') && !isServiceEnabled('virtual_bank_display', $vid)) {
            return false;
        }
        $gw = bc_pg_infer_bank_gateway($row);
        if ($gw !== '' && !bc_pg_is_gateway_enabled($db, $vid, $gw)) {
            return false;
        }
        return true;
    }

    function bc_pg_list_enabled_gateways($db, $vendor_id)
    {
        $vid = (int)$vendor_id;
        if (!($db instanceof mysqli) || $vid <= 0) return [];
        $out = [];
        $labels = [
            'paystack' => 'Paystack (Card / Bank / USSD)',
            'payhub' => 'PayHub Checkout',
            'monnify' => 'Monnify (Card / Transfer / USSD)',
            'flutterwave' => 'Flutterwave (Card / Bank)',
            'beewave' => 'BeeWave Checkout',
        ];
        $pricing = bc_pg_default_gateway_pricing($db);
        foreach (bc_pg_supported_checkout_gateways() as $gw) {
            if (!bc_pg_is_gateway_enabled($db, $vid, $gw)) continue;
            $cfg = bc_pg_gateway_config($db, $vid, $gw, true);
            if (!$cfg) continue;
            $pub = trim((string)($cfg['public_key'] ?? ''));
            $sec = trim((string)($cfg['secret_key'] ?? ''));
            if ($gw === 'beewave' && $pub === '') continue;
            if ($gw !== 'beewave' && $sec === '') continue;
            if ($gw === 'monnify' && ($pub === '' || trim((string)($cfg['encrypt_key'] ?? '')) === '')) continue;

            $markup = bc_pg_clamp_markup($cfg['percentage'] ?? null, 0.5);
            $rule = $pricing[$gw] ?? [
                'percent' => 1.5,
                'flat' => 0.0,
                'waive_flat_below' => 0.0,
                'cap' => 2000.0,
                'checkout_surcharge_percent' => 0.0,
                'mode' => 'checkout_fee',
                'label' => '1.5% (capped at ₦2,000)',
            ];
            $out[$gw] = [
                'gateway_name' => $gw,
                'label' => $labels[$gw] ?? ucfirst($gw),
                'public_key' => $pub,
                'percentage' => $markup,
                'markup_percent' => $markup,
                'gateway_fee_label' => (string)($rule['label'] ?? ''),
                'gateway_fee_rule' => [
                    'percent' => (float)($rule['percent'] ?? 0),
                    'flat' => (float)($rule['flat'] ?? 0),
                    'waive_flat_below' => (float)($rule['waive_flat_below'] ?? 0),
                    'cap' => (float)($rule['cap'] ?? 0),
                    'checkout_surcharge_percent' => (float)($rule['checkout_surcharge_percent'] ?? 0),
                    'mode' => (string)($rule['mode'] ?? 'checkout_fee'),
                ],
                'fee_mode' => 'customer_pays',
            ];
        }
        return $out;
    }

    function bc_pg_clamp_platform_markup($value, $default = 0.0)
    {
        if ($value === null || $value === '' || !is_numeric($value)) {
            return round((float)$default, 2);
        }
        $num = (float)$value;
        if (!is_finite($num) || $num < 0.0) return 0.0;
        if ($num > 10.0) return 10.0;
        return round($num, 2);
    }

    function bc_pg_platform_gateway_config($db, $gateway, $require_active = true)
    {
        if (!($db instanceof mysqli)) return null;
        $gw = strtolower(trim((string)$gateway));
        if ($gw === '') return null;
        $gw_esc = mysqli_real_escape_string($db, $gw);
        $status_clause = $require_active ? " AND status='1'" : "";
        $q = mysqli_query(
            $db,
            "SELECT * FROM sas_super_admin_payment_gateways
             WHERE LOWER(TRIM(gateway_name))='$gw_esc'$status_clause
             ORDER BY (TRIM(COALESCE(secret_key,''))<>'' OR TRIM(COALESCE(public_key,''))<>'') DESC
             LIMIT 1"
        );
        if (!$q || mysqli_num_rows($q) !== 1) return null;
        return mysqli_fetch_assoc($q);
    }

    function bc_pg_is_platform_gateway_enabled($db, $gateway)
    {
        $gw = strtolower(trim((string)$gateway));
        if ($gw === '') return false;
        return bc_pg_platform_gateway_config($db, $gw, true) !== null;
    }

    function bc_pg_list_platform_gateways($db)
    {
        if (!($db instanceof mysqli)) return [];
        $out = [];
        $labels = [
            'paystack' => 'Paystack (Card / Bank / USSD)',
            'payhub' => 'PayHub Checkout',
            'monnify' => 'Monnify (Card / Transfer / USSD)',
            'flutterwave' => 'Flutterwave (Card / Bank)',
            'beewave' => 'BeeWave Checkout',
        ];
        $pricing = bc_pg_default_gateway_pricing($db);
        foreach (bc_pg_supported_checkout_gateways() as $gw) {
            if (!bc_pg_is_platform_gateway_enabled($db, $gw)) continue;
            $cfg = bc_pg_platform_gateway_config($db, $gw, true);
            if (!$cfg) continue;
            $pub = trim((string)($cfg['public_key'] ?? ''));
            $sec = trim((string)($cfg['secret_key'] ?? ''));
            if ($gw === 'beewave' && $pub === '') continue;
            if ($gw !== 'beewave' && $sec === '') continue;
            if ($gw === 'monnify' && ($pub === '' || trim((string)($cfg['encrypt_key'] ?? '')) === '')) continue;

            $markup = bc_pg_clamp_platform_markup($cfg['percentage'] ?? null, 0.0);
            $rule = $pricing[$gw] ?? [
                'percent' => 1.5,
                'flat' => 0.0,
                'waive_flat_below' => 0.0,
                'cap' => 2000.0,
                'checkout_surcharge_percent' => 0.0,
                'mode' => 'checkout_fee',
                'label' => '1.5% (capped at ₦2,000)',
            ];
            $out[$gw] = [
                'gateway_name' => $gw,
                'label' => $labels[$gw] ?? ucfirst($gw),
                'public_key' => $pub,
                'percentage' => $markup,
                'markup_percent' => $markup,
                'max_markup_percent' => 10,
                'gateway_fee_label' => (string)($rule['label'] ?? ''),
                'gateway_fee_rule' => [
                    'percent' => (float)($rule['percent'] ?? 0),
                    'flat' => (float)($rule['flat'] ?? 0),
                    'waive_flat_below' => (float)($rule['waive_flat_below'] ?? 0),
                    'cap' => (float)($rule['cap'] ?? 0),
                    'checkout_surcharge_percent' => (float)($rule['checkout_surcharge_percent'] ?? 0),
                    'mode' => (string)($rule['mode'] ?? 'checkout_fee'),
                ],
                'fee_mode' => 'customer_pays',
            ];
        }
        return $out;
    }

    function bc_pg_normalize_amount($value, $min = 100.0, $max = 1000000.0)
    {
        if (is_int($value) || is_float($value)) {
            $value = number_format((float)$value, 2, '.', '');
        }
        if (!is_string($value)) return null;
        $value = trim($value);
        if (!preg_match('/\A(?:0|[1-9][0-9]*)(?:\.[0-9]{1,2})?\z/', $value)) return null;
        if (strpos($value, '.') === false) {
            $minor = (int)$value * 100;
        } else {
            [$whole, $frac] = explode('.', $value, 2);
            $minor = ((int)$whole * 100) + (int)str_pad($frac, 2, '0', STR_PAD_RIGHT);
        }
        $min_minor = (int)round($min * 100);
        $max_minor = (int)round($max * 100);
        if ($minor < $min_minor || $minor > $max_minor) return null;
        return [
            'minor' => $minor,
            'decimal' => number_format($minor / 100, 2, '.', ''),
            'float' => $minor / 100,
        ];
    }

    function bc_pg_sanitize_reference($ref)
    {
        if (!is_string($ref)) return '';
        $ref = trim($ref);
        if ($ref === '' || !preg_match('/\A[A-Za-z0-9._:|-]{6,96}\z/', $ref)) {
            return '';
        }
        return $ref;
    }

    function bc_pg_register_intent($db, $vendor_id, $username, $provider, $amount_ngn, $preferred_ref = '', array $fee_meta = [])
    {
        if (!bc_pg_ensure_tables($db)) {
            return ['ok' => false, 'code' => 'SCHEMA_UNAVAILABLE', 'message' => 'Payment service is temporarily unavailable.'];
        }
        $vid = (int)$vendor_id;
        $username = trim((string)$username);
        $provider = strtolower(trim((string)$provider));
        $target = (($fee_meta['target'] ?? 'user') === 'vendor') ? 'vendor' : 'user';
        $norm = bc_pg_normalize_amount($amount_ngn);
        if ($vid <= 0 || $username === '' || $norm === null) {
            return ['ok' => false, 'code' => 'INVALID_INPUT', 'message' => 'Valid amount (NGN 100 - NGN 1,000,000) is required.'];
        }

        if ($provider !== '' && $provider !== 'pending') {
            if (!in_array($provider, bc_pg_supported_checkout_gateways(), true)) {
                return ['ok' => false, 'code' => 'UNSUPPORTED_GATEWAY', 'message' => 'Unsupported payment gateway.'];
            }
            $gw_ok = ($target === 'vendor')
                ? bc_pg_is_platform_gateway_enabled($db, $provider)
                : bc_pg_is_gateway_enabled($db, $vid, $provider);
            if (!$gw_ok) {
                return ['ok' => false, 'code' => 'GATEWAY_DISABLED', 'message' => ucfirst($provider) . ' payment gateway is currently disabled.'];
            }
        } else {
            $provider = 'pending';
        }

        $ref = bc_pg_sanitize_reference((string)$preferred_ref);
        if ($ref === '') {
            $ref = ($target === 'vendor' ? 'VCHK_' : 'CHK_') . bin2hex(random_bytes(10));
        }

        $ref_esc = mysqli_real_escape_string($db, $ref);
        $user_esc = mysqli_real_escape_string($db, $username);
        $prov_esc = mysqli_real_escape_string($db, $provider);
        $target_esc = mysqli_real_escape_string($db, $target);
        $purpose_val = trim((string)($fee_meta['purpose'] ?? 'wallet_funding'));
        if ($purpose_val === '') $purpose_val = 'wallet_funding';
        $purpose_esc = mysqli_real_escape_string($db, substr($purpose_val, 0, 40));
        $amt_sql = $norm['decimal'];

        $credit_sql = (isset($fee_meta['credit_amount']) && is_numeric($fee_meta['credit_amount']) && (float)$fee_meta['credit_amount'] > 0)
            ? "'" . number_format((float)$fee_meta['credit_amount'], 2, '.', '') . "'"
            : 'NULL';
        $gw_fee_sql = (isset($fee_meta['gateway_fee']) && is_numeric($fee_meta['gateway_fee']) && (float)$fee_meta['gateway_fee'] >= 0)
            ? "'" . number_format((float)$fee_meta['gateway_fee'], 2, '.', '') . "'"
            : 'NULL';
        $markup_sql = (isset($fee_meta['vendor_markup']) && is_numeric($fee_meta['vendor_markup']) && (float)$fee_meta['vendor_markup'] >= 0)
            ? "'" . number_format((float)$fee_meta['vendor_markup'], 2, '.', '') . "'"
            : 'NULL';
        $fee_mode_val = isset($fee_meta['fee_mode']) ? trim((string)$fee_meta['fee_mode']) : '';
        $fee_mode_sql = $fee_mode_val !== ''
            ? "'" . mysqli_real_escape_string($db, $fee_mode_val) . "'"
            : 'NULL';

        $existing_q = mysqli_query($db, "SELECT vendor_id, username, target, provider, amount FROM sas_secure_payment_intents WHERE reference='$ref_esc' LIMIT 1");
        if ($existing_q && mysqli_num_rows($existing_q) === 1) {
            $ex = mysqli_fetch_assoc($existing_q);
            $ex_target = (($ex['target'] ?? 'user') === 'vendor') ? 'vendor' : 'user';
            if ((int)$ex['vendor_id'] !== $vid || strcasecmp((string)$ex['username'], $username) !== 0 || $ex_target !== $target) {
                return ['ok' => false, 'code' => 'REFERENCE_CONFLICT', 'message' => 'Reference conflict. Please generate a fresh checkout.'];
            }
            if ($provider !== 'pending' && $ex['provider'] === 'pending') {
                mysqli_query($db, "UPDATE sas_secure_payment_intents SET provider='$prov_esc', purpose='$purpose_esc', amount='$amt_sql', credit_amount=$credit_sql, gateway_fee=$gw_fee_sql, vendor_markup=$markup_sql, fee_mode=$fee_mode_sql WHERE reference='$ref_esc' AND vendor_id='$vid' AND username='$user_esc' LIMIT 1");
            }
        } else {
            $ins = mysqli_query(
                $db,
                "INSERT INTO sas_secure_payment_intents (reference, vendor_id, username, target, purpose, provider, currency, amount, credit_amount, gateway_fee, vendor_markup, fee_mode)
                 VALUES ('$ref_esc', '$vid', '$user_esc', '$target_esc', '$purpose_esc', '$prov_esc', 'NGN', '$amt_sql', $credit_sql, $gw_fee_sql, $markup_sql, $fee_mode_sql)"
            );
            if (!$ins) {
                return ['ok' => false, 'code' => 'INTENT_WRITE_FAILED', 'message' => 'Could not create payment checkout intent.'];
            }
        }

        if ($target === 'user') {
            $chk_q = mysqli_query($db, "SELECT id, vendor_id, username, status FROM sas_user_payment_checkouts WHERE reference='$ref_esc' LIMIT 1");
            if (!$chk_q || mysqli_num_rows($chk_q) === 0) {
                mysqli_query(
                    $db,
                    "INSERT INTO sas_user_payment_checkouts (vendor_id, username, reference, status)
                     VALUES ('$vid', '$user_esc', '$ref_esc', 2)"
                );
            }
        }

        return [
            'ok' => true,
            'reference' => $ref,
            'amount' => $norm['decimal'],
            'amount_float' => $norm['float'],
            'amount_kobo' => $norm['minor'],
            'provider' => $provider,
        ];
    }

    function bc_pg_lookup_intent($db, $reference, $vendor_id = 0)
    {
        if (!($db instanceof mysqli)) return null;
        $ref = bc_pg_sanitize_reference((string)$reference);
        if ($ref === '') return null;
        $ref_esc = mysqli_real_escape_string($db, $ref);
        $vid_clause = $vendor_id > 0 ? " AND vendor_id='" . (int)$vendor_id . "'" : "";

        $q = mysqli_query($db, "SELECT * FROM sas_secure_payment_intents WHERE reference='$ref_esc'$vid_clause LIMIT 1");
        if ($q && mysqli_num_rows($q) === 1) {
            return mysqli_fetch_assoc($q);
        }
        $q2 = mysqli_query($db, "SELECT * FROM sas_user_payment_checkouts WHERE reference='$ref_esc'$vid_clause LIMIT 1");
        if ($q2 && mysqli_num_rows($q2) === 1) {
            $r = mysqli_fetch_assoc($q2);
            $r['target'] = 'user';
            $r['purpose'] = 'wallet_funding';
            $r['provider'] = '';
            $r['currency'] = 'NGN';
            $r['amount'] = null;
            return $r;
        }
        return null;
    }

    function bc_pg_http_json($method, $url, array $headers = [], $body = null, $timeout = 30)
    {
        if (!function_exists('curl_init')) {
            return ['ok' => false, 'http_code' => 0, 'error' => 'curl_unavailable', 'json' => null, 'raw' => ''];
        }
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, strtoupper($method));
        if (!empty($headers)) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        }
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, is_string($body) ? $body : json_encode($body));
        }
        $raw = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($raw === false || $err !== '') {
            return ['ok' => false, 'http_code' => $code, 'error' => $err ?: 'http_failed', 'json' => null, 'raw' => ''];
        }
        $json = json_decode($raw, true);
        return [
            'ok' => ($code >= 200 && $code < 300),
            'http_code' => $code,
            'error' => '',
            'json' => is_array($json) ? $json : null,
            'raw' => $raw,
        ];
    }

    function bc_pg_scheme_host()
    {
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443);
        $scheme = $https ? 'https' : 'http';
        $host = trim((string)($_SERVER['HTTP_HOST'] ?? 'localhost'));
        $host = preg_replace('/[^A-Za-z0-9.:-]/', '', $host);
        return $scheme . '://' . ($host !== '' ? $host : 'localhost');
    }

    function bc_pg_init_gateway_checkout($db, $vendor_id, array $user_row, $provider, $credit_amount_ngn, $scheme_host = '', $preferred_ref = '', $apply_fee_on_top = true)
    {
        $vid = (int)$vendor_id;
        $provider = strtolower(trim((string)$provider));
        $username = trim((string)($user_row['username'] ?? ''));
        if ($vid <= 0 || $username === '') {
            return ['ok' => false, 'code' => 'UNAUTHENTICATED', 'message' => 'User account not found.'];
        }
        if (!in_array($provider, bc_pg_supported_checkout_gateways(), true)) {
            return ['ok' => false, 'code' => 'UNSUPPORTED_GATEWAY', 'message' => 'Selected payment gateway is not supported.'];
        }
        if (!bc_pg_is_gateway_enabled($db, $vid, $provider)) {
            return ['ok' => false, 'code' => 'GATEWAY_DISABLED', 'message' => ucfirst($provider) . ' is currently disabled.'];
        }
        $cfg = bc_pg_gateway_config($db, $vid, $provider, true);
        if (!$cfg) {
            return ['ok' => false, 'code' => 'GATEWAY_UNCONFIGURED', 'message' => 'Payment gateway is not configured.'];
        }

        $norm = bc_pg_normalize_amount($credit_amount_ngn);
        if ($norm === null) {
            return ['ok' => false, 'code' => 'INVALID_AMOUNT', 'message' => 'Enter an amount between NGN 100 and NGN 1,000,000.'];
        }

        $entered_amount = $norm['float'];
        $markup_pct = bc_pg_clamp_markup($cfg['percentage'] ?? null, 0.5);
        $fee_mode = $apply_fee_on_top ? 'customer_pays' : 'deduct_from_deposit';
        $breakdown = bc_pg_calculate_fee_breakdown($db, $provider, $entered_amount, $markup_pct, $fee_mode);

        $credit_target = $breakdown['wallet_credit'];
        $charge_amount = $breakdown['charge_amount'];
        $user_pay_amount = $breakdown['user_pay_amount'];
        $gateway_fee = $breakdown['gateway_fee'];
        $vendor_markup = $breakdown['vendor_markup'];
        $fee = round($gateway_fee + $vendor_markup, 2);

        $intent_fee_meta = [
            'credit_amount' => $credit_target,
            'gateway_fee' => $gateway_fee,
            'vendor_markup' => $vendor_markup,
            'fee_mode' => $fee_mode,
        ];
        $intent = bc_pg_register_intent($db, $vid, $username, $provider, $charge_amount, $preferred_ref, $intent_fee_meta);
        if (empty($intent['ok'])) {
            return $intent;
        }
        $ref = $intent['reference'];
        $base_url = $scheme_host !== '' ? rtrim($scheme_host, '/') : bc_pg_scheme_host();

        $email = trim((string)($user_row['email'] ?? ''));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $email = preg_replace('/[^a-zA-Z0-9]/', '', $username) . '@wallet.local';
        }
        $full_name = trim((string)($user_row['firstname'] ?? '') . ' ' . (string)($user_row['lastname'] ?? ''));
        if ($full_name === '') $full_name = $username;
        $phone = trim((string)($user_row['phone_number'] ?? ''));

        $pub = trim((string)($cfg['public_key'] ?? ''));
        $sec = trim((string)($cfg['secret_key'] ?? ''));
        $enc = trim((string)($cfg['encrypt_key'] ?? ''));

        if ($provider === 'paystack') {
            if ($sec === '') {
                return ['ok' => false, 'code' => 'GATEWAY_UNCONFIGURED', 'message' => 'Paystack secret key is not configured.'];
            }
            $payload = [
                'email' => $email,
                'amount' => (int)round($charge_amount * 100),
                'currency' => 'NGN',
                'reference' => $ref,
                'callback_url' => $base_url . '/web/Fund.php?verify_gateway=paystack&reference=' . rawurlencode($ref),
                'metadata' => [
                    'vendor_id' => $vid,
                    'username' => $username,
                    'reference' => $ref,
                    'credit_amount' => $credit_target,
                    'gateway_fee' => $gateway_fee,
                    'vendor_markup' => $vendor_markup,
                    'fee_on_top' => $apply_fee_on_top ? 1 : 0,
                ],
            ];
            $res = bc_pg_http_json('POST', 'https://api.paystack.co/transaction/initialize', [
                'Authorization: Bearer ' . $sec,
                'Content-Type: application/json',
            ], $payload);
            $auth_url = trim((string)($res['json']['data']['authorization_url'] ?? ''));
            if (empty($res['ok']) || empty($res['json']['status']) || $auth_url === '') {
                return ['ok' => false, 'code' => 'GATEWAY_INIT_FAILED', 'message' => 'Could not initialize Paystack checkout. Please try again.'];
            }
            return [
                'ok' => true,
                'mode' => 'redirect',
                'provider' => 'paystack',
                'reference' => $ref,
                'charge_amount' => $charge_amount,
                'user_pay_amount' => $user_pay_amount,
                'credit_amount' => $credit_target,
                'fee' => $fee,
                'gateway_fee' => $gateway_fee,
                'vendor_markup' => $vendor_markup,
                'fee_mode' => $fee_mode,
                'authorization_url' => $auth_url,
            ];
        }

        if ($provider === 'payhub') {
            if (!function_exists('makePayhubRequest')) {
                return ['ok' => false, 'code' => 'GATEWAY_UNAVAILABLE', 'message' => 'PayHub client is unavailable.'];
            }
            $payload = [
                'amount' => $charge_amount,
                'email' => $email,
                'name' => $full_name,
                'phone' => $phone,
                'callback_url' => $base_url . '/web/payhub-success.php?local_reference=' . rawurlencode($ref),
                'metadata' => [
                    'vendor_id' => $vid,
                    'username' => $username,
                    'target' => 'user',
                    'local_reference' => $ref,
                    'credit_amount' => $credit_target,
                    'gateway_fee' => $gateway_fee,
                    'vendor_markup' => $vendor_markup,
                    'fee_on_top' => $apply_fee_on_top ? 1 : 0,
                ],
            ];
            $raw_ph = makePayhubRequest('POST', 'api/transaction/initialize', $payload, $vid);
            $outer = is_string($raw_ph) ? json_decode($raw_ph, true) : (is_array($raw_ph) ? $raw_ph : null);
            $ph = (is_array($outer) && !empty($outer['json_result']) && is_string($outer['json_result']))
                ? json_decode($outer['json_result'], true)
                : $outer;
            $auth_url = trim((string)($ph['data']['authorization_url'] ?? ''));
            $ph_ref = bc_pg_sanitize_reference((string)($ph['data']['reference'] ?? ''));
            $ph_ok = is_array($outer) && (($outer['status'] ?? '') === 'success') && is_array($ph) && !empty($ph['status']);
            if (!$ph_ok || $auth_url === '' || $ph_ref === '') {
                return ['ok' => false, 'code' => 'GATEWAY_INIT_FAILED', 'message' => 'Could not initialize PayHub checkout. Please try again.'];
            }
            // Bind the PayHub-issued reference to the exact same user, vendor, and fee breakdown,
            // and link provider_reference on the local_reference row for verify-funding polls.
            bc_pg_register_intent($db, $vid, $username, 'payhub', $charge_amount, $ph_ref, $intent_fee_meta);
            $loc_esc = mysqli_real_escape_string($db, $ref);
            $ph_esc = mysqli_real_escape_string($db, $ph_ref);
            @mysqli_query($db, "UPDATE sas_secure_payment_intents SET provider_reference='$ph_esc' WHERE reference='$loc_esc' AND vendor_id='$vid' LIMIT 1");
            return [
                'ok' => true,
                'mode' => 'redirect',
                'provider' => 'payhub',
                'reference' => $ph_ref,
                'local_reference' => $ref,
                'charge_amount' => $charge_amount,
                'user_pay_amount' => $user_pay_amount,
                'credit_amount' => $credit_target,
                'fee' => $fee,
                'gateway_fee' => $gateway_fee,
                'vendor_markup' => $vendor_markup,
                'fee_mode' => $fee_mode,
                'authorization_url' => $auth_url,
            ];
        }

        if ($provider === 'flutterwave') {
            if ($sec === '') {
                return ['ok' => false, 'code' => 'GATEWAY_UNCONFIGURED', 'message' => 'Flutterwave secret key is not configured.'];
            }
            $payload = [
                'tx_ref' => $ref,
                'amount' => $charge_amount,
                'currency' => 'NGN',
                'redirect_url' => $base_url . '/web/Fund.php?verify_gateway=flutterwave&tx_ref=' . rawurlencode($ref),
                'customer' => [
                    'email' => $email,
                    'phonenumber' => $phone,
                    'name' => $full_name,
                ],
                'meta' => [
                    'vendor_id' => $vid,
                    'username' => $username,
                    'reference' => $ref,
                    'credit_amount' => $credit_target,
                    'gateway_fee' => $gateway_fee,
                    'vendor_markup' => $vendor_markup,
                    'fee_on_top' => $apply_fee_on_top ? 1 : 0,
                ],
            ];
            $res = bc_pg_http_json('POST', 'https://api.flutterwave.com/v3/payments', [
                'Authorization: Bearer ' . $sec,
                'Content-Type: application/json',
            ], $payload);
            $link = trim((string)($res['json']['data']['link'] ?? ''));
            if (empty($res['ok']) || ($res['json']['status'] ?? '') !== 'success' || $link === '') {
                return ['ok' => false, 'code' => 'GATEWAY_INIT_FAILED', 'message' => 'Could not initialize Flutterwave checkout. Please try again.'];
            }
            return [
                'ok' => true,
                'mode' => 'redirect',
                'provider' => 'flutterwave',
                'reference' => $ref,
                'charge_amount' => $charge_amount,
                'user_pay_amount' => $user_pay_amount,
                'credit_amount' => $credit_target,
                'fee' => $fee,
                'gateway_fee' => $gateway_fee,
                'vendor_markup' => $vendor_markup,
                'fee_mode' => $fee_mode,
                'authorization_url' => $link,
            ];
        }

        if ($provider === 'monnify') {
            if ($pub === '' || $sec === '' || $enc === '') {
                return ['ok' => false, 'code' => 'GATEWAY_UNCONFIGURED', 'message' => 'Monnify API keys or Contract Code are not configured.'];
            }
            $auth_res = bc_pg_http_json('POST', 'https://api.monnify.com/api/v1/auth/login', [
                'Authorization: Basic ' . base64_encode($pub . ':' . $sec),
                'Content-Type: application/json',
            ], '{}');
            $token = trim((string)($auth_res['json']['responseBody']['accessToken'] ?? ''));
            if (empty($auth_res['ok']) || empty($auth_res['json']['requestSuccessful']) || $token === '') {
                return ['ok' => false, 'code' => 'GATEWAY_AUTH_FAILED', 'message' => 'Could not authenticate with Monnify.'];
            }
            $payload = [
                'amount' => $charge_amount,
                'customerName' => $full_name,
                'customerEmail' => $email,
                'paymentReference' => $ref,
                'paymentDescription' => 'Wallet Funding',
                'currencyCode' => 'NGN',
                'contractCode' => $enc,
                'redirectUrl' => $base_url . '/web/Fund.php?verify_gateway=monnify&paymentReference=' . rawurlencode($ref),
                'paymentMethods' => ['CARD', 'ACCOUNT_TRANSFER', 'USSD'],
                'metaData' => [
                    'vendor_id' => (string)$vid,
                    'username' => $username,
                    'credit_amount' => (string)$credit_target,
                    'gateway_fee' => (string)$gateway_fee,
                    'vendor_markup' => (string)$vendor_markup,
                    'fee_on_top' => $apply_fee_on_top ? '1' : '0',
                ],
            ];
            $init_res = bc_pg_http_json('POST', 'https://api.monnify.com/api/v1/merchant/transactions/init-transaction', [
                'Authorization: Bearer ' . $token,
                'Content-Type: application/json',
            ], $payload);
            $checkout_url = trim((string)($init_res['json']['responseBody']['checkoutUrl'] ?? ''));
            if (empty($init_res['ok']) || empty($init_res['json']['requestSuccessful']) || $checkout_url === '') {
                return ['ok' => false, 'code' => 'GATEWAY_INIT_FAILED', 'message' => 'Could not initialize Monnify checkout.'];
            }
            return [
                'ok' => true,
                'mode' => 'redirect',
                'provider' => 'monnify',
                'reference' => $ref,
                'charge_amount' => $charge_amount,
                'user_pay_amount' => $user_pay_amount,
                'credit_amount' => $credit_target,
                'fee' => $fee,
                'gateway_fee' => $gateway_fee,
                'vendor_markup' => $vendor_markup,
                'fee_mode' => $fee_mode,
                'authorization_url' => $checkout_url,
            ];
        }

        if ($provider === 'beewave') {
            if ($pub === '') {
                return ['ok' => false, 'code' => 'GATEWAY_UNCONFIGURED', 'message' => 'BeeWave public key is not configured.'];
            }
            return [
                'ok' => true,
                'mode' => 'inline_beewave',
                'provider' => 'beewave',
                'reference' => $ref,
                'charge_amount' => $charge_amount,
                'user_pay_amount' => $user_pay_amount,
                'credit_amount' => $credit_target,
                'fee' => $fee,
                'gateway_fee' => $gateway_fee,
                'vendor_markup' => $vendor_markup,
                'fee_mode' => $fee_mode,
                'public_key' => $pub,
                'email' => $email,
                'name' => $full_name,
                'phone' => $phone,
            ];
        }

        return ['ok' => false, 'code' => 'UNSUPPORTED_GATEWAY', 'message' => 'Unsupported gateway.'];
    }

    function bc_pg_init_vendor_gateway_checkout($db, array $vendor_row, $provider, $credit_amount_ngn, $scheme_host = '', $preferred_ref = '', $apply_fee_on_top = true, $purpose = 'wallet_funding')
    {
        $vid = (int)($vendor_row['id'] ?? 0);
        $provider = strtolower(trim((string)$provider));
        if ($vid <= 0 || (int)($vendor_row['status'] ?? 0) !== 1 || !empty($vendor_row['is_blocked'])) {
            return ['ok' => false, 'code' => 'UNAUTHENTICATED', 'message' => 'Vendor account is not active.'];
        }
        if (!in_array($provider, bc_pg_supported_checkout_gateways(), true)) {
            return ['ok' => false, 'code' => 'UNSUPPORTED_GATEWAY', 'message' => 'Selected payment gateway is not supported.'];
        }
        if (!bc_pg_is_platform_gateway_enabled($db, $provider)) {
            return ['ok' => false, 'code' => 'GATEWAY_DISABLED', 'message' => ucfirst($provider) . ' is currently disabled.'];
        }
        $cfg = bc_pg_platform_gateway_config($db, $provider, true);
        if (!$cfg) {
            return ['ok' => false, 'code' => 'GATEWAY_UNCONFIGURED', 'message' => 'Payment gateway is not configured.'];
        }

        $purpose = trim((string)$purpose);
        if ($purpose === 'payout_activation' && ($db instanceof mysqli)) {
            $q_fee = @mysqli_query($db, "SELECT option_value FROM sas_super_admin_options WHERE option_name='payout_activation_fee' LIMIT 1");
            $fee_opt = ($q_fee && ($fr = mysqli_fetch_assoc($q_fee)) && is_numeric($fr['option_value'] ?? null))
                ? (float)$fr['option_value']
                : 15000.0;
            if ($fee_opt >= 100.0) {
                $credit_amount_ngn = $fee_opt;
            }
        } elseif ($purpose === '') {
            $purpose = 'wallet_funding';
        }

        $norm = bc_pg_normalize_amount($credit_amount_ngn);
        if ($norm === null) {
            return ['ok' => false, 'code' => 'INVALID_AMOUNT', 'message' => 'Enter an amount between NGN 100 and NGN 1,000,000.'];
        }

        $entered_amount = $norm['float'];
        $markup_pct = bc_pg_clamp_platform_markup($cfg['percentage'] ?? null, 0.0);
        $fee_mode = $apply_fee_on_top ? 'customer_pays' : 'deduct_from_deposit';
        $breakdown = bc_pg_calculate_fee_breakdown($db, $provider, $entered_amount, $markup_pct, $fee_mode, 10.0);

        $credit_target = $breakdown['wallet_credit'];
        $charge_amount = $breakdown['charge_amount'];
        $user_pay_amount = $breakdown['user_pay_amount'];
        $gateway_fee = $breakdown['gateway_fee'];
        $vendor_markup = $breakdown['vendor_markup'];
        $fee = round($gateway_fee + $vendor_markup, 2);

        $email = trim((string)($vendor_row['email'] ?? ''));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $email = 'vendor' . $vid . '@wallet.local';
        }
        $username = $email;
        $full_name = trim((string)($vendor_row['firstname'] ?? '') . ' ' . (string)($vendor_row['lastname'] ?? ''));
        if ($full_name === '') $full_name = $email;
        $phone = trim((string)($vendor_row['phone_number'] ?? ''));

        $intent_fee_meta = [
            'target' => 'vendor',
            'purpose' => $purpose,
            'credit_amount' => $credit_target,
            'gateway_fee' => $gateway_fee,
            'vendor_markup' => $vendor_markup,
            'fee_mode' => $fee_mode,
        ];
        $intent = bc_pg_register_intent($db, $vid, $username, $provider, $charge_amount, $preferred_ref, $intent_fee_meta);
        if (empty($intent['ok'])) {
            return $intent;
        }
        $ref = $intent['reference'];
        $base_url = $scheme_host !== '' ? rtrim($scheme_host, '/') : bc_pg_scheme_host();

        $pub = trim((string)($cfg['public_key'] ?? ''));
        $sec = trim((string)($cfg['secret_key'] ?? ''));
        $enc = trim((string)($cfg['encrypt_key'] ?? ''));

        if ($provider === 'paystack') {
            if ($sec === '') {
                return ['ok' => false, 'code' => 'GATEWAY_UNCONFIGURED', 'message' => 'Paystack secret key is not configured.'];
            }
            $payload = [
                'email' => $email,
                'amount' => (int)round($charge_amount * 100),
                'currency' => 'NGN',
                'reference' => $ref,
                'callback_url' => $base_url . '/bc-admin/Fund.php?verify_gateway=paystack&reference=' . rawurlencode($ref),
                'metadata' => [
                    'target' => 'vendor',
                    'vendor_id' => $vid,
                    'username' => $username,
                    'reference' => $ref,
                    'purpose' => (string)$purpose,
                    'credit_amount' => $credit_target,
                    'gateway_fee' => $gateway_fee,
                    'vendor_markup' => $vendor_markup,
                    'fee_on_top' => $apply_fee_on_top ? 1 : 0,
                ],
            ];
            $res = bc_pg_http_json('POST', 'https://api.paystack.co/transaction/initialize', [
                'Authorization: Bearer ' . $sec,
                'Content-Type: application/json',
            ], $payload);
            $auth_url = trim((string)($res['json']['data']['authorization_url'] ?? ''));
            if (empty($res['ok']) || empty($res['json']['status']) || $auth_url === '') {
                return ['ok' => false, 'code' => 'GATEWAY_INIT_FAILED', 'message' => 'Could not initialize Paystack checkout. Please try again.'];
            }
            return [
                'ok' => true,
                'mode' => 'redirect',
                'provider' => 'paystack',
                'reference' => $ref,
                'charge_amount' => $charge_amount,
                'user_pay_amount' => $user_pay_amount,
                'credit_amount' => $credit_target,
                'fee' => $fee,
                'gateway_fee' => $gateway_fee,
                'vendor_markup' => $vendor_markup,
                'fee_mode' => $fee_mode,
                'authorization_url' => $auth_url,
            ];
        }

        if ($provider === 'payhub') {
            if (!function_exists('makePayhubRequest')) {
                return ['ok' => false, 'code' => 'GATEWAY_UNAVAILABLE', 'message' => 'PayHub client is unavailable.'];
            }
            $payload = [
                'amount' => $charge_amount,
                'email' => $email,
                'name' => $full_name,
                'phone' => $phone,
                'callback_url' => $base_url . '/bc-admin/payhub-success.php?local_reference=' . rawurlencode($ref),
                'metadata' => [
                    'vendor_id' => $vid,
                    'username' => $username,
                    'target' => 'vendor',
                    'local_reference' => $ref,
                    'purpose' => (string)$purpose,
                    'credit_amount' => $credit_target,
                    'gateway_fee' => $gateway_fee,
                    'vendor_markup' => $vendor_markup,
                    'fee_on_top' => $apply_fee_on_top ? 1 : 0,
                ],
            ];
            $raw_ph = makePayhubRequest('POST', 'api/transaction/initialize', $payload, $vid, true);
            $outer = is_string($raw_ph) ? json_decode($raw_ph, true) : (is_array($raw_ph) ? $raw_ph : null);
            $ph = (is_array($outer) && !empty($outer['json_result']) && is_string($outer['json_result']))
                ? json_decode($outer['json_result'], true)
                : $outer;
            $auth_url = trim((string)($ph['data']['authorization_url'] ?? ''));
            $ph_ref = bc_pg_sanitize_reference((string)($ph['data']['reference'] ?? ''));
            $ph_ok = is_array($outer) && (($outer['status'] ?? '') === 'success') && is_array($ph) && !empty($ph['status']);
            if (!$ph_ok || $auth_url === '' || $ph_ref === '') {
                return ['ok' => false, 'code' => 'GATEWAY_INIT_FAILED', 'message' => 'Could not initialize PayHub checkout. Please try again.'];
            }
            bc_pg_register_intent($db, $vid, $username, 'payhub', $charge_amount, $ph_ref, $intent_fee_meta);
            $loc_esc = mysqli_real_escape_string($db, $ref);
            $ph_esc = mysqli_real_escape_string($db, $ph_ref);
            @mysqli_query($db, "UPDATE sas_secure_payment_intents SET provider_reference='$ph_esc' WHERE reference='$loc_esc' AND vendor_id='$vid' AND target='vendor' LIMIT 1");
            return [
                'ok' => true,
                'mode' => 'redirect',
                'provider' => 'payhub',
                'reference' => $ph_ref,
                'local_reference' => $ref,
                'charge_amount' => $charge_amount,
                'user_pay_amount' => $user_pay_amount,
                'credit_amount' => $credit_target,
                'fee' => $fee,
                'gateway_fee' => $gateway_fee,
                'vendor_markup' => $vendor_markup,
                'fee_mode' => $fee_mode,
                'authorization_url' => $auth_url,
            ];
        }

        if ($provider === 'flutterwave') {
            if ($sec === '') {
                return ['ok' => false, 'code' => 'GATEWAY_UNCONFIGURED', 'message' => 'Flutterwave secret key is not configured.'];
            }
            $payload = [
                'tx_ref' => $ref,
                'amount' => $charge_amount,
                'currency' => 'NGN',
                'redirect_url' => $base_url . '/bc-admin/Fund.php?verify_gateway=flutterwave&tx_ref=' . rawurlencode($ref),
                'customer' => [
                    'email' => $email,
                    'phonenumber' => $phone,
                    'name' => $full_name,
                ],
                'meta' => [
                    'target' => 'vendor',
                    'vendor_id' => $vid,
                    'username' => $username,
                    'reference' => $ref,
                    'purpose' => (string)$purpose,
                    'credit_amount' => $credit_target,
                    'gateway_fee' => $gateway_fee,
                    'vendor_markup' => $vendor_markup,
                    'fee_on_top' => $apply_fee_on_top ? 1 : 0,
                ],
                'customizations' => [
                    'title' => 'Vendor Wallet Funding',
                    'description' => 'Fund vendor wallet (' . $ref . ')',
                ],
            ];
            $res = bc_pg_http_json('POST', 'https://api.flutterwave.com/v3/payments', [
                'Authorization: Bearer ' . $sec,
                'Content-Type: application/json',
            ], $payload);
            $link = trim((string)($res['json']['data']['link'] ?? ''));
            if (empty($res['ok']) || ($res['json']['status'] ?? '') !== 'success' || $link === '') {
                return ['ok' => false, 'code' => 'GATEWAY_INIT_FAILED', 'message' => 'Could not initialize Flutterwave checkout. Please try again.'];
            }
            return [
                'ok' => true,
                'mode' => 'redirect',
                'provider' => 'flutterwave',
                'reference' => $ref,
                'charge_amount' => $charge_amount,
                'user_pay_amount' => $user_pay_amount,
                'credit_amount' => $credit_target,
                'fee' => $fee,
                'gateway_fee' => $gateway_fee,
                'vendor_markup' => $vendor_markup,
                'fee_mode' => $fee_mode,
                'authorization_url' => $link,
            ];
        }

        if ($provider === 'monnify') {
            if ($pub === '' || $sec === '' || $enc === '') {
                return ['ok' => false, 'code' => 'GATEWAY_UNCONFIGURED', 'message' => 'Monnify credentials are incomplete.'];
            }
            $auth_res = bc_pg_http_json('POST', 'https://api.monnify.com/api/v1/auth/login', [
                'Authorization: Basic ' . base64_encode($pub . ':' . $sec),
                'Content-Type: application/json',
            ], '{}');
            $token = trim((string)($auth_res['json']['responseBody']['accessToken'] ?? ''));
            if (empty($auth_res['ok']) || empty($auth_res['json']['requestSuccessful']) || $token === '') {
                return ['ok' => false, 'code' => 'GATEWAY_AUTH_FAILED', 'message' => 'Could not authenticate with Monnify.'];
            }
            $payload = [
                'amount' => $charge_amount,
                'customerName' => $full_name,
                'customerEmail' => $email,
                'paymentReference' => $ref,
                'paymentDescription' => 'Vendor Wallet Funding',
                'currencyCode' => 'NGN',
                'contractCode' => $enc,
                'redirectUrl' => $base_url . '/bc-admin/Fund.php?verify_gateway=monnify&paymentReference=' . rawurlencode($ref),
                'paymentMethods' => ['CARD', 'ACCOUNT_TRANSFER', 'USSD'],
                'metaData' => [
                    'target' => 'vendor',
                    'vendor_id' => (string)$vid,
                    'username' => $username,
                    'purpose' => (string)$purpose,
                    'credit_amount' => (string)$credit_target,
                    'gateway_fee' => (string)$gateway_fee,
                    'vendor_markup' => (string)$vendor_markup,
                    'fee_on_top' => $apply_fee_on_top ? '1' : '0',
                ],
            ];
            $init_res = bc_pg_http_json('POST', 'https://api.monnify.com/api/v1/merchant/transactions/init-transaction', [
                'Authorization: Bearer ' . $token,
                'Content-Type: application/json',
            ], $payload);
            $checkout_url = trim((string)($init_res['json']['responseBody']['checkoutUrl'] ?? ''));
            if (empty($init_res['ok']) || empty($init_res['json']['requestSuccessful']) || $checkout_url === '') {
                return ['ok' => false, 'code' => 'GATEWAY_INIT_FAILED', 'message' => 'Could not initialize Monnify checkout.'];
            }
            return [
                'ok' => true,
                'mode' => 'redirect',
                'provider' => 'monnify',
                'reference' => $ref,
                'charge_amount' => $charge_amount,
                'user_pay_amount' => $user_pay_amount,
                'credit_amount' => $credit_target,
                'fee' => $fee,
                'gateway_fee' => $gateway_fee,
                'vendor_markup' => $vendor_markup,
                'fee_mode' => $fee_mode,
                'authorization_url' => $checkout_url,
            ];
        }

        if ($provider === 'beewave') {
            if ($pub === '') {
                return ['ok' => false, 'code' => 'GATEWAY_UNCONFIGURED', 'message' => 'BeeWave public key is not configured.'];
            }
            return [
                'ok' => true,
                'mode' => 'inline_beewave',
                'provider' => 'beewave',
                'reference' => $ref,
                'charge_amount' => $charge_amount,
                'user_pay_amount' => $user_pay_amount,
                'credit_amount' => $credit_target,
                'fee' => $fee,
                'gateway_fee' => $gateway_fee,
                'vendor_markup' => $vendor_markup,
                'fee_mode' => $fee_mode,
                'public_key' => $pub,
                'email' => $email,
                'name' => $full_name,
                'phone' => $phone,
            ];
        }

        return ['ok' => false, 'code' => 'UNSUPPORTED_GATEWAY', 'message' => 'Unsupported gateway.'];
    }

    function bc_pg_payhub_secret_candidates($db, $vendor_id)
    {
        $secrets = [];
        $cfg = bc_pg_gateway_config($db, $vendor_id, 'payhub', false);
        if (is_array($cfg)) {
            foreach (['webhook_secret', 'secret_key', 'encrypt_key'] as $k) {
                $val = trim((string)($cfg[$k] ?? ''));
                if ($val !== '') $secrets[] = $val;
            }
        }
        if (!$cfg || trim((string)($cfg['secret_key'] ?? '')) === '') {
            if ($db instanceof mysqli) {
                $sq = @mysqli_query(
                    $db,
                    "SELECT * FROM sas_super_admin_payment_gateways WHERE LOWER(TRIM(gateway_name))='payhub' ORDER BY (TRIM(COALESCE(secret_key,''))<>'') DESC LIMIT 1"
                );
                if ($sq && ($srow = mysqli_fetch_assoc($sq))) {
                    foreach (['webhook_secret', 'secret_key', 'encrypt_key'] as $k) {
                        $val = trim((string)($srow[$k] ?? ''));
                        if ($val !== '') $secrets[] = $val;
                    }
                }
            }
        }
        return array_values(array_unique($secrets));
    }

    function bc_pg_payhub_base_url($db)
    {
        if ($db instanceof mysqli) {
            $q = @mysqli_query($db, "SELECT option_value FROM sas_super_admin_options WHERE option_name='payhub_base_url' LIMIT 1");
            if ($q && ($r = mysqli_fetch_assoc($q))) {
                $val = trim((string)($r['option_value'] ?? ''));
                if ($val !== '') {
                    return rtrim($val, '/') . '/';
                }
            }
        }
        return 'https://merchant.payhub.com.ng/';
    }

    function bc_pg_resolve_settlement_amounts($db, $provider, $gross_amount, $markup_pct, $intent = null, array $meta = [], $provider_settled = 0.0)
    {
        $provider = strtolower(trim((string)$provider));
        $gross = max(0.0, round((float)$gross_amount, 2));
        $settled = max(0.0, round((float)$provider_settled, 2));
        $is_vendor_target = (is_array($intent) && (($intent['target'] ?? 'user') === 'vendor'))
            || (($meta['target'] ?? '') === 'vendor');
        $markup = $is_vendor_target
            ? bc_pg_clamp_platform_markup($markup_pct, 0.0)
            : bc_pg_clamp_markup($markup_pct, 0.5);

        $credit_meta = isset($meta['credit_amount']) && is_numeric($meta['credit_amount']) ? (float)$meta['credit_amount'] : 0.0;
        $intent_credit = (is_array($intent) && isset($intent['credit_amount']) && is_numeric($intent['credit_amount'])) ? (float)$intent['credit_amount'] : 0.0;
        $intent_expected = (is_array($intent) && isset($intent['amount']) && is_numeric($intent['amount'])) ? (float)$intent['amount'] : 0.0;
        $intent_mode = is_array($intent) ? trim((string)($intent['fee_mode'] ?? '')) : '';
        $intent_markup = (is_array($intent) && isset($intent['vendor_markup']) && is_numeric($intent['vendor_markup'])) ? (float)$intent['vendor_markup'] : null;

        $target_credit = $intent_credit > 0 ? $intent_credit : $credit_meta;
        $is_customer_pays = ($intent_mode === 'customer_pays' && $target_credit > 0) || (!empty($meta['fee_on_top']) && $credit_meta > 0);

        if ($is_customer_pays && $target_credit > 0) {
            if ($provider === 'payhub' && $settled > 0) {
                $v_markup = $intent_markup !== null ? $intent_markup : round($target_credit * ($markup / 100.0), 2);
                $safe_credit = max(0.0, round($settled - $v_markup, 2));
                $credit = min(round($target_credit, 2), $safe_credit);
                $fee = round(max(0.0, $gross - $credit), 2);
                return ['credit_amount' => $credit, 'fee' => $fee, 'vendor_markup' => $v_markup];
            }
            $expected_floor = $intent_expected > 0 ? $intent_expected : $target_credit;
            if (($gross + 0.01) >= $expected_floor) {
                $credit = min($gross, round($target_credit, 2));
                $fee = round(max(0.0, $gross - $credit), 2);
                $v_markup = $intent_markup !== null ? $intent_markup : round($credit * ($markup / 100.0), 2);
                return ['credit_amount' => $credit, 'fee' => $fee, 'vendor_markup' => $v_markup];
            }
        }

        if ($settled > 0 && $settled <= $gross) {
            $v_markup = ($intent_markup !== null && $intent_mode === 'deduct_from_deposit')
                ? $intent_markup
                : round($settled * ($markup / 100.0), 2);
            $credit = max(0.0, round($settled - $v_markup, 2));
            $fee = round(max(0.0, $gross - $credit), 2);
            return ['credit_amount' => $credit, 'fee' => $fee, 'vendor_markup' => $v_markup];
        }

        $bd = bc_pg_calculate_fee_breakdown($db, $provider, $gross, $markup, 'deduct_from_deposit', $is_vendor_target ? 10.0 : 1.0);
        $credit = $bd['wallet_credit'];
        $fee = round(max(0.0, $gross - $credit), 2);
        return ['credit_amount' => $credit, 'fee' => $fee, 'vendor_markup' => $bd['vendor_markup']];
    }

    function bc_pg_verify_with_provider($db, $vendor_id, $provider, $reference, array $extra = [])
    {
        $vid = (int)$vendor_id;
        $provider = strtolower(trim((string)$provider));
        $reference = bc_pg_sanitize_reference((string)$reference);
        if ($vid <= 0 || $reference === '') {
            return ['ok' => false, 'code' => 'INVALID_REFERENCE', 'message' => 'Invalid payment reference.'];
        }
        $intent = ($db instanceof mysqli) ? bc_pg_lookup_intent($db, $reference, $vid) : null;
        $is_vendor = !empty($extra['is_vendor']) || (is_array($intent) && (($intent['target'] ?? 'user') === 'vendor'));
        if ($is_vendor) {
            $cfg = bc_pg_platform_gateway_config($db, $provider, false);
        } else {
            $cfg = bc_pg_gateway_config($db, $vid, $provider, false);
            if (!$cfg && $provider === 'payhub' && ($db instanceof mysqli)) {
                $sq = @mysqli_query($db, "SELECT * FROM sas_super_admin_payment_gateways WHERE LOWER(TRIM(gateway_name))='payhub' LIMIT 1");
                if ($sq && mysqli_num_rows($sq) === 1) {
                    $cfg = mysqli_fetch_assoc($sq);
                }
            }
        }
        if (!$cfg) {
            return ['ok' => false, 'code' => 'GATEWAY_UNCONFIGURED', 'message' => 'Payment gateway is not configured.'];
        }
        $pub = trim((string)($cfg['public_key'] ?? ''));
        $sec = trim((string)($cfg['secret_key'] ?? ''));
        $pct = $is_vendor
            ? bc_pg_clamp_platform_markup($cfg['percentage'] ?? null, 0.0)
            : bc_pg_clamp_markup($cfg['percentage'] ?? null, 0.5);

        if ($provider === 'paystack') {
            if ($sec === '') return ['ok' => false, 'code' => 'GATEWAY_UNCONFIGURED', 'message' => 'Paystack secret key missing.'];
            $res = bc_pg_http_json('GET', 'https://api.paystack.co/transaction/verify/' . rawurlencode($reference), [
                'Authorization: Bearer ' . $sec,
                'Cache-Control: no-cache',
            ]);
            $data = $res['json']['data'] ?? null;
            if (empty($res['ok']) || empty($res['json']['status']) || !is_array($data)) {
                return ['ok' => false, 'code' => 'VERIFY_FAILED', 'message' => 'Unable to verify Paystack transaction.'];
            }
            if (($data['domain'] ?? '') === 'test') {
                return ['ok' => false, 'code' => 'TEST_MODE_REJECTED', 'message' => 'Test-mode transactions cannot credit live wallets.'];
            }
            if (strtolower((string)($data['status'] ?? '')) !== 'success') {
                return ['ok' => false, 'code' => 'NOT_PAID', 'message' => 'Payment has not been completed yet.'];
            }
            if (strtoupper((string)($data['currency'] ?? 'NGN')) !== 'NGN') {
                return ['ok' => false, 'code' => 'CURRENCY_MISMATCH', 'message' => 'Only NGN transactions are supported.'];
            }
            $gross = ((float)($data['amount'] ?? 0)) / 100.0;
            $meta = is_array($data['metadata'] ?? null) ? $data['metadata'] : [];
            if (!$intent && ($db instanceof mysqli)) {
                $loc_ref = bc_pg_sanitize_reference((string)($meta['local_reference'] ?? ($meta['reference'] ?? '')));
                if ($loc_ref !== '') $intent = bc_pg_lookup_intent($db, $loc_ref, $vid);
            }
            $settled_calc = bc_pg_resolve_settlement_amounts($db, 'paystack', $gross, $pct, $intent, $meta, 0.0);
            $credit = $settled_calc['credit_amount'];
            $fee = $settled_calc['fee'];
            return [
                'ok' => ($credit > 0),
                'provider' => 'paystack',
                'canonical_reference' => bc_pg_sanitize_reference((string)($data['reference'] ?? $reference)) ?: $reference,
                'event_id' => (string)($data['id'] ?? $reference),
                'gross_amount' => round($gross, 2),
                'credit_amount' => $credit,
                'fee' => $fee,
                'currency' => 'NGN',
                'metadata' => $meta,
                'raw_data' => $data,
            ];
        }

        if ($provider === 'payhub') {
            $verify_ref = $reference;
            if (!empty($extra['provider_reference'])) {
                $pr = bc_pg_sanitize_reference((string)$extra['provider_reference']);
                if ($pr !== '') $verify_ref = $pr;
            } elseif ($db instanceof mysqli) {
                $ref_esc = mysqli_real_escape_string($db, $reference);
                $prq = @mysqli_query($db, "SELECT provider_reference FROM sas_secure_payment_intents WHERE reference='$ref_esc' AND vendor_id='$vid' LIMIT 1");
                if ($prq && ($prr = mysqli_fetch_assoc($prq))) {
                    $pr = bc_pg_sanitize_reference((string)($prr['provider_reference'] ?? ''));
                    if ($pr !== '') $verify_ref = $pr;
                }
            }
            $ph_ok = false;
            $res = null;
            $data = null;
            if (function_exists('makePayhubRequest')) {
                $parse_ph_verify = function ($raw_resp) {
                    $outer = is_string($raw_resp) ? json_decode($raw_resp, true) : (is_array($raw_resp) ? $raw_resp : null);
                    $res_inner = (is_array($outer) && !empty($outer['json_result']) && is_string($outer['json_result']))
                        ? json_decode($outer['json_result'], true)
                        : $outer;
                    $d = is_array($res_inner['data'] ?? null) ? $res_inner['data'] : null;
                    $ok = is_array($outer) && (($outer['status'] ?? '') === 'success') && is_array($res_inner) && !empty($res_inner['status']) && is_array($d);
                    return [$ok, $res_inner, $d];
                };
                $raw = makePayhubRequest('GET', 'api/transaction/verify/' . rawurlencode($verify_ref), [], $vid, $is_vendor);
                [$ph_ok, $res, $data] = $parse_ph_verify($raw);
                if (!$ph_ok) {
                    $raw_q = makePayhubRequest('GET', 'api/transaction/verify?reference=' . rawurlencode($verify_ref), [], $vid, $is_vendor);
                    [$ph_ok, $res, $data] = $parse_ph_verify($raw_q);
                }
            } else {
                if ($sec === '') {
                    return ['ok' => false, 'code' => 'GATEWAY_UNCONFIGURED', 'message' => 'PayHub secret key missing.'];
                }
                $ph_base = bc_pg_payhub_base_url($db);
                $ph_headers = ['Authorization: Bearer ' . $sec, 'Content-Type: application/json'];
                $r1 = bc_pg_http_json('GET', $ph_base . 'api/transaction/verify/' . rawurlencode($verify_ref), $ph_headers);
                if (!empty($r1['ok']) && is_array($r1['json'] ?? null) && !empty($r1['json']['status']) && is_array($r1['json']['data'] ?? null)) {
                    $ph_ok = true;
                    $res = $r1['json'];
                    $data = $r1['json']['data'];
                } else {
                    $r2 = bc_pg_http_json('GET', $ph_base . 'api/transaction/verify?reference=' . rawurlencode($verify_ref), $ph_headers);
                    if (!empty($r2['ok']) && is_array($r2['json'] ?? null) && !empty($r2['json']['status']) && is_array($r2['json']['data'] ?? null)) {
                        $ph_ok = true;
                        $res = $r2['json'];
                        $data = $r2['json']['data'];
                    }
                }
            }
            if (!$ph_ok) {
                return ['ok' => false, 'code' => 'VERIFY_FAILED', 'message' => 'Unable to verify PayHub transaction.'];
            }
            if (strtolower((string)($data['domain'] ?? 'live')) === 'test') {
                return ['ok' => false, 'code' => 'TEST_MODE_REJECTED', 'message' => 'Test-mode PayHub transactions cannot credit live wallets.'];
            }
            $st = strtolower((string)($data['status'] ?? ''));
            if (!in_array($st, ['paid', 'success', 'successful'], true) || (isset($data['paid']) && $data['paid'] === false)) {
                return ['ok' => false, 'code' => 'NOT_PAID', 'message' => 'Payment is still pending confirmation.'];
            }
            $is_major = strtolower((string)($data['amount_unit'] ?? '')) === 'major';
            $div = $is_major ? 1.0 : 100.0;
            $gross_raw = is_numeric($data['gateway_amount'] ?? null) ? (float)$data['gateway_amount'] : (is_numeric($data['amount'] ?? null) ? (float)$data['amount'] : 0.0);
            $settled_raw = is_numeric($data['settled_amount'] ?? null) ? (float)$data['settled_amount'] : 0.0;
            $gross = round($gross_raw / $div, 2);
            $provider_settled = $settled_raw > 0 ? round($settled_raw / $div, 2) : 0.0;
            $meta = is_array($data['metadata'] ?? null) ? $data['metadata'] : [];
            if ((!$intent || empty($intent['credit_amount'])) && ($db instanceof mysqli)) {
                $ph_intent = bc_pg_lookup_intent($db, $verify_ref, $vid);
                if ($ph_intent && !empty($ph_intent['credit_amount'])) {
                    $intent = $ph_intent;
                } elseif (!empty($meta['local_reference'])) {
                    $loc_intent = bc_pg_lookup_intent($db, (string)$meta['local_reference'], $vid);
                    if ($loc_intent) $intent = $loc_intent;
                }
            }
            $settled_calc = bc_pg_resolve_settlement_amounts($db, 'payhub', $gross, $pct, $intent, $meta, $provider_settled);
            $credit = $settled_calc['credit_amount'];
            $fee = $settled_calc['fee'];
            return [
                'ok' => ($credit > 0),
                'provider' => 'payhub',
                'canonical_reference' => bc_pg_sanitize_reference((string)($data['reference'] ?? $verify_ref)) ?: $verify_ref,
                'event_id' => (string)($data['reference'] ?? $verify_ref),
                'gross_amount' => $gross,
                'credit_amount' => $credit,
                'fee' => $fee,
                'currency' => strtoupper((string)($data['currency'] ?? 'NGN')),
                'metadata' => $meta,
                'raw_data' => $data,
            ];
        }

        if ($provider === 'flutterwave') {
            if ($sec === '') return ['ok' => false, 'code' => 'GATEWAY_UNCONFIGURED', 'message' => 'Flutterwave secret key missing.'];
            $tx_id = trim((string)($extra['transaction_id'] ?? ''));
            if ($tx_id !== '' && ctype_digit($tx_id)) {
                $url = 'https://api.flutterwave.com/v3/transactions/' . rawurlencode($tx_id) . '/verify';
            } else {
                $url = 'https://api.flutterwave.com/v3/transactions/verify_by_reference?tx_ref=' . rawurlencode($reference);
            }
            $res = bc_pg_http_json('GET', $url, [
                'Authorization: Bearer ' . $sec,
                'Content-Type: application/json',
            ]);
            $data = $res['json']['data'] ?? null;
            if (empty($res['ok']) || ($res['json']['status'] ?? '') !== 'success' || !is_array($data)) {
                return ['ok' => false, 'code' => 'VERIFY_FAILED', 'message' => 'Unable to verify Flutterwave transaction.'];
            }
            $st = strtolower((string)($data['status'] ?? ''));
            if (!in_array($st, ['successful', 'success'], true)) {
                return ['ok' => false, 'code' => 'NOT_PAID', 'message' => 'Flutterwave payment is not completed yet.'];
            }
            if (strtoupper((string)($data['currency'] ?? 'NGN')) !== 'NGN') {
                return ['ok' => false, 'code' => 'CURRENCY_MISMATCH', 'message' => 'Only NGN transactions are supported.'];
            }
            $gross = round((float)($data['amount'] ?? 0), 2);
            $meta = is_array($data['meta'] ?? null) ? $data['meta'] : [];
            if (!$intent && ($db instanceof mysqli)) {
                $loc_ref = bc_pg_sanitize_reference((string)($meta['local_reference'] ?? ($meta['reference'] ?? '')));
                if ($loc_ref !== '') $intent = bc_pg_lookup_intent($db, $loc_ref, $vid);
            }
            $settled_calc = bc_pg_resolve_settlement_amounts($db, 'flutterwave', $gross, $pct, $intent, $meta, 0.0);
            $credit = $settled_calc['credit_amount'];
            $fee = $settled_calc['fee'];
            return [
                'ok' => ($credit > 0),
                'provider' => 'flutterwave',
                'canonical_reference' => bc_pg_sanitize_reference((string)($data['tx_ref'] ?? $reference)) ?: $reference,
                'event_id' => (string)($data['id'] ?? $reference),
                'gross_amount' => $gross,
                'credit_amount' => $credit,
                'fee' => $fee,
                'currency' => 'NGN',
                'metadata' => $meta,
                'raw_data' => $data,
            ];
        }

        if ($provider === 'monnify') {
            if ($pub === '' || $sec === '') return ['ok' => false, 'code' => 'GATEWAY_UNCONFIGURED', 'message' => 'Monnify API keys missing.'];
            $auth_res = bc_pg_http_json('POST', 'https://api.monnify.com/api/v1/auth/login', [
                'Authorization: Basic ' . base64_encode($pub . ':' . $sec),
                'Content-Type: application/json',
            ], '{}');
            $token = trim((string)($auth_res['json']['responseBody']['accessToken'] ?? ''));
            if (empty($auth_res['ok']) || empty($auth_res['json']['requestSuccessful']) || $token === '') {
                return ['ok' => false, 'code' => 'GATEWAY_AUTH_FAILED', 'message' => 'Unable to authenticate with Monnify.'];
            }
            $tx_ref = trim((string)($extra['transaction_reference'] ?? ''));
            if ($tx_ref !== '' && stripos($tx_ref, 'MNFY|') === 0) {
                $res = bc_pg_http_json('GET', 'https://api.monnify.com/api/v2/transactions/' . rawurlencode($tx_ref), [
                    'Authorization: Bearer ' . $token,
                    'Content-Type: application/json',
                ]);
                if (empty($res['ok']) || empty($res['json']['requestSuccessful']) || !is_array($res['json']['responseBody'] ?? null)) {
                    $res = bc_pg_http_json('GET', 'https://api.monnify.com/api/v2/merchant/transactions/query?paymentReference=' . rawurlencode($reference), [
                        'Authorization: Bearer ' . $token,
                        'Content-Type: application/json',
                    ]);
                }
            } else {
                $res = bc_pg_http_json('GET', 'https://api.monnify.com/api/v2/merchant/transactions/query?paymentReference=' . rawurlencode($reference), [
                    'Authorization: Bearer ' . $token,
                    'Content-Type: application/json',
                ]);
            }
            $body = $res['json']['responseBody'] ?? null;
            if (empty($res['ok']) || empty($res['json']['requestSuccessful']) || !is_array($body)) {
                return ['ok' => false, 'code' => 'VERIFY_FAILED', 'message' => 'Unable to verify Monnify transaction.'];
            }
            if (strtoupper((string)($body['paymentStatus'] ?? '')) !== 'PAID') {
                return ['ok' => false, 'code' => 'NOT_PAID', 'message' => 'Monnify payment has not been paid yet.'];
            }
            if (strtoupper((string)($body['currencyCode'] ?? 'NGN')) !== 'NGN') {
                return ['ok' => false, 'code' => 'CURRENCY_MISMATCH', 'message' => 'Only NGN transactions are supported.'];
            }
            $gross = round((float)($body['amountPaid'] ?? ($body['totalPayable'] ?? 0)), 2);
            $meta = is_array($body['metaData'] ?? null) ? $body['metaData'] : [];
            if (!$intent && ($db instanceof mysqli)) {
                $loc_ref = bc_pg_sanitize_reference((string)($meta['local_reference'] ?? ''));
                if ($loc_ref !== '') $intent = bc_pg_lookup_intent($db, $loc_ref, $vid);
            }
            $settled_calc = bc_pg_resolve_settlement_amounts($db, 'monnify', $gross, $pct, $intent, $meta, 0.0);
            $credit = $settled_calc['credit_amount'];
            $fee = $settled_calc['fee'];
            return [
                'ok' => ($credit > 0),
                'provider' => 'monnify',
                'canonical_reference' => bc_pg_sanitize_reference((string)($body['paymentReference'] ?? $reference)) ?: $reference,
                'event_id' => (string)($body['transactionReference'] ?? $reference),
                'gross_amount' => $gross,
                'credit_amount' => $credit,
                'fee' => $fee,
                'currency' => 'NGN',
                'metadata' => $meta,
                'raw_data' => $body,
            ];
        }

        if ($provider === 'beewave') {
            // BeeWave settles via its server-authenticated webhook (?secret=...).
            // A browser/app poll can only observe whether the webhook already settled the intent/claim.
            return ['ok' => false, 'code' => 'AWAITING_WEBHOOK', 'message' => 'Awaiting BeeWave server webhook confirmation. Your wallet will be credited automatically once confirmed.'];
        }

        return ['ok' => false, 'code' => 'UNSUPPORTED_GATEWAY', 'message' => 'Unsupported gateway.'];
    }

    function bc_pg_verify_and_settle($db, $vendor_id, $username, $provider, $reference, array $extra = [])
    {
        if (!bc_pg_ensure_tables($db)) {
            return ['ok' => false, 'code' => 'SCHEMA_UNAVAILABLE', 'message' => 'Payment service is unavailable.'];
        }
        $vid = (int)$vendor_id;
        $username = trim((string)$username);
        $provider = strtolower(trim((string)$provider));
        $reference = bc_pg_sanitize_reference((string)$reference);
        if ($vid <= 0 || $username === '' || $reference === '') {
            return ['ok' => false, 'code' => 'INVALID_REQUEST', 'message' => 'Invalid funding verification request.'];
        }

        $intent = bc_pg_lookup_intent($db, $reference, $vid);
        if (!$intent) {
            return ['ok' => false, 'code' => 'INTENT_NOT_FOUND', 'message' => 'No matching payment checkout was initiated for this reference.'];
        }
        if (($intent['target'] ?? 'user') !== 'user') {
            return ['ok' => false, 'code' => 'OWNERSHIP_MISMATCH', 'message' => 'Reference does not belong to a customer checkout.'];
        }
        if ((int)$intent['vendor_id'] !== $vid || strcasecmp((string)$intent['username'], $username) !== 0) {
            return ['ok' => false, 'code' => 'OWNERSHIP_MISMATCH', 'message' => 'Reference does not belong to this account.'];
        }

        if ($provider === '' || $provider === 'pending') {
            $provider = strtolower(trim((string)($intent['provider'] ?? '')));
        }
        if ($provider === '' || $provider === 'pending') {
            return ['ok' => false, 'code' => 'GATEWAY_REQUIRED', 'message' => 'Payment gateway is required to verify this transaction.'];
        }

        // Fast idempotent check: if already settled by webhook or earlier call, report success without re-crediting.
        $ref_esc = mysqli_real_escape_string($db, $reference);
        $user_esc = mysqli_real_escape_string($db, $username);
        $chk_done = mysqli_query($db, "SELECT id FROM sas_user_payment_checkouts WHERE vendor_id='$vid' AND username='$user_esc' AND reference='$ref_esc' AND status='1' LIMIT 1");
        if ($chk_done && mysqli_num_rows($chk_done) === 1) {
            $already_amt = is_numeric($intent['amount'] ?? null) ? (float)$intent['amount'] : 0.0;
            return ['ok' => true, 'already_settled' => true, 'code' => 'ALREADY_CREDITED', 'message' => 'Wallet already credited for this payment.', 'reference' => $reference, 'amount' => $already_amt];
        }

        $verified = bc_pg_verify_with_provider($db, $vid, $provider, $reference, $extra);
        if (empty($verified['ok'])) {
            return $verified;
        }

        // Ensure intent amount (when recorded) wasn't underpaid.
        $expected_amt = isset($intent['amount']) && is_numeric($intent['amount']) ? (float)$intent['amount'] : 0.0;
        $gross_amt = (float)$verified['gross_amount'];
        if ($expected_amt > 0 && ($gross_amt + 0.01) < $expected_amt) {
            return ['ok' => false, 'code' => 'UNDERPAID', 'message' => 'Paid amount is less than the expected checkout amount.'];
        }

        // Cross-check metadata ownership if provided by gateway.
        $meta = (array)($verified['metadata'] ?? []);
        if (isset($meta['vendor_id']) && (int)$meta['vendor_id'] > 0 && (int)$meta['vendor_id'] !== $vid) {
            return ['ok' => false, 'code' => 'OWNERSHIP_MISMATCH', 'message' => 'Payment vendor mismatch.'];
        }
        if (!empty($meta['username']) && strcasecmp((string)$meta['username'], $username) !== 0) {
            return ['ok' => false, 'code' => 'OWNERSHIP_MISMATCH', 'message' => 'Payment user mismatch.'];
        }

        $canonical_ref = $verified['canonical_reference'] ?: $reference;
        $credit = (float)$verified['credit_amount'];
        $fee = (float)$verified['fee'];
        $ledger = bc_fintech_ledger_post($vid, $username, 'NGN', 'credit', $credit, $canonical_ref, [
            'provider' => $provider,
            'event_id' => (string)$verified['event_id'],
            'fee' => $fee,
            'status' => 'success',
            'channel' => $provider . '_checkout',
        ]);

        if (empty($ledger['ok'])) {
            return [
                'ok' => false,
                'code' => strtoupper((string)($ledger['reason'] ?: 'LEDGER_ERROR')),
                'message' => 'Could not settle wallet credit (' . ($ledger['reason'] ?: 'ledger_error') . ').',
            ];
        }

        // Mark the canonical reference, caller reference, and any linked local/provider reference completed.
        $can_esc = mysqli_real_escape_string($db, $canonical_ref);
        $refs_to_mark = [$ref_esc, $can_esc];
        $loc_meta_ref = bc_pg_sanitize_reference((string)($meta['local_reference'] ?? ''));
        if ($loc_meta_ref !== '') $refs_to_mark[] = mysqli_real_escape_string($db, $loc_meta_ref);
        $prov_intent_ref = bc_pg_sanitize_reference((string)($intent['provider_reference'] ?? ''));
        if ($prov_intent_ref !== '') $refs_to_mark[] = mysqli_real_escape_string($db, $prov_intent_ref);
        $in_refs = "'" . implode("','", array_unique($refs_to_mark)) . "'";
        mysqli_query($db, "UPDATE sas_user_payment_checkouts SET status='1' WHERE vendor_id='$vid' AND username='$user_esc' AND reference IN ($in_refs)");

        if (($ledger['reason'] ?? '') === 'ok' && function_exists('bc_fintech_notify_wallet_credit')) {
            bc_fintech_notify_wallet_credit($vid, $username, 'NGN', $credit, $fee, $canonical_ref, $ledger['balance_after'] ?? null);
        }

        return [
            'ok' => true,
            'already_settled' => (($ledger['reason'] ?? '') === 'already_settled'),
            'code' => ($ledger['reason'] ?? '') === 'already_settled' ? 'ALREADY_CREDITED' : 'CREDITED',
            'message' => ($ledger['reason'] ?? '') === 'already_settled'
                ? 'Wallet already credited for this payment.'
                : 'Wallet credited with NGN ' . number_format($credit, 2) . ' successfully.',
            'reference' => $canonical_ref,
            'amount' => $credit,
            'fee' => $fee,
            'balance_after' => $ledger['balance_after'] ?? null,
        ];
    }

    function bc_pg_resolve_bank_owner($db, $account_number, $gateway = '', $vendor_id = 0)
    {
        if (!($db instanceof mysqli)) return null;
        $acct = preg_replace('/\D+/', '', (string)$account_number);
        if ($acct === '') return null;
        $acct_esc = mysqli_real_escape_string($db, $acct);
        $vid_clause = $vendor_id > 0 ? " AND vendor_id='" . (int)$vendor_id . "'" : "";
        $q = mysqli_query($db, "SELECT * FROM sas_user_banks WHERE account_number='$acct_esc' AND status='1'$vid_clause");
        if (!$q) return null;
        $matches = [];
        $gw = strtolower(trim((string)$gateway));
        while ($row = mysqli_fetch_assoc($q)) {
            $inferred = bc_pg_infer_bank_gateway($row);
            if ($gw !== '' && $inferred !== '' && $inferred !== $gw) continue;
            $matches[] = $row;
        }
        if (count($matches) !== 1) {
            return null;
        }
        return $matches[0];
    }

    function bc_pg_webhook_settle_credit($db, $vendor_id, $username, $provider, $canonical_ref, $event_id, $gross_amount, $credit_amount, $fee = 0.0, $channel = '')
    {
        if (!bc_pg_ensure_tables($db)) {
            return ['ok' => false, 'http' => 503, 'code' => 'SCHEMA_UNAVAILABLE', 'message' => 'Schema unavailable'];
        }
        $vid = (int)$vendor_id;
        $username = trim((string)$username);
        $provider = strtolower(trim((string)$provider));
        $canonical_ref = bc_pg_sanitize_reference((string)$canonical_ref);
        $event_id = trim((string)$event_id);
        if ($vid <= 0 || $username === '' || $provider === '' || $canonical_ref === '' || $event_id === '' || $credit_amount <= 0) {
            return ['ok' => false, 'http' => 422, 'code' => 'INVALID_SETTLEMENT', 'message' => 'Invalid settlement payload'];
        }

        $claim = bc_fintech_webhook_claim($provider, $event_id, $vid);
        if ($claim === 'duplicate') {
            return ['ok' => true, 'http' => 200, 'code' => 'DUPLICATE_EVENT', 'message' => 'Event already processed'];
        }
        if ($claim === 'error') {
            return ['ok' => false, 'http' => 500, 'code' => 'CLAIM_FAILED', 'message' => 'Could not claim webhook event'];
        }

        $ledger = bc_fintech_ledger_post($vid, $username, 'NGN', 'credit', round((float)$credit_amount, 2), $canonical_ref, [
            'provider' => $provider,
            'event_id' => $event_id,
            'fee' => round((float)$fee, 2),
            'gross_amount' => round((float)$gross_amount, 2),
            'status' => 'success',
            'channel' => $channel !== '' ? $channel : ($provider . '_webhook'),
        ]);

        if (empty($ledger['ok'])) {
            bc_fintech_webhook_release($provider, $event_id);
            return ['ok' => false, 'http' => 500, 'code' => strtoupper((string)($ledger['reason'] ?: 'LEDGER_FAILED')), 'message' => 'Ledger settlement failed'];
        }

        bc_fintech_webhook_complete($provider, $event_id);
        $ref_esc = mysqli_real_escape_string($db, $canonical_ref);
        $user_esc = mysqli_real_escape_string($db, $username);
        mysqli_query($db, "UPDATE sas_user_payment_checkouts SET status='1' WHERE vendor_id='$vid' AND username='$user_esc' AND reference='$ref_esc'");

        if (($ledger['reason'] ?? '') === 'ok' && function_exists('bc_fintech_notify_wallet_credit')) {
            bc_fintech_notify_wallet_credit($vid, $username, 'NGN', (float)$credit_amount, (float)$fee, $canonical_ref, $ledger['balance_after'] ?? null);
        }

        return [
            'ok' => true,
            'http' => 200,
            'code' => ($ledger['reason'] ?? '') === 'already_settled' ? 'ALREADY_SETTLED' : 'CREDITED',
            'message' => 'Processed',
            'amount' => (float)$credit_amount,
            'reference' => $canonical_ref,
        ];
    }

    function bc_pg_emit_webhook_json($http, array $body)
    {
        if (!headers_sent()) {
            header('Content-Type: application/json');
            header('Cache-Control: no-store');
        }
        http_response_code((int)$http);
        echo json_encode($body);
    }

    function bc_pg_verify_hmac_candidates($algo, $raw_body, $presented, array $secrets)
    {
        $presented = strtolower(trim((string)$presented));
        if ($presented === '') return false;
        foreach ($secrets as $sec) {
            $sec = trim((string)$sec);
            if ($sec === '') continue;
            $expected = strtolower(hash_hmac($algo, $raw_body, $sec));
            if (hash_equals($expected, $presented)) {
                return true;
            }
        }
        return false;
    }

    function bc_pg_handle_paystack_webhook($db)
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            bc_pg_emit_webhook_json(405, ['status' => 'error', 'code' => 'METHOD_NOT_ALLOWED', 'message' => 'POST required']);
            return;
        }
        bc_pg_ensure_tables($db);
        $raw = bc_fintech_webhook_raw_input();
        $headers = bc_fintech_webhook_headers();
        $payload = json_decode($raw, true);
        if (!is_array($payload)) {
            bc_pg_emit_webhook_json(400, ['status' => 'error', 'code' => 'INVALID_JSON', 'message' => 'Invalid JSON payload']);
            return;
        }
        $event = strtolower(trim((string)($payload['event'] ?? '')));
        if ($event !== 'charge.success') {
            bc_pg_emit_webhook_json(200, ['status' => 'ignored', 'code' => 'EVENT_IGNORED', 'message' => 'Event ignored']);
            return;
        }

        $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];
        $meta = is_array($data['metadata'] ?? null) ? $data['metadata'] : [];
        $ref = bc_pg_sanitize_reference((string)($data['reference'] ?? ''));
        $meta_local_ref = bc_pg_sanitize_reference((string)($meta['local_reference'] ?? ($meta['reference'] ?? '')));
        $rec_acct = (string)(
            $data['authorization']['receiver_bank_account_number']
            ?? ($meta['receiver_account_number']
            ?? ($data['dedicated_account']['account_number'] ?? ''))
        );

        $host_vid = function_exists('resolveVendorID') ? (int)resolveVendorID() : 0;
        $vid = 0;
        $username = '';
        $intent = ($ref !== '' ? bc_pg_lookup_intent($db, $ref, $host_vid > 0 ? $host_vid : 0) : null)
            ?: ($meta_local_ref !== '' ? bc_pg_lookup_intent($db, $meta_local_ref, $host_vid > 0 ? $host_vid : 0) : null);
        if ($intent) {
            if (($intent['target'] ?? 'user') !== 'user') {
                bc_pg_emit_webhook_json(200, ['status' => 'ignored', 'code' => 'TARGET_MISMATCH', 'message' => 'Not a user checkout intent']);
                return;
            }
            $vid = (int)$intent['vendor_id'];
            $username = (string)$intent['username'];
        } elseif ($rec_acct !== '') {
            $bank_row = bc_pg_resolve_bank_owner($db, $rec_acct, 'paystack', $host_vid > 0 ? $host_vid : 0);
            if ($bank_row) {
                $vid = (int)$bank_row['vendor_id'];
                $username = (string)$bank_row['username'];
            }
        }

        if ($vid <= 0 || $username === '' || ($host_vid > 0 && $vid !== $host_vid)) {
            bc_pg_emit_webhook_json(200, ['status' => 'ignored', 'code' => 'OWNER_NOT_RESOLVED', 'message' => 'Transaction owner not resolved']);
            return;
        }

        $cfg = bc_pg_gateway_config($db, $vid, 'paystack', false);
        if (!$cfg) {
            bc_pg_emit_webhook_json(403, ['status' => 'error', 'code' => 'GATEWAY_UNCONFIGURED', 'message' => 'Gateway not configured']);
            return;
        }
        $sig = $headers['x-paystack-signature'] ?? '';
        if (!bc_pg_verify_hmac_candidates('sha512', $raw, $sig, [$cfg['webhook_secret'] ?? '', $cfg['secret_key'] ?? ''])) {
            bc_pg_emit_webhook_json(401, ['status' => 'error', 'code' => 'INVALID_SIGNATURE', 'message' => 'Invalid Paystack signature']);
            return;
        }

        $verified = bc_pg_verify_with_provider($db, $vid, 'paystack', $ref);
        if (empty($verified['ok'])) {
            bc_pg_emit_webhook_json(200, ['status' => 'ignored', 'code' => $verified['code'] ?? 'VERIFY_FAILED', 'message' => $verified['message'] ?? 'Verification skipped']);
            return;
        }

        if ($intent && is_numeric($intent['amount'] ?? null) && (float)$intent['amount'] > 0 && ((float)$verified['gross_amount'] + 0.01) < (float)$intent['amount']) {
            bc_pg_emit_webhook_json(422, ['status' => 'error', 'code' => 'UNDERPAID', 'message' => 'Amount below checkout intent']);
            return;
        }

        $res = bc_pg_webhook_settle_credit(
            $db,
            $vid,
            $username,
            'paystack',
            $verified['canonical_reference'],
            (string)$verified['event_id'],
            (float)$verified['gross_amount'],
            (float)$verified['credit_amount'],
            (float)$verified['fee'],
            'paystack_webhook'
        );
        if (!empty($res['ok']) && $meta_local_ref !== '' && ($db instanceof mysqli)) {
            $lr_esc = mysqli_real_escape_string($db, $meta_local_ref);
            $u_esc = mysqli_real_escape_string($db, $username);
            @mysqli_query($db, "UPDATE sas_user_payment_checkouts SET status='1' WHERE vendor_id='$vid' AND username='$u_esc' AND reference='$lr_esc'");
        }
        bc_pg_emit_webhook_json($res['http'], $res);
    }

    function bc_pg_handle_monnify_webhook($db)
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            bc_pg_emit_webhook_json(405, ['status' => 'error', 'code' => 'METHOD_NOT_ALLOWED', 'message' => 'POST required']);
            return;
        }
        bc_pg_ensure_tables($db);
        $raw = bc_fintech_webhook_raw_input();
        $headers = bc_fintech_webhook_headers();
        $payload = json_decode($raw, true);
        if (!is_array($payload)) {
            bc_pg_emit_webhook_json(400, ['status' => 'error', 'code' => 'INVALID_JSON', 'message' => 'Invalid JSON payload']);
            return;
        }
        $event_type = strtoupper(trim((string)($payload['eventType'] ?? '')));
        $data = is_array($payload['eventData'] ?? null) ? $payload['eventData'] : $payload;
        $pay_status = strtoupper(trim((string)($data['paymentStatus'] ?? '')));
        if (($event_type !== '' && $event_type !== 'SUCCESSFUL_TRANSACTION') || ($pay_status !== '' && $pay_status !== 'PAID')) {
            bc_pg_emit_webhook_json(200, ['status' => 'ignored', 'code' => 'EVENT_IGNORED', 'message' => 'Non-paid event ignored']);
            return;
        }

        $meta = is_array($data['metaData'] ?? null) ? $data['metaData'] : [];
        $payment_ref = bc_pg_sanitize_reference((string)($data['paymentReference'] ?? ''));
        $meta_local_ref = bc_pg_sanitize_reference((string)($meta['local_reference'] ?? ''));
        $tx_ref = trim((string)($data['transactionReference'] ?? ''));
        $acct_no = (string)($data['destinationAccountInformation']['accountNumber'] ?? ($data['accountDetails']['accountNumber'] ?? ''));
        $prod_ref = trim((string)($data['product']['reference'] ?? ''));

        $host_vid = function_exists('resolveVendorID') ? (int)resolveVendorID() : 0;
        $vid = 0;
        $username = '';
        $intent = ($payment_ref !== '' ? bc_pg_lookup_intent($db, $payment_ref, $host_vid > 0 ? $host_vid : 0) : null)
            ?: ($meta_local_ref !== '' ? bc_pg_lookup_intent($db, $meta_local_ref, $host_vid > 0 ? $host_vid : 0) : null);
        if ($intent) {
            if (($intent['target'] ?? 'user') !== 'user') {
                bc_pg_emit_webhook_json(200, ['status' => 'ignored', 'code' => 'TARGET_MISMATCH', 'message' => 'Not a user checkout intent']);
                return;
            }
            $vid = (int)$intent['vendor_id'];
            $username = (string)$intent['username'];
        } elseif ($acct_no !== '') {
            $bank_row = bc_pg_resolve_bank_owner($db, $acct_no, 'monnify', $host_vid > 0 ? $host_vid : 0);
            if ($bank_row) {
                $vid = (int)$bank_row['vendor_id'];
                $username = (string)$bank_row['username'];
            }
        }
        if ($vid <= 0 && $prod_ref !== '') {
            $pr_esc = mysqli_real_escape_string($db, $prod_ref);
            $vid_clause = $host_vid > 0 ? " AND vendor_id='$host_vid'" : "";
            $bq = mysqli_query($db, "SELECT vendor_id, username FROM sas_user_banks WHERE reference='$pr_esc' AND status='1'$vid_clause");
            if ($bq && mysqli_num_rows($bq) === 1) {
                $br = mysqli_fetch_assoc($bq);
                $vid = (int)$br['vendor_id'];
                $username = (string)$br['username'];
            }
        }

        if ($vid <= 0 || $username === '' || ($host_vid > 0 && $vid !== $host_vid)) {
            bc_pg_emit_webhook_json(200, ['status' => 'ignored', 'code' => 'OWNER_NOT_RESOLVED', 'message' => 'Transaction owner not resolved']);
            return;
        }

        $cfg = bc_pg_gateway_config($db, $vid, 'monnify', false);
        if (!$cfg) {
            bc_pg_emit_webhook_json(403, ['status' => 'error', 'code' => 'GATEWAY_UNCONFIGURED', 'message' => 'Gateway not configured']);
            return;
        }
        $sig = $headers['monnify-signature'] ?? ($headers['x-monnify-signature'] ?? '');
        if (!bc_pg_verify_hmac_candidates('sha512', $raw, $sig, [$cfg['secret_key'] ?? '', $cfg['webhook_secret'] ?? ''])) {
            bc_pg_emit_webhook_json(401, ['status' => 'error', 'code' => 'INVALID_SIGNATURE', 'message' => 'Invalid Monnify signature']);
            return;
        }

        $verify_key = $payment_ref !== '' ? $payment_ref : $tx_ref;
        $verified = bc_pg_verify_with_provider($db, $vid, 'monnify', $verify_key, ['transaction_reference' => $tx_ref]);
        if (empty($verified['ok'])) {
            bc_pg_emit_webhook_json(200, ['status' => 'ignored', 'code' => $verified['code'] ?? 'VERIFY_FAILED', 'message' => $verified['message'] ?? 'Verification skipped']);
            return;
        }

        if ($intent && is_numeric($intent['amount'] ?? null) && (float)$intent['amount'] > 0 && ((float)$verified['gross_amount'] + 0.01) < (float)$intent['amount']) {
            bc_pg_emit_webhook_json(422, ['status' => 'error', 'code' => 'UNDERPAID', 'message' => 'Amount below checkout intent']);
            return;
        }

        $canonical_ref = bc_pg_sanitize_reference($tx_ref ?: $verified['canonical_reference']) ?: $verified['canonical_reference'];
        $res = bc_pg_webhook_settle_credit(
            $db,
            $vid,
            $username,
            'monnify',
            $canonical_ref,
            (string)$verified['event_id'],
            (float)$verified['gross_amount'],
            (float)$verified['credit_amount'],
            (float)$verified['fee'],
            'monnify_webhook'
        );
        if (!empty($res['ok']) && $payment_ref !== '' && ($db instanceof mysqli)) {
            $pr_esc = mysqli_real_escape_string($db, $payment_ref);
            $u_esc = mysqli_real_escape_string($db, $username);
            @mysqli_query($db, "UPDATE sas_user_payment_checkouts SET status='1' WHERE vendor_id='$vid' AND username='$u_esc' AND reference='$pr_esc'");
        }
        bc_pg_emit_webhook_json($res['http'], $res);
    }

    function bc_pg_handle_beewave_webhook($db)
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            bc_pg_emit_webhook_json(405, ['status' => 'error', 'code' => 'METHOD_NOT_ALLOWED', 'message' => 'POST required']);
            return;
        }
        bc_pg_ensure_tables($db);
        $raw = bc_fintech_webhook_raw_input();
        $headers = bc_fintech_webhook_headers();
        $payload = json_decode($raw, true);
        if (!is_array($payload)) {
            bc_pg_emit_webhook_json(400, ['status' => 'error', 'code' => 'INVALID_JSON', 'message' => 'Invalid JSON payload']);
            return;
        }
        $data = is_array($payload['data'] ?? null) ? $payload['data'] : $payload;
        $status = strtolower(trim((string)($data['status'] ?? ($payload['status'] ?? ''))));
        if (!in_array($status, ['success', 'successful', 'paid', 'completed'], true)) {
            bc_pg_emit_webhook_json(200, ['status' => 'ignored', 'code' => 'EVENT_IGNORED', 'message' => 'Non-success event ignored']);
            return;
        }

        $currency = strtoupper(trim((string)($data['currency'] ?? ($payload['currency'] ?? 'NGN'))));
        if ($currency !== 'NGN') {
            bc_pg_emit_webhook_json(422, ['status' => 'error', 'code' => 'CURRENCY_MISMATCH', 'message' => 'Only NGN supported']);
            return;
        }

        $meta = is_array($data['metadata'] ?? null) ? $data['metadata'] : [];
        $ref = bc_pg_sanitize_reference((string)($data['reference'] ?? ($payload['reference'] ?? ($data['tx_ref'] ?? ($data['transaction_reference'] ?? '')))));
        $meta_local_ref = bc_pg_sanitize_reference((string)($meta['local_reference'] ?? ''));
        $acct_no = (string)($data['account_number'] ?? ($data['virtual_account']['account_number'] ?? ($data['destination']['account_number'] ?? '')));
        $host_vid = function_exists('resolveVendorID') ? (int)resolveVendorID() : 0;

        $vid = 0;
        $username = '';
        $intent = ($ref !== '' ? bc_pg_lookup_intent($db, $ref, $host_vid > 0 ? $host_vid : 0) : null)
            ?: ($meta_local_ref !== '' ? bc_pg_lookup_intent($db, $meta_local_ref, $host_vid > 0 ? $host_vid : 0) : null);
        if ($intent) {
            if (($intent['target'] ?? 'user') !== 'user') {
                bc_pg_emit_webhook_json(200, ['status' => 'ignored', 'code' => 'TARGET_MISMATCH', 'message' => 'Not a user checkout intent']);
                return;
            }
            $vid = (int)$intent['vendor_id'];
            $username = (string)$intent['username'];
        } elseif ($acct_no !== '') {
            $bank_row = bc_pg_resolve_bank_owner($db, $acct_no, 'beewave', $host_vid > 0 ? $host_vid : 0);
            if ($bank_row) {
                $vid = (int)$bank_row['vendor_id'];
                $username = (string)$bank_row['username'];
            }
        }

        if ($vid <= 0 || $username === '' || ($host_vid > 0 && $vid !== $host_vid)) {
            bc_pg_emit_webhook_json(200, ['status' => 'ignored', 'code' => 'OWNER_NOT_RESOLVED', 'message' => 'Transaction owner not resolved']);
            return;
        }

        $cfg = bc_pg_gateway_config($db, $vid, 'beewave', false);
        if (!$cfg) {
            bc_pg_emit_webhook_json(403, ['status' => 'error', 'code' => 'GATEWAY_UNCONFIGURED', 'message' => 'BeeWave not configured']);
            return;
        }
        $secret_candidates = [];
        foreach (['webhook_secret', 'secret_key', 'encrypt_key'] as $sk) {
            $sv = trim((string)($cfg[$sk] ?? ''));
            if ($sv !== '') $secret_candidates[] = $sv;
        }
        $secret_candidates = array_values(array_unique($secret_candidates));
        $presented_secret = trim((string)($_GET['secret'] ?? ($headers['x-beewave-secret'] ?? ($headers['x-webhook-secret'] ?? ''))));
        $presented_sig = trim((string)($headers['x-beewave-signature'] ?? ($headers['x-webhook-signature'] ?? '')));
        $secret_ok = false;
        if ($presented_secret !== '') {
            foreach ($secret_candidates as $sc) {
                if (hash_equals($sc, $presented_secret)) {
                    $secret_ok = true;
                    break;
                }
            }
        }
        if (!$secret_ok && $presented_sig !== '') {
            $secret_ok = bc_pg_verify_hmac_candidates('sha256', $raw, $presented_sig, $secret_candidates)
                || bc_pg_verify_hmac_candidates('sha512', $raw, $presented_sig, $secret_candidates);
        }
        if (empty($secret_candidates) || !$secret_ok) {
            bc_pg_emit_webhook_json(401, ['status' => 'error', 'code' => 'INVALID_SECRET', 'message' => 'Invalid BeeWave webhook secret']);
            return;
        }

        $gross = is_numeric($data['amount'] ?? null) ? (float)$data['amount'] : (is_numeric($payload['amount'] ?? null) ? (float)$payload['amount'] : 0.0);
        if ($gross <= 0) {
            bc_pg_emit_webhook_json(422, ['status' => 'error', 'code' => 'INVALID_AMOUNT', 'message' => 'Positive amount required']);
            return;
        }
        if ($intent && is_numeric($intent['amount'] ?? null) && (float)$intent['amount'] > 0 && ($gross + 0.01) < (float)$intent['amount']) {
            bc_pg_emit_webhook_json(422, ['status' => 'error', 'code' => 'UNDERPAID', 'message' => 'Amount below checkout intent']);
            return;
        }

        $pct = bc_pg_clamp_markup($cfg['percentage'] ?? null, 0.5);
        $settled_raw = is_numeric($data['settlement_amount'] ?? null) ? (float)$data['settlement_amount'] : 0.0;
        $settled_calc = bc_pg_resolve_settlement_amounts($db, 'beewave', $gross, $pct, $intent, $meta, $settled_raw);
        $credit = $settled_calc['credit_amount'];
        $fee = $settled_calc['fee'];

        $event_id = (string)($data['id'] ?? ($data['transaction_id'] ?? $ref));
        $canonical_ref = $ref !== '' ? $ref : ('BW_' . $event_id);
        $res = bc_pg_webhook_settle_credit($db, $vid, $username, 'beewave', $canonical_ref, $event_id, $gross, $credit, $fee, 'beewave_webhook');
        bc_pg_emit_webhook_json($res['http'], $res);
    }

    function bc_pg_handle_payvessel_webhook($db)
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            bc_pg_emit_webhook_json(405, ['status' => 'error', 'code' => 'METHOD_NOT_ALLOWED', 'message' => 'POST required']);
            return;
        }
        bc_pg_ensure_tables($db);
        $raw = bc_fintech_webhook_raw_input();
        $headers = bc_fintech_webhook_headers();
        $payload = json_decode($raw, true);
        if (!is_array($payload)) {
            bc_pg_emit_webhook_json(400, ['status' => 'error', 'code' => 'INVALID_JSON', 'message' => 'Invalid JSON payload']);
            return;
        }

        $acct_no = (string)($payload['virtualAccount']['virtualAccountNumber'] ?? ($payload['order']['virtualAccountNumber'] ?? ($payload['virtual_account']['account_number'] ?? '')));
        $ref = bc_pg_sanitize_reference((string)($payload['transaction']['reference'] ?? ($payload['order']['trackingReference'] ?? '')));
        $host_vid = function_exists('resolveVendorID') ? (int)resolveVendorID() : 0;
        $bank_row = $acct_no !== '' ? bc_pg_resolve_bank_owner($db, $acct_no, 'payvessel', $host_vid > 0 ? $host_vid : 0) : null;
        if (!$bank_row) {
            bc_pg_emit_webhook_json(200, ['status' => 'ignored', 'code' => 'OWNER_NOT_RESOLVED', 'message' => 'Virtual account owner not resolved']);
            return;
        }

        $vid = (int)$bank_row['vendor_id'];
        $username = (string)$bank_row['username'];
        $cfg = bc_pg_gateway_config($db, $vid, 'payvessel', false);
        if (!$cfg) {
            bc_pg_emit_webhook_json(403, ['status' => 'error', 'code' => 'GATEWAY_UNCONFIGURED', 'message' => 'PayVessel not configured']);
            return;
        }

        $sig = $_SERVER['HTTP_PAYVESSEL_HTTP_SIGNATURE'] ?? ($headers['payvessel-http-signature'] ?? ($headers['x-payvessel-signature'] ?? ''));
        if (!bc_pg_verify_hmac_candidates('sha512', $raw, $sig, [$cfg['secret_key'] ?? '', $cfg['webhook_secret'] ?? ''])) {
            bc_pg_emit_webhook_json(401, ['status' => 'error', 'code' => 'INVALID_SIGNATURE', 'message' => 'Invalid PayVessel signature']);
            return;
        }

        $gross = is_numeric($payload['order']['amount'] ?? null) ? (float)$payload['order']['amount'] : 0.0;
        $settled = is_numeric($payload['order']['settlement_amount'] ?? null) ? (float)$payload['order']['settlement_amount'] : 0.0;
        $pct = bc_pg_clamp_markup($cfg['percentage'] ?? null, 0.5);
        if ($gross <= 0 && $settled <= 0) {
            bc_pg_emit_webhook_json(422, ['status' => 'error', 'code' => 'INVALID_AMOUNT', 'message' => 'Positive amount required']);
            return;
        }
        if ($gross <= 0) $gross = $settled;
        $settled_calc = bc_pg_resolve_settlement_amounts($db, 'payvessel', $gross, $pct, null, [], $settled);
        $credit = $settled_calc['credit_amount'];
        $fee = $settled_calc['fee'];

        $event_id = $ref !== '' ? $ref : hash('sha256', $raw);
        $canonical_ref = $ref !== '' ? $ref : ('PV_' . substr($event_id, 0, 24));
        $res = bc_pg_webhook_settle_credit($db, $vid, $username, 'payvessel', $canonical_ref, $event_id, $gross, $credit, $fee, 'payvessel_webhook');
        bc_pg_emit_webhook_json($res['http'], $res);
    }

    function bc_pg_resolve_vendor_bank_owner($db, $account_number, $gateway = '')
    {
        if (!($db instanceof mysqli)) return null;
        $acct = preg_replace('/\D+/', '', (string)$account_number);
        if ($acct === '') return null;
        $acct_esc = mysqli_real_escape_string($db, $acct);
        $q = mysqli_query($db, "SELECT * FROM sas_vendor_banks WHERE account_number='$acct_esc' AND (status='1' OR status IS NULL)");
        if (!$q) return null;
        $matches = [];
        $gw = strtolower(trim((string)$gateway));
        while ($row = mysqli_fetch_assoc($q)) {
            $inferred = bc_pg_infer_bank_gateway($row);
            if ($gw !== '' && $inferred !== '' && $inferred !== $gw) continue;
            $matches[] = $row;
        }
        if (count($matches) !== 1) {
            return null;
        }
        return $matches[0];
    }

    function bc_pg_settle_vendor_credit($db, $vendor_id, $provider, $canonical_ref, $event_id, $gross_amount, $credit_amount, $fee = 0.0, $channel = '', $purpose = 'wallet_funding', array $extra_refs = [])
    {
        if (!bc_pg_ensure_tables($db)) {
            return ['ok' => false, 'http' => 503, 'code' => 'SCHEMA_UNAVAILABLE', 'message' => 'Schema unavailable'];
        }
        if (!function_exists('bc_incident_wallet_post')) {
            return ['ok' => false, 'http' => 500, 'code' => 'LEDGER_UNAVAILABLE', 'message' => 'Vendor wallet ledger unavailable'];
        }
        $vid = (int)$vendor_id;
        $provider = strtolower(trim((string)$provider));
        $canonical_ref = bc_pg_sanitize_reference((string)$canonical_ref);
        $event_id = trim((string)$event_id);
        $credit_amt = round((float)$credit_amount, 2);
        $gross_amt = round((float)$gross_amount, 2);
        if ($gross_amt <= 0.0) $gross_amt = $credit_amt;
        if ($vid <= 0 || $provider === '' || $canonical_ref === '' || $event_id === '' || $credit_amt <= 0.0) {
            return ['ok' => false, 'http' => 422, 'code' => 'INVALID_SETTLEMENT', 'message' => 'Invalid vendor settlement payload'];
        }

        if (empty($GLOBALS['connection_server']) || $GLOBALS['connection_server'] !== $db) {
            $GLOBALS['connection_server'] = $db;
        }

        // Fast idempotency check against vendor receipts before mutating.
        $refs_to_check = [$canonical_ref];
        foreach ($extra_refs as $er) {
            $er_clean = bc_pg_sanitize_reference((string)$er);
            if ($er_clean !== '') $refs_to_check[] = $er_clean;
        }
        $refs_esc = array_map(function ($r) use ($db) {
            return "'" . mysqli_real_escape_string($db, $r) . "'";
        }, array_values(array_unique($refs_to_check)));
        $in_refs = implode(',', $refs_esc);
        $chk_tx = @mysqli_query($db, "SELECT reference, balance_after FROM sas_vendor_transactions WHERE vendor_id='$vid' AND reference IN ($in_refs) AND status=1 LIMIT 1");
        if ($chk_tx && ($txr = mysqli_fetch_assoc($chk_tx))) {
            return [
                'ok' => true,
                'already_settled' => true,
                'http' => 200,
                'code' => 'ALREADY_CREDITED',
                'message' => 'Vendor wallet already credited for this payment.',
                'reference' => (string)$txr['reference'],
                'amount' => $credit_amt,
                'fee' => round((float)$fee, 2),
                'balance_after' => $txr['balance_after'] ?? null,
            ];
        }

        $desc = 'Vendor Wallet Funding (' . ucfirst($provider) . ') Ref: ' . $canonical_ref;
        if ($purpose === 'payout_activation') {
            $desc = 'Vendor Wallet Funding (Withdrawal Module) Ref: ' . $canonical_ref;
        }
        $website = (string)($_SERVER['HTTP_HOST'] ?? 'admin');
        $api_ref = $provider . ':' . $event_id;
        $post_res = bc_incident_wallet_post(
            $vid,
            '',
            'credit',
            $provider,
            'Wallet Credit',
            $canonical_ref,
            $api_ref,
            $gross_amt,
            $credit_amt,
            $desc,
            'WEB',
            $website,
            1,
            true
        );

        if ($post_res !== 'success') {
            $reason = (string)($GLOBALS['bc_charge_failure_reason'] ?? 'Vendor wallet settlement failed');
            return [
                'ok' => false,
                'http' => 500,
                'code' => 'LEDGER_FAILED',
                'message' => $reason,
            ];
        }

        // Handle one-time withdrawal module activation if funded for payout_activation.
        $activated_payout = false;
        if ($purpose === 'payout_activation') {
            $vq = @mysqli_query($db, "SELECT payout_activated FROM sas_vendors WHERE id='$vid' LIMIT 1");
            $vrow = $vq ? mysqli_fetch_assoc($vq) : null;
            if ($vrow && (int)($vrow['payout_activated'] ?? 0) === 0) {
                $q_fee = @mysqli_query($db, "SELECT option_value FROM sas_super_admin_options WHERE option_name='payout_activation_fee' LIMIT 1");
                $fee_opt = ($q_fee && ($fr = mysqli_fetch_assoc($q_fee)) && is_numeric($fr['option_value'] ?? null))
                    ? (float)$fr['option_value']
                    : 15000.0;
                if ($fee_opt > 0 && ($credit_amt + 0.01) >= $fee_opt) {
                    $act_ref = 'PAYOUT_ACT_' . $canonical_ref;
                    $debit_res = bc_incident_wallet_post(
                        $vid,
                        '',
                        'debit',
                        'payout_activation',
                        'Service Activation',
                        $act_ref,
                        '',
                        $fee_opt,
                        $fee_opt,
                        'Withdrawal Module Activation Fee',
                        'WEB',
                        $website,
                        1,
                        true
                    );
                    if ($debit_res === 'success') {
                        @mysqli_query($db, "UPDATE sas_vendors SET payout_activated=1 WHERE id='$vid' LIMIT 1");
                        $activated_payout = true;
                    }
                }
            }
        }

        $bal_after = null;
        $bq = @mysqli_query($db, "SELECT balance FROM sas_vendors WHERE id='$vid' LIMIT 1");
        if ($bq && ($br = mysqli_fetch_assoc($bq))) {
            $bal_after = $br['balance'];
        }

        return [
            'ok' => true,
            'already_settled' => false,
            'payout_activated' => $activated_payout,
            'http' => 200,
            'code' => 'CREDITED',
            'message' => $activated_payout
                ? 'Payment confirmed! Wallet funded and Withdrawal Module activated.'
                : 'Vendor wallet credited with NGN ' . number_format($credit_amt, 2) . ' successfully.',
            'reference' => $canonical_ref,
            'amount' => $credit_amt,
            'fee' => round((float)$fee, 2),
            'balance_after' => $bal_after,
        ];
    }

    function bc_pg_verify_and_settle_vendor($db, $vendor_id, $provider, $reference, array $extra = [])
    {
        if (!bc_pg_ensure_tables($db)) {
            return ['ok' => false, 'code' => 'SCHEMA_UNAVAILABLE', 'message' => 'Payment service is unavailable.'];
        }
        $vid = (int)$vendor_id;
        $provider = strtolower(trim((string)$provider));
        $reference = bc_pg_sanitize_reference((string)$reference);
        if ($vid <= 0 || $reference === '') {
            return ['ok' => false, 'code' => 'INVALID_REQUEST', 'message' => 'Invalid vendor funding verification request.'];
        }

        $intent = bc_pg_lookup_intent($db, $reference, $vid);
        if (!$intent) {
            return ['ok' => false, 'code' => 'INTENT_NOT_FOUND', 'message' => 'No matching vendor payment checkout was initiated for this reference.'];
        }
        if ((int)$intent['vendor_id'] !== $vid || (($intent['target'] ?? 'user') !== 'vendor')) {
            return ['ok' => false, 'code' => 'OWNERSHIP_MISMATCH', 'message' => 'Reference does not belong to this vendor account.'];
        }

        if ($provider === '' || $provider === 'pending') {
            $provider = strtolower(trim((string)($intent['provider'] ?? '')));
        }
        if ($provider === '' || $provider === 'pending') {
            return ['ok' => false, 'code' => 'GATEWAY_REQUIRED', 'message' => 'Payment gateway is required to verify this transaction.'];
        }

        // Fast idempotency check in sas_vendor_transactions before hitting provider API.
        $check_refs = [$reference];
        if (!empty($intent['provider_reference'])) $check_refs[] = (string)$intent['provider_reference'];
        if (!empty($extra['provider_reference'])) $check_refs[] = (string)$extra['provider_reference'];
        $refs_esc = [];
        foreach ($check_refs as $cr) {
            $cr_clean = bc_pg_sanitize_reference($cr);
            if ($cr_clean !== '') $refs_esc[] = "'" . mysqli_real_escape_string($db, $cr_clean) . "'";
        }
        if (!empty($refs_esc)) {
            $in_refs = implode(',', array_unique($refs_esc));
            $chk_tx = @mysqli_query($db, "SELECT reference, discounted_amount, balance_after FROM sas_vendor_transactions WHERE vendor_id='$vid' AND reference IN ($in_refs) AND status=1 LIMIT 1");
            if ($chk_tx && ($txr = mysqli_fetch_assoc($chk_tx))) {
                $settled_amt = is_numeric($txr['discounted_amount'] ?? null)
                    ? (float)$txr['discounted_amount']
                    : (is_numeric($intent['credit_amount'] ?? null) ? (float)$intent['credit_amount'] : 0.0);
                return [
                    'ok' => true,
                    'already_settled' => true,
                    'code' => 'ALREADY_CREDITED',
                    'message' => 'Vendor wallet already credited for this payment.',
                    'reference' => (string)$txr['reference'],
                    'amount' => $settled_amt,
                    'balance_after' => $txr['balance_after'] ?? null,
                ];
            }
        }

        $verified = bc_pg_verify_with_provider($db, $vid, $provider, $reference, array_merge($extra, ['is_vendor' => true]));
        if (empty($verified['ok'])) {
            return $verified;
        }

        $expected_amt = isset($intent['amount']) && is_numeric($intent['amount']) ? (float)$intent['amount'] : 0.0;
        $gross_amt = (float)$verified['gross_amount'];
        if ($expected_amt > 0 && ($gross_amt + 0.01) < $expected_amt) {
            return ['ok' => false, 'code' => 'UNDERPAID', 'message' => 'Paid amount is less than the expected checkout amount.'];
        }

        $meta = (array)($verified['metadata'] ?? []);
        if (isset($meta['vendor_id']) && (int)$meta['vendor_id'] > 0 && (int)$meta['vendor_id'] !== $vid) {
            return ['ok' => false, 'code' => 'OWNERSHIP_MISMATCH', 'message' => 'Payment vendor mismatch.'];
        }
        if (isset($meta['target']) && (string)$meta['target'] !== '' && (string)$meta['target'] !== 'vendor') {
            return ['ok' => false, 'code' => 'OWNERSHIP_MISMATCH', 'message' => 'Payment target mismatch.'];
        }

        $canonical_ref = $verified['canonical_reference'] ?: $reference;
        $credit = (float)$verified['credit_amount'];
        $fee = (float)$verified['fee'];
        $purpose = trim((string)($intent['purpose'] ?? ($meta['purpose'] ?? 'wallet_funding')));
        if ($purpose === '') $purpose = 'wallet_funding';

        return bc_pg_settle_vendor_credit(
            $db,
            $vid,
            $provider,
            $canonical_ref,
            (string)$verified['event_id'],
            $gross_amt,
            $credit,
            $fee,
            $provider . '_checkout',
            $purpose,
            array_filter([$reference, (string)($meta['local_reference'] ?? ''), (string)($intent['provider_reference'] ?? '')])
        );
    }

    function bc_pg_handle_vendor_paystack_webhook($db)
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            bc_pg_emit_webhook_json(405, ['status' => 'error', 'code' => 'METHOD_NOT_ALLOWED', 'message' => 'POST required']);
            return;
        }
        bc_pg_ensure_tables($db);
        $raw = bc_fintech_webhook_raw_input();
        $headers = bc_fintech_webhook_headers();
        $payload = json_decode($raw, true);
        if (!is_array($payload)) {
            bc_pg_emit_webhook_json(400, ['status' => 'error', 'code' => 'INVALID_JSON', 'message' => 'Invalid JSON payload']);
            return;
        }
        $event = strtolower(trim((string)($payload['event'] ?? '')));
        if ($event !== 'charge.success') {
            bc_pg_emit_webhook_json(200, ['status' => 'ignored', 'code' => 'EVENT_IGNORED', 'message' => 'Event ignored']);
            return;
        }

        $cfg = bc_pg_platform_gateway_config($db, 'paystack', false);
        if (!$cfg) {
            bc_pg_emit_webhook_json(403, ['status' => 'error', 'code' => 'GATEWAY_UNCONFIGURED', 'message' => 'Platform Paystack gateway not configured']);
            return;
        }
        $sig = $headers['x-paystack-signature'] ?? '';
        if (!bc_pg_verify_hmac_candidates('sha512', $raw, $sig, [$cfg['webhook_secret'] ?? '', $cfg['secret_key'] ?? ''])) {
            bc_pg_emit_webhook_json(401, ['status' => 'error', 'code' => 'INVALID_SIGNATURE', 'message' => 'Invalid Paystack signature']);
            return;
        }

        $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];
        $meta = is_array($data['metadata'] ?? null) ? $data['metadata'] : [];
        $ref = bc_pg_sanitize_reference((string)($data['reference'] ?? ''));
        $meta_local_ref = bc_pg_sanitize_reference((string)($meta['local_reference'] ?? ($meta['reference'] ?? '')));
        $rec_acct = (string)(
            $data['authorization']['receiver_bank_account_number']
            ?? ($meta['receiver_account_number']
            ?? ($data['dedicated_account']['account_number'] ?? ''))
        );

        $vid = 0;
        $intent = ($ref !== '' ? bc_pg_lookup_intent($db, $ref, 0) : null)
            ?: ($meta_local_ref !== '' ? bc_pg_lookup_intent($db, $meta_local_ref, 0) : null);
        if ($intent) {
            if (($intent['target'] ?? 'user') !== 'vendor') {
                bc_pg_emit_webhook_json(200, ['status' => 'ignored', 'code' => 'TARGET_MISMATCH', 'message' => 'Not a vendor intent']);
                return;
            }
            $vid = (int)$intent['vendor_id'];
        } elseif ($rec_acct !== '') {
            $bank_row = bc_pg_resolve_vendor_bank_owner($db, $rec_acct, 'paystack');
            if ($bank_row) {
                $vid = (int)$bank_row['vendor_id'];
            }
        } elseif (($meta['target'] ?? '') === 'vendor' && !empty($meta['vendor_id'])) {
            $vid = (int)$meta['vendor_id'];
        }

        if ($vid <= 0) {
            bc_pg_emit_webhook_json(200, ['status' => 'ignored', 'code' => 'OWNER_NOT_RESOLVED', 'message' => 'Vendor owner not resolved']);
            return;
        }

        $verified = bc_pg_verify_with_provider($db, $vid, 'paystack', $ref, ['is_vendor' => true]);
        if (empty($verified['ok'])) {
            bc_pg_emit_webhook_json(200, ['status' => 'ignored', 'code' => $verified['code'] ?? 'VERIFY_FAILED', 'message' => $verified['message'] ?? 'Verification skipped']);
            return;
        }

        if ($intent && is_numeric($intent['amount'] ?? null) && (float)$intent['amount'] > 0 && ((float)$verified['gross_amount'] + 0.01) < (float)$intent['amount']) {
            bc_pg_emit_webhook_json(422, ['status' => 'error', 'code' => 'UNDERPAID', 'message' => 'Amount below checkout intent']);
            return;
        }

        $v_meta = (array)($verified['metadata'] ?? []);
        $purpose = trim((string)(($intent['purpose'] ?? '') !== '' ? $intent['purpose'] : ($v_meta['purpose'] ?? 'wallet_funding')));
        $res = bc_pg_settle_vendor_credit(
            $db,
            $vid,
            'paystack',
            $verified['canonical_reference'],
            (string)$verified['event_id'],
            (float)$verified['gross_amount'],
            (float)$verified['credit_amount'],
            (float)$verified['fee'],
            'paystack_vendor_webhook',
            $purpose,
            array_filter([$ref, $meta_local_ref])
        );
        bc_pg_emit_webhook_json($res['http'], $res);
    }

    function bc_pg_handle_vendor_flutterwave_webhook($db)
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            bc_pg_emit_webhook_json(405, ['status' => 'error', 'code' => 'METHOD_NOT_ALLOWED', 'message' => 'POST required']);
            return;
        }
        bc_pg_ensure_tables($db);
        $raw = bc_fintech_webhook_raw_input();
        $headers = bc_fintech_webhook_headers();
        $payload = json_decode($raw, true);
        if (!is_array($payload)) {
            bc_pg_emit_webhook_json(400, ['status' => 'error', 'code' => 'INVALID_JSON', 'message' => 'Invalid JSON payload']);
            return;
        }

        $cfg = bc_pg_platform_gateway_config($db, 'flutterwave', false);
        if (!$cfg) {
            bc_pg_emit_webhook_json(403, ['status' => 'error', 'code' => 'GATEWAY_UNCONFIGURED', 'message' => 'Platform Flutterwave gateway not configured']);
            return;
        }
        $secret_hash = trim((string)($cfg['webhook_secret'] ?? ''));
        if ($secret_hash === '') {
            $secret_hash = trim((string)($cfg['encrypt_key'] ?? ''));
        }
        $presented_hash = trim((string)($headers['verif-hash'] ?? ($_SERVER['HTTP_VERIF_HASH'] ?? '')));
        if ($secret_hash !== '' && ($presented_hash === '' || !hash_equals($secret_hash, $presented_hash))) {
            bc_pg_emit_webhook_json(401, ['status' => 'error', 'code' => 'INVALID_SIGNATURE', 'message' => 'Invalid Flutterwave webhook signature']);
            return;
        }

        $data = is_array($payload['data'] ?? null) ? $payload['data'] : $payload;
        $status = strtolower(trim((string)($data['status'] ?? ($payload['status'] ?? ''))));
        if (!in_array($status, ['successful', 'success'], true)) {
            bc_pg_emit_webhook_json(200, ['status' => 'ignored', 'code' => 'EVENT_IGNORED', 'message' => 'Non-success event ignored']);
            return;
        }

        $meta = is_array($data['meta'] ?? null) ? $data['meta'] : (is_array($payload['meta_data'] ?? null) ? $payload['meta_data'] : []);
        $ref = bc_pg_sanitize_reference((string)($data['tx_ref'] ?? ($data['reference'] ?? '')));
        $meta_local_ref = bc_pg_sanitize_reference((string)($meta['local_reference'] ?? ($meta['reference'] ?? '')));
        $tx_id = trim((string)($data['id'] ?? ''));

        $vid = 0;
        $intent = ($ref !== '' ? bc_pg_lookup_intent($db, $ref, 0) : null)
            ?: ($meta_local_ref !== '' ? bc_pg_lookup_intent($db, $meta_local_ref, 0) : null);
        if ($intent) {
            if (($intent['target'] ?? 'user') !== 'vendor') {
                bc_pg_emit_webhook_json(200, ['status' => 'ignored', 'code' => 'TARGET_MISMATCH', 'message' => 'Not a vendor intent']);
                return;
            }
            $vid = (int)$intent['vendor_id'];
        } elseif (($meta['target'] ?? '') === 'vendor' && !empty($meta['vendor_id'])) {
            $vid = (int)$meta['vendor_id'];
        }

        if ($vid <= 0 || $ref === '') {
            bc_pg_emit_webhook_json(200, ['status' => 'ignored', 'code' => 'OWNER_NOT_RESOLVED', 'message' => 'Vendor owner not resolved']);
            return;
        }

        $verified = bc_pg_verify_with_provider($db, $vid, 'flutterwave', $ref, ['is_vendor' => true, 'transaction_id' => $tx_id]);
        if (empty($verified['ok'])) {
            bc_pg_emit_webhook_json(200, ['status' => 'ignored', 'code' => $verified['code'] ?? 'VERIFY_FAILED', 'message' => $verified['message'] ?? 'Verification skipped']);
            return;
        }

        if ($intent && is_numeric($intent['amount'] ?? null) && (float)$intent['amount'] > 0 && ((float)$verified['gross_amount'] + 0.01) < (float)$intent['amount']) {
            bc_pg_emit_webhook_json(422, ['status' => 'error', 'code' => 'UNDERPAID', 'message' => 'Amount below checkout intent']);
            return;
        }

        $v_meta = (array)($verified['metadata'] ?? []);
        $purpose = trim((string)(($intent['purpose'] ?? '') !== '' ? $intent['purpose'] : ($v_meta['purpose'] ?? 'wallet_funding')));
        $res = bc_pg_settle_vendor_credit(
            $db,
            $vid,
            'flutterwave',
            $verified['canonical_reference'],
            (string)$verified['event_id'],
            (float)$verified['gross_amount'],
            (float)$verified['credit_amount'],
            (float)$verified['fee'],
            'flutterwave_vendor_webhook',
            $purpose,
            array_filter([$ref, $meta_local_ref])
        );
        bc_pg_emit_webhook_json($res['http'], $res);
    }

    function bc_pg_handle_vendor_monnify_webhook($db)
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            bc_pg_emit_webhook_json(405, ['status' => 'error', 'code' => 'METHOD_NOT_ALLOWED', 'message' => 'POST required']);
            return;
        }
        bc_pg_ensure_tables($db);
        $raw = bc_fintech_webhook_raw_input();
        $headers = bc_fintech_webhook_headers();
        $payload = json_decode($raw, true);
        if (!is_array($payload)) {
            bc_pg_emit_webhook_json(400, ['status' => 'error', 'code' => 'INVALID_JSON', 'message' => 'Invalid JSON payload']);
            return;
        }

        $cfg = bc_pg_platform_gateway_config($db, 'monnify', false);
        if (!$cfg) {
            bc_pg_emit_webhook_json(403, ['status' => 'error', 'code' => 'GATEWAY_UNCONFIGURED', 'message' => 'Platform Monnify gateway not configured']);
            return;
        }
        $sig = $headers['monnify-signature'] ?? ($headers['x-monnify-signature'] ?? '');
        if (!bc_pg_verify_hmac_candidates('sha512', $raw, $sig, [$cfg['secret_key'] ?? '', $cfg['webhook_secret'] ?? ''])) {
            bc_pg_emit_webhook_json(401, ['status' => 'error', 'code' => 'INVALID_SIGNATURE', 'message' => 'Invalid Monnify signature']);
            return;
        }

        $event_type = strtoupper(trim((string)($payload['eventType'] ?? '')));
        $data = is_array($payload['eventData'] ?? null) ? $payload['eventData'] : $payload;
        $pay_status = strtoupper(trim((string)($data['paymentStatus'] ?? '')));
        if (($event_type !== '' && $event_type !== 'SUCCESSFUL_TRANSACTION') || ($pay_status !== '' && $pay_status !== 'PAID')) {
            bc_pg_emit_webhook_json(200, ['status' => 'ignored', 'code' => 'EVENT_IGNORED', 'message' => 'Non-paid event ignored']);
            return;
        }

        $meta = is_array($data['metaData'] ?? null) ? $data['metaData'] : [];
        $payment_ref = bc_pg_sanitize_reference((string)($data['paymentReference'] ?? ''));
        $meta_local_ref = bc_pg_sanitize_reference((string)($meta['local_reference'] ?? ''));
        $tx_ref = trim((string)($data['transactionReference'] ?? ''));
        $acct_no = (string)($data['destinationAccountInformation']['accountNumber'] ?? ($data['accountDetails']['accountNumber'] ?? ''));
        $prod_ref = trim((string)($data['product']['reference'] ?? ''));

        $vid = 0;
        $intent = ($payment_ref !== '' ? bc_pg_lookup_intent($db, $payment_ref, 0) : null)
            ?: ($meta_local_ref !== '' ? bc_pg_lookup_intent($db, $meta_local_ref, 0) : null);
        if ($intent) {
            if (($intent['target'] ?? 'user') !== 'vendor') {
                bc_pg_emit_webhook_json(200, ['status' => 'ignored', 'code' => 'TARGET_MISMATCH', 'message' => 'Not a vendor intent']);
                return;
            }
            $vid = (int)$intent['vendor_id'];
        } elseif ($acct_no !== '') {
            $bank_row = bc_pg_resolve_vendor_bank_owner($db, $acct_no, 'monnify');
            if ($bank_row) {
                $vid = (int)$bank_row['vendor_id'];
            }
        }
        if ($vid <= 0 && $prod_ref !== '') {
            $pr_esc = mysqli_real_escape_string($db, $prod_ref);
            $bq = @mysqli_query($db, "SELECT vendor_id FROM sas_vendor_banks WHERE reference='$pr_esc' AND (status='1' OR status IS NULL)");
            if ($bq && mysqli_num_rows($bq) === 1) {
                $br = mysqli_fetch_assoc($bq);
                $vid = (int)$br['vendor_id'];
            }
        }
        if ($vid <= 0 && ($meta['target'] ?? '') === 'vendor' && !empty($meta['vendor_id'])) {
            $vid = (int)$meta['vendor_id'];
        }

        if ($vid <= 0) {
            bc_pg_emit_webhook_json(200, ['status' => 'ignored', 'code' => 'OWNER_NOT_RESOLVED', 'message' => 'Vendor owner not resolved']);
            return;
        }

        $verify_key = $payment_ref !== '' ? $payment_ref : $tx_ref;
        $verified = bc_pg_verify_with_provider($db, $vid, 'monnify', $verify_key, ['is_vendor' => true, 'transaction_reference' => $tx_ref]);
        if (empty($verified['ok'])) {
            bc_pg_emit_webhook_json(200, ['status' => 'ignored', 'code' => $verified['code'] ?? 'VERIFY_FAILED', 'message' => $verified['message'] ?? 'Verification skipped']);
            return;
        }

        if ($intent && is_numeric($intent['amount'] ?? null) && (float)$intent['amount'] > 0 && ((float)$verified['gross_amount'] + 0.01) < (float)$intent['amount']) {
            bc_pg_emit_webhook_json(422, ['status' => 'error', 'code' => 'UNDERPAID', 'message' => 'Amount below checkout intent']);
            return;
        }

        $v_meta = (array)($verified['metadata'] ?? []);
        $purpose = trim((string)(($intent['purpose'] ?? '') !== '' ? $intent['purpose'] : ($v_meta['purpose'] ?? 'wallet_funding')));
        $canonical_ref = bc_pg_sanitize_reference($tx_ref ?: $verified['canonical_reference']) ?: $verified['canonical_reference'];
        $res = bc_pg_settle_vendor_credit(
            $db,
            $vid,
            'monnify',
            $canonical_ref,
            (string)$verified['event_id'],
            (float)$verified['gross_amount'],
            (float)$verified['credit_amount'],
            (float)$verified['fee'],
            'monnify_vendor_webhook',
            $purpose,
            array_filter([$payment_ref, $meta_local_ref, $verified['canonical_reference']])
        );
        bc_pg_emit_webhook_json($res['http'], $res);
    }

    function bc_pg_handle_vendor_payvessel_webhook($db)
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            bc_pg_emit_webhook_json(405, ['status' => 'error', 'code' => 'METHOD_NOT_ALLOWED', 'message' => 'POST required']);
            return;
        }
        bc_pg_ensure_tables($db);
        $raw = bc_fintech_webhook_raw_input();
        $headers = bc_fintech_webhook_headers();
        $payload = json_decode($raw, true);
        if (!is_array($payload)) {
            bc_pg_emit_webhook_json(400, ['status' => 'error', 'code' => 'INVALID_JSON', 'message' => 'Invalid JSON payload']);
            return;
        }

        $cfg = bc_pg_platform_gateway_config($db, 'payvessel', false);
        if (!$cfg) {
            bc_pg_emit_webhook_json(403, ['status' => 'error', 'code' => 'GATEWAY_UNCONFIGURED', 'message' => 'Platform PayVessel gateway not configured']);
            return;
        }
        $sig = $_SERVER['HTTP_PAYVESSEL_HTTP_SIGNATURE'] ?? ($headers['payvessel-http-signature'] ?? ($headers['x-payvessel-signature'] ?? ''));
        if (!bc_pg_verify_hmac_candidates('sha512', $raw, $sig, [$cfg['secret_key'] ?? '', $cfg['webhook_secret'] ?? ''])) {
            bc_pg_emit_webhook_json(401, ['status' => 'error', 'code' => 'INVALID_SIGNATURE', 'message' => 'Invalid PayVessel signature']);
            return;
        }

        $acct_no = (string)($payload['virtualAccount']['virtualAccountNumber'] ?? ($payload['order']['virtualAccountNumber'] ?? ($payload['virtual_account']['account_number'] ?? '')));
        $ref = bc_pg_sanitize_reference((string)($payload['transaction']['reference'] ?? ($payload['order']['trackingReference'] ?? '')));
        $bank_row = $acct_no !== '' ? bc_pg_resolve_vendor_bank_owner($db, $acct_no, 'payvessel') : null;
        if (!$bank_row) {
            bc_pg_emit_webhook_json(200, ['status' => 'ignored', 'code' => 'OWNER_NOT_RESOLVED', 'message' => 'Vendor virtual account owner not resolved']);
            return;
        }

        $vid = (int)$bank_row['vendor_id'];
        $gross = is_numeric($payload['order']['amount'] ?? null) ? (float)$payload['order']['amount'] : 0.0;
        $settled = is_numeric($payload['order']['settlement_amount'] ?? null) ? (float)$payload['order']['settlement_amount'] : 0.0;
        $pct = bc_pg_clamp_platform_markup($cfg['percentage'] ?? null, 0.0);
        if ($gross <= 0 && $settled <= 0) {
            bc_pg_emit_webhook_json(422, ['status' => 'error', 'code' => 'INVALID_AMOUNT', 'message' => 'Positive amount required']);
            return;
        }
        if ($gross <= 0) $gross = $settled;
        $settled_calc = bc_pg_resolve_settlement_amounts($db, 'payvessel', $gross, $pct, null, ['target' => 'vendor'], $settled);
        $credit = $settled_calc['credit_amount'];
        $fee = $settled_calc['fee'];

        $event_id = $ref !== '' ? $ref : hash('sha256', $raw);
        $canonical_ref = $ref !== '' ? $ref : ('PVV_' . substr($event_id, 0, 24));
        $res = bc_pg_settle_vendor_credit($db, $vid, 'payvessel', $canonical_ref, $event_id, $gross, $credit, $fee, 'payvessel_vendor_webhook', 'wallet_funding');
        bc_pg_emit_webhook_json($res['http'], $res);
    }

    function bc_pg_try_handle_payhub_checkout_webhook($db, $raw_body = null, $target_scope = 'user')
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') return false;
        $target_scope = ($target_scope === 'vendor') ? 'vendor' : 'user';
        $raw = is_string($raw_body) && $raw_body !== '' ? $raw_body : bc_fintech_webhook_raw_input();
        if (trim($raw) === '') return false;
        $payload = json_decode($raw, true);
        if (!is_array($payload)) return false;

        $data = is_array($payload['data'] ?? null) ? $payload['data'] : $payload;
        $receiving_acct = trim((string)($data['receiving_account_number'] ?? ($payload['receiving_account_number'] ?? '')));
        if ($receiving_acct !== '') {
            // DVA deposit: always route to strict bc_ph_endpoint($target_scope).
            return false;
        }

        $meta = is_array($data['metadata'] ?? null) ? $data['metadata'] : [];
        $ref = bc_pg_sanitize_reference((string)($data['reference'] ?? ($payload['reference'] ?? '')));
        $meta_checkout_ref = bc_pg_sanitize_reference((string)($meta['checkout_reference'] ?? ''));
        $meta_local_ref = bc_pg_sanitize_reference((string)($meta['local_reference'] ?? ''));
        if ($ref === '' && $meta_checkout_ref === '' && $meta_local_ref === '') return false;

        $meta_target = trim((string)($meta['target'] ?? ''));
        $is_checkout_payload = ($meta_target === $target_scope)
            || ($target_scope === 'user' && $meta_target === '' && ($meta_local_ref !== '' || $meta_checkout_ref !== '' || (is_array($data['source'] ?? null) && (($data['source']['source'] ?? '') === 'checkout'))));

        bc_pg_ensure_tables($db);
        $host_vid = ($target_scope === 'user' && function_exists('resolveVendorID')) ? (int)resolveVendorID() : 0;
        $intent = ($ref !== '' ? bc_pg_lookup_intent($db, $ref, $host_vid > 0 ? $host_vid : 0) : null)
            ?: ($meta_checkout_ref !== '' ? bc_pg_lookup_intent($db, $meta_checkout_ref, $host_vid > 0 ? $host_vid : 0) : null)
            ?: ($meta_local_ref !== '' ? bc_pg_lookup_intent($db, $meta_local_ref, $host_vid > 0 ? $host_vid : 0) : null);

        if ($intent && (($intent['target'] ?? 'user') !== $target_scope)) {
            return false;
        }
        if (!$intent && $meta_target !== '' && $meta_target !== $target_scope) {
            return false;
        }

        $event = strtolower(trim((string)($payload['event'] ?? '')));
        if ($event !== '' && $event !== 'charge.success') {
            if ($intent || $is_checkout_payload) {
                bc_pg_emit_webhook_json(200, ['status' => 'ignored', 'code' => 'EVENT_IGNORED', 'message' => 'Event ignored']);
                return true;
            }
            return false;
        }

        $vid = 0;
        $username = '';
        if ($intent) {
            $vid = (int)$intent['vendor_id'];
            $username = (string)$intent['username'];
        } elseif ($target_scope === 'vendor' && $is_checkout_payload && !empty($meta['vendor_id'])) {
            $cand_vid = (int)$meta['vendor_id'];
            if ($cand_vid > 0 && ($db instanceof mysqli)) {
                $vq = @mysqli_query($db, "SELECT id, email FROM sas_vendors WHERE id='$cand_vid' AND status='1' AND (is_blocked IS NULL OR is_blocked=0) LIMIT 1");
                if ($vq && ($vr = mysqli_fetch_assoc($vq))) {
                    $vid = (int)$vr['id'];
                    $username = (string)($vr['email'] ?? ('vendor' . $vid . '@wallet.local'));
                }
            }
        } elseif ($target_scope === 'user' && $is_checkout_payload && !empty($meta['vendor_id']) && !empty($meta['username'])) {
            $cand_vid = (int)$meta['vendor_id'];
            $cand_user = trim((string)$meta['username']);
            if ($cand_vid > 0 && $cand_user !== '' && ($host_vid <= 0 || $cand_vid === $host_vid) && ($db instanceof mysqli)) {
                $cu_esc = mysqli_real_escape_string($db, $cand_user);
                $uq = @mysqli_query($db, "SELECT username FROM sas_users WHERE vendor_id='$cand_vid' AND username='$cu_esc' AND status='1' AND (is_blocked IS NULL OR is_blocked=0) LIMIT 1");
                if ($uq && ($ur = mysqli_fetch_assoc($uq))) {
                    $vid = $cand_vid;
                    $username = (string)$ur['username'];
                }
            }
        }

        if ($vid <= 0 || $username === '' || ($host_vid > 0 && $vid !== $host_vid)) {
            if ($is_checkout_payload) {
                bc_pg_emit_webhook_json(200, ['status' => 'ignored', 'code' => 'OWNER_NOT_RESOLVED', 'message' => 'Checkout owner not resolved']);
                return true;
            }
            return false;
        }

        if ($target_scope === 'vendor') {
            $secrets = [];
            $pcfg = bc_pg_platform_gateway_config($db, 'payhub', false);
            if (is_array($pcfg)) {
                foreach (['webhook_secret', 'secret_key', 'encrypt_key'] as $k) {
                    $val = trim((string)($pcfg[$k] ?? ''));
                    if ($val !== '') $secrets[] = $val;
                }
            }
            $secrets = array_values(array_unique($secrets));
        } else {
            $secrets = bc_pg_payhub_secret_candidates($db, $vid);
        }
        if (empty($secrets)) {
            bc_pg_emit_webhook_json(403, ['status' => 'error', 'code' => 'GATEWAY_UNCONFIGURED', 'message' => 'PayHub not configured']);
            return true;
        }

        $headers = bc_fintech_webhook_headers();
        $sig = $headers['x-payhub-signature'] ?? ($_SERVER['HTTP_X_PAYHUB_SIGNATURE'] ?? '');
        if (!bc_pg_verify_hmac_candidates('sha256', $raw, $sig, $secrets)) {
            bc_pg_emit_webhook_json(401, ['status' => 'error', 'code' => 'INVALID_SIGNATURE', 'message' => 'Invalid PayHub signature']);
            return true;
        }

        $verify_ref = $ref !== '' ? $ref : $meta_checkout_ref;
        $verified = bc_pg_verify_with_provider($db, $vid, 'payhub', $verify_ref, ['is_vendor' => ($target_scope === 'vendor')]);
        if (empty($verified['ok'])) {
            bc_pg_emit_webhook_json(200, ['status' => 'ignored', 'code' => $verified['code'] ?? 'VERIFY_FAILED', 'message' => $verified['message'] ?? 'Verification skipped']);
            return true;
        }

        $v_meta = (array)($verified['metadata'] ?? []);
        if (isset($v_meta['vendor_id']) && (int)$v_meta['vendor_id'] > 0 && (int)$v_meta['vendor_id'] !== $vid) {
            bc_pg_emit_webhook_json(403, ['status' => 'error', 'code' => 'OWNERSHIP_MISMATCH', 'message' => 'Payment vendor mismatch']);
            return true;
        }
        if ($target_scope === 'user' && !empty($v_meta['username']) && strcasecmp((string)$v_meta['username'], $username) !== 0) {
            bc_pg_emit_webhook_json(403, ['status' => 'error', 'code' => 'OWNERSHIP_MISMATCH', 'message' => 'Payment user mismatch']);
            return true;
        }

        if ($intent && is_numeric($intent['amount'] ?? null) && (float)$intent['amount'] > 0 && ((float)$verified['gross_amount'] + 0.01) < (float)$intent['amount']) {
            bc_pg_emit_webhook_json(422, ['status' => 'error', 'code' => 'UNDERPAID', 'message' => 'Amount below checkout intent']);
            return true;
        }

        if ($target_scope === 'vendor') {
            $purpose = trim((string)(($intent['purpose'] ?? '') !== '' ? $intent['purpose'] : ($v_meta['purpose'] ?? 'wallet_funding')));
            $res = bc_pg_settle_vendor_credit(
                $db,
                $vid,
                'payhub',
                $verified['canonical_reference'],
                (string)$verified['event_id'],
                (float)$verified['gross_amount'],
                (float)$verified['credit_amount'],
                (float)$verified['fee'],
                'payhub_vendor_checkout_webhook',
                $purpose,
                array_filter([$ref, $meta_checkout_ref, $meta_local_ref])
            );
            bc_pg_emit_webhook_json($res['http'], $res);
            return true;
        }

        $res = bc_pg_webhook_settle_credit(
            $db,
            $vid,
            $username,
            'payhub',
            $verified['canonical_reference'],
            (string)$verified['event_id'],
            (float)$verified['gross_amount'],
            (float)$verified['credit_amount'],
            (float)$verified['fee'],
            'payhub_checkout_webhook'
        );
        if (!empty($res['ok']) && ($db instanceof mysqli)) {
            $u_esc = mysqli_real_escape_string($db, $username);
            $mark_refs = [];
            foreach ([$ref, $meta_checkout_ref, $meta_local_ref, $verified['canonical_reference']] as $mr) {
                $mr = bc_pg_sanitize_reference((string)$mr);
                if ($mr !== '') $mark_refs[] = mysqli_real_escape_string($db, $mr);
            }
            if (!empty($mark_refs)) {
                $in_mr = "'" . implode("','", array_unique($mark_refs)) . "'";
                @mysqli_query($db, "UPDATE sas_user_payment_checkouts SET status='1' WHERE vendor_id='$vid' AND username='$u_esc' AND reference IN ($in_mr)");
            }
        }
        bc_pg_emit_webhook_json($res['http'], $res);
        return true;
    }
}
