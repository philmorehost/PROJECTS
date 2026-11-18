<?php
/**
 * DGV7 phase 1C step 2 — account-type backfill, per-tier service matrix, and the KYB upgrade flow.
 *
 * OPERATING RULES
 *   - account_level is NEVER written here. It keeps driving prices, parameter tables and the
 *     legacy API, so remapping it would silently re-price live customers.
 *   - The backfill only fills rows whose account_type is NULL. It never overwrites an explicit
 *     tier, so it is safe to re-run and safe to run after a manual correction.
 *   - KYB approval is the ONLY path that flips an account to business, and it revokes live app
 *     sessions in the same step: business accounts are web-only, so leaving app sessions alive
 *     would contradict the access policy.
 *   - Nothing here is wired into payment capture. The optional upgrade fee is recorded and then
 *     charged through the existing ledger helper, after approval, never before.
 */

require_once __DIR__ . '/bc-gateway-policy.php';

/** Service slugs that may be gated per tier. Kept stable: these strings are in the database. */
if (!function_exists('bc_fintech_matrix_services')) {
    function bc_fintech_matrix_services() {
        return [
            'developer_api' => 'Developer API (merchant keys, webhooks)',
            'airtime'       => 'Airtime',
            'esim'          => '1GLOBAL eSIM',
            'tuition'       => 'Tuition payments',
            'bills'         => 'Bills & utilities (Flutterwave)',
            'invoices'      => 'Invoices (Flutterwave)',
            'pos'           => 'POS terminals',
            'transfers'     => 'Accounts & transfers',
            'cross_border'  => 'Cross-border payouts',
            'stablecoin'    => 'Stablecoin wallets (USDC/USDT/RLUSD)',
            'fx'            => 'FX & conversion',
            'virtual_accounts' => 'Virtual accounts',
            'referrals'     => 'Two-sided referrals',
        ];
    }
}

if (!function_exists('bc_fintech_service_locked_off')) {
    /**
     * Services that can NEVER be enabled for a tier, regardless of switches or entitlements.
     * developer_api is locked off for personal accounts: that is the blueprint rule, and it is
     * enforced in the decision primitive too, so the UI and the runtime cannot disagree.
     */
    function bc_fintech_service_locked_off($service, $account_type) {
        return strtolower(trim((string)$service)) === 'developer_api'
            && strtolower(trim((string)$account_type)) === 'personal';
    }
}

// ─────────────────────────────── backfill ───────────────────────────────

if (!function_exists('bc_fintech_backfill_dry_run')) {
    /**
     * Counts what an account_type backfill WOULD do, without writing anything.
     * Returns rows for: personal (levels 1,2), business (level 3), already-set, and unknown.
     */
    function bc_fintech_backfill_dry_run($vendor_id) {
        global $connection_server;
        $vendor_id = (int)$vendor_id;
        $out = ['personal' => 0, 'business' => 0, 'already_set' => 0, 'unknown_level' => 0, 'invalid_tier' => 0];
        if ($vendor_id <= 0 || !($connection_server instanceof mysqli)) return $out;

        $q = mysqli_query($connection_server, "SELECT account_type, account_level, COUNT(*) AS n
            FROM sas_users WHERE vendor_id='$vendor_id' GROUP BY account_type, account_level");
        if (!$q) return $out;
        while ($row = mysqli_fetch_assoc($q)) {
            $n = (int)$row['n'];
            $tier = $row['account_type'];
            $level = (string)$row['account_level'];
            if ($tier !== null && $tier !== '') {
                if (in_array($tier, ['personal', 'business'], true)) { $out['already_set'] += $n; }
                else { $out['invalid_tier'] += $n; }
                continue;
            }
            if ($level === '1' || $level === '2') { $out['personal'] += $n; }
            elseif ($level === '3') { $out['business'] += $n; }
            else { $out['unknown_level'] += $n; }
        }
        return $out;
    }
}

if (!function_exists('bc_fintech_backfill_account_types')) {
    /**
     * Idempotent backfill: account_level 1,2 -> personal; 3 -> business.
     *
     * Only NULL/empty account_type rows are touched, so explicit tiers and manual corrections
     * survive a re-run. Unknown levels (0, 4, malformed) are left NULL on purpose: guessing a
     * tier for them could grant or remove access that nobody approved.
     *
     * Returns ['updated' => n, 'skipped_unknown' => n] or false when the schema/query is unusable.
     */
    function bc_fintech_backfill_account_types($vendor_id, $dry_run = false) {
        global $connection_server;
        $vendor_id = (int)$vendor_id;
        if ($vendor_id <= 0 || !($connection_server instanceof mysqli)) return false;
        if (!bc_fintech_schema_ready()) return false;

        if ($dry_run) {
            $counts = bc_fintech_backfill_dry_run($vendor_id);
            return ['updated' => $counts['personal'] + $counts['business'], 'skipped_unknown' => $counts['unknown_level']];
        }

        $updated = 0;
        // Two explicit statements, each guarded on the tier being unset. account_level is only
        // READ here, never written.
        foreach ([['1', '2', 'personal'], ['3', '3', 'business']] as $rule) {
            list($lo, $hi, $tier) = $rule;
            $ok = mysqli_query($connection_server, "UPDATE sas_users
                SET account_type='$tier', account_type_source='backfill_2026_11', account_type_at=NOW()
                WHERE vendor_id='$vendor_id'
                  AND (account_type IS NULL OR account_type='')
                  AND account_level IN ('$lo', '$hi')");
            if ($ok === false) return false;
            $updated += (int)mysqli_affected_rows($connection_server);
        }

        $counts = bc_fintech_backfill_dry_run($vendor_id);
        return ['updated' => $updated, 'skipped_unknown' => $counts['unknown_level']];
    }
}

// ─────────────────────────── service matrix ───────────────────────────

if (!function_exists('bc_fintech_matrix_read')) {
    /** Reads the per-tier switches for one tenant, defaulting to enabled when no row exists. */
    function bc_fintech_matrix_read($vendor_id) {
        global $connection_server;
        $vendor_id = (int)$vendor_id;
        $matrix = [];
        if ($vendor_id <= 0 || !($connection_server instanceof mysqli)) return $matrix;
        $q = mysqli_query($connection_server, "SELECT service_name, status, personal_enabled, business_enabled, provider_ready
            FROM sas_service_control WHERE vendor_id='$vendor_id'");
        if (!$q) return $matrix;
        while ($row = mysqli_fetch_assoc($q)) {
            $matrix[$row['service_name']] = [
                'status'           => (int)$row['status'],
                'personal_enabled' => (int)$row['personal_enabled'],
                'business_enabled' => (int)$row['business_enabled'],
                'provider_ready'   => (int)$row['provider_ready'],
            ];
        }
        return $matrix;
    }
}

if (!function_exists('bc_fintech_matrix_set')) {
    /**
     * Writes one switch for one tenant/service. Refuses to enable a service that is locked off
     * for a tier (developer_api for personal) and refuses unknown service names.
     *
     * Returns true on success, or a string explaining the refusal.
     */
    function bc_fintech_matrix_set($vendor_id, $service, $field, $value) {
        global $connection_server;
        $vendor_id = (int)$vendor_id;
        $service = strtolower(trim((string)$service));
        $field = strtolower(trim((string)$field));
        $value = (int)(bool)$value;

        if ($vendor_id <= 0 || !($connection_server instanceof mysqli)) return 'No tenant context.';
        if (!array_key_exists($service, bc_fintech_matrix_services())) return 'Unknown service.';
        if (!in_array($field, ['status', 'personal_enabled', 'business_enabled', 'provider_ready'], true)) return 'Unknown switch.';
        if ($value === 1 && $field === 'personal_enabled' && bc_fintech_service_locked_off($service, 'personal')) {
            return 'Developer API can never be enabled for personal accounts.';
        }

        $svc_esc = mysqli_real_escape_string($connection_server, $service);
        $exists = mysqli_query($connection_server, "SELECT id FROM sas_service_control WHERE vendor_id='$vendor_id' AND service_name='$svc_esc' LIMIT 1");
        if ($exists && mysqli_num_rows($exists) > 0) {
            $ok = mysqli_query($connection_server, "UPDATE sas_service_control SET `$field`='$value' WHERE vendor_id='$vendor_id' AND service_name='$svc_esc'");
        } else {
            $ok = mysqli_query($connection_server, "INSERT INTO sas_service_control (vendor_id, service_name, `$field`) VALUES ('$vendor_id', '$svc_esc', '$value')");
        }
        return $ok ? true : 'Could not save the switch.';
    }
}

if (!function_exists('bc_fintech_tier_effective')) {
    /** Human summary of whether a service is usable by a tier right now, for the admin matrix. */
    function bc_fintech_tier_effective(array $row, $account_type) {
        $type = strtolower(trim((string)$account_type));
        if (!in_array($type, ['personal', 'business'], true)) return false;
        if (!bc_fintech_enabled_flag($row['status'] ?? null)) return false;
        if (!bc_fintech_enabled_flag($row['provider_ready'] ?? null)) return false;
        if ($type === 'personal' && bc_fintech_service_locked_off($row['service_name'] ?? '', 'personal')) return false;
        return bc_fintech_enabled_flag($row[$type . '_enabled'] ?? null);
    }
}

// ───────────────────────────── KYB flow ─────────────────────────────

if (!function_exists('bc_fintech_kyb_status_label')) {
    function bc_fintech_kyb_status_label($status) {
        $labels = [0 => 'Not started', 1 => 'Under review', 2 => 'Approved', 3 => 'Rejected'];
        return $labels[(int)$status] ?? 'Unknown';
    }
}

if (!function_exists('bc_fintech_kyb_categories')) {
    function bc_fintech_kyb_categories() {
        return [
            'sole_trader'   => 'Sole trader',
            'limited_co'    => 'Limited company',
            'partnership'   => 'Partnership',
            'ngo'           => 'NGO / non-profit',
            'fintech'       => 'Fintech / payments',
            'other'         => 'Other',
        ];
    }
}

if (!function_exists('bc_fintech_kyb_submit')) {
    /**
     * Records a business upgrade request. Does NOT change account_type: approval does that.
     *
     * Returns true on success, or a string describing the validation failure.
     */
    function bc_fintech_kyb_submit($user, $vendor_id, array $fields) {
        global $connection_server;
        if (!($connection_server instanceof mysqli)) return 'Database unavailable.';
        if (!bc_fintech_schema_ready()) return 'Account policy tables are not ready.';
        $user_id = (int)($user['id'] ?? 0);
        $vendor_id = (int)$vendor_id;
        if ($user_id <= 0 || $vendor_id <= 0) return 'Invalid account.';

        // Only a personal account can request an upgrade; business is already there.
        $type = bc_fintech_account_type($user);
        if ($type === 'business') return 'This account is already a business account.';
        if ($type !== 'personal') return 'This account is not eligible for a business upgrade.';

        // Re-read rather than trusting the caller's copy: a second submit while a reviewer is
        // looking at the first one must not overwrite the evidence they are reviewing.
        $q_state = mysqli_query($connection_server, "SELECT kyb_status FROM sas_users WHERE id='$user_id' AND vendor_id='$vendor_id' LIMIT 1");
        if (!$q_state || mysqli_num_rows($q_state) !== 1) return 'Account not found.';
        if ((int)(mysqli_fetch_assoc($q_state)['kyb_status'] ?? 0) === 1) return 'Your request is already under review.';

        $name = trim((string)($fields['business_name'] ?? ''));
        $regno = trim((string)($fields['registration_no'] ?? ''));
        $cat = trim((string)($fields['category'] ?? ''));
        $doc = trim((string)($fields['document'] ?? ''));

        // These limits match the VARCHAR widths. mbstring is not guaranteed to be compiled in
        // (this deployment runs `php -n` in CI), so measure with a fallback that still counts
        // characters rather than bytes.
        $len = function ($v) {
            if (function_exists('mb_strlen')) return mb_strlen($v);
            $count = preg_match_all('/./us', $v);
            return $count === false ? strlen($v) : $count;
        };
        if ($name === '' || $len($name) > 200) return 'Enter the registered business name (200 characters max).';
        if ($regno === '' || $len($regno) > 80) return 'Enter the business registration number.';
        if (!array_key_exists($cat, bc_fintech_kyb_categories())) return 'Choose a valid business category.';
        if ($doc === '') return 'Upload your business registration document.';

        $esc = function ($v) use ($connection_server) { return mysqli_real_escape_string($connection_server, $v); };
        $ok = mysqli_query($connection_server, "UPDATE sas_users SET
            kyb_status=1,
            kyb_business_name='" . $esc($name) . "',
            kyb_registration_no='" . $esc($regno) . "',
            kyb_category='" . $esc($cat) . "',
            kyb_document='" . $esc($doc) . "',
            kyb_reference='" . $esc('KYB-' . strtoupper(bin2hex(random_bytes(5)))) . "',
            kyb_submitted_at=NOW(),
            kyb_reviewed_at=NULL,
            kyb_reviewed_by=NULL
            WHERE id='$user_id' AND vendor_id='$vendor_id'");
        return $ok ? true : 'Could not save the request.';
    }
}

if (!function_exists('bc_fintech_kyb_approve')) {
    /**
     * Approves a pending KYB request: the ONLY code path that promotes an account to business.
     *
     * In one step it sets account_type=business, records the reviewer, and revokes every live app
     * session (business accounts are web-only). account_level is untouched.
     *
     * Returns true, or a string explaining the refusal.
     */
    function bc_fintech_kyb_approve($user_id, $vendor_id, $reviewer) {
        global $connection_server;
        if (!($connection_server instanceof mysqli)) return 'Database unavailable.';
        $user_id = (int)$user_id;
        $vendor_id = (int)$vendor_id;
        $reviewer = substr(trim((string)$reviewer), 0, 120);
        if ($user_id <= 0 || $vendor_id <= 0) return 'Invalid account.';
        if ($reviewer === '') return 'Reviewer is required.';

        $q = mysqli_query($connection_server, "SELECT * FROM sas_users WHERE id='$user_id' AND vendor_id='$vendor_id' LIMIT 1");
        if (!$q || mysqli_num_rows($q) !== 1) return 'Account not found.';
        $row = mysqli_fetch_assoc($q);
        if ((int)$row['kyb_status'] !== 1) return 'This request is not awaiting review.';

        $rev = mysqli_real_escape_string($connection_server, $reviewer);
        $ok = mysqli_query($connection_server, "UPDATE sas_users SET
            account_type='business', account_type_source='kyb_approved', account_type_at=NOW(),
            kyb_status=2, kyb_reviewed_at=NOW(), kyb_reviewed_by='$rev'
            WHERE id='$user_id' AND vendor_id='$vendor_id' AND kyb_status=1");
        if (!$ok || mysqli_affected_rows($connection_server) < 1) return 'Could not approve the request.';

        // Business is web-only: kill app sessions in the same operation.
        bc_app_token_revoke_all($user_id, $vendor_id, 'account_type');
        return true;
    }
}

if (!function_exists('bc_fintech_kyb_reject')) {
    /** Rejects a pending request. The account stays personal; the reason is kept for the user. */
    function bc_fintech_kyb_reject($user_id, $vendor_id, $reviewer, $reason = '') {
        global $connection_server;
        if (!($connection_server instanceof mysqli)) return 'Database unavailable.';
        $user_id = (int)$user_id;
        $vendor_id = (int)$vendor_id;
        $reviewer = substr(trim((string)$reviewer), 0, 120);
        if ($user_id <= 0 || $vendor_id <= 0) return 'Invalid account.';
        if ($reviewer === '') return 'Reviewer is required.';

        $q = mysqli_query($connection_server, "SELECT kyb_status FROM sas_users WHERE id='$user_id' AND vendor_id='$vendor_id' LIMIT 1");
        if (!$q || mysqli_num_rows($q) !== 1) return 'Account not found.';
        if ((int)mysqli_fetch_assoc($q)['kyb_status'] !== 1) return 'This request is not awaiting review.';

        $rev = mysqli_real_escape_string($connection_server, $reviewer);
        $note = mysqli_real_escape_string($connection_server, substr(trim((string)$reason), 0, 2000));
        $ok = mysqli_query($connection_server, "UPDATE sas_users SET
            kyb_status=3, kyb_note='$note', kyb_reviewed_at=NOW(), kyb_reviewed_by='$rev'
            WHERE id='$user_id' AND vendor_id='$vendor_id' AND kyb_status=1");
        return ($ok && mysqli_affected_rows($connection_server) > 0) ? true : 'Could not reject the request.';
    }
}

if (!function_exists('bc_fintech_kyb_pending')) {
    /** Review queue for one tenant, oldest first. Never returns another vendor's users. */
    function bc_fintech_kyb_pending($vendor_id, $limit = 100) {
        global $connection_server;
        $vendor_id = (int)$vendor_id;
        $limit = max(1, min(500, (int)$limit));
        $rows = [];
        if ($vendor_id <= 0 || !($connection_server instanceof mysqli)) return $rows;
        $q = mysqli_query($connection_server, "SELECT * FROM sas_users
            WHERE vendor_id='$vendor_id' AND kyb_status=1
            ORDER BY kyb_submitted_at ASC LIMIT $limit");
        if (!$q) return $rows;
        while ($r = mysqli_fetch_assoc($q)) $rows[] = $r;
        return $rows;
    }
}

/**
 * DGV7 phase 2 — marketing presentation helpers.
 *
 * The landing page and the dashboard both list services the tenant may not be able to sell yet.
 * These helpers answer one question honestly: "can a customer actually use this today?" — and
 * they answer it from the SAME three-way rule as the service matrix (status + provider_ready +
 * the tier switch), so a card can never advertise something the account gate would refuse.
 */

if (!function_exists('bc_fintech_marketing_services')) {
    /**
     * The marketing catalogue, in display order. Each entry declares which tiers it belongs to and
     * the blurb shown on its card. Keep this list presentational only: availability comes from the
     * matrix, never from here.
     */
    function bc_fintech_marketing_services() {
        return [
            'transfers' => [
                'name'  => 'USD Wallet',
                'copy'  => 'Hold NGN and receive USD from abroad into one account.',
                'tiers' => ['personal', 'business'],
            ],
            'cross_border' => [
                'name'  => 'Cross-border transfers',
                'copy'  => 'Send money to China and other corridors at a transparent rate.',
                'tiers' => ['personal', 'business'],
            ],
            'fx' => [
                'name'  => 'FX & conversion',
                'copy'  => 'Convert between supported currencies without hidden spread.',
                'tiers' => ['personal', 'business'],
            ],
            'esim' => [
                'name'  => '1GLOBAL eSIM',
                'copy'  => 'Stay connected in 100+ countries with a data eSIM.',
                'tiers' => ['personal'],
            ],
            'tuition' => [
                'name'  => 'Global tuition',
                'copy'  => 'Pay school and university fees abroad directly.',
                'tiers' => ['business'],
            ],
            'invoices' => [
                'name'  => 'Invoices',
                'copy'  => 'Bill international clients and get paid in your currency.',
                'tiers' => ['business'],
            ],
            'pos' => [
                'name'  => 'POS terminals',
                'copy'  => 'Take card payments at the counter with settlement to your wallet.',
                'tiers' => ['business'],
            ],
            'developer_api' => [
                'name'  => 'Developer API',
                'copy'  => 'Build on the platform with merchant keys and webhooks.',
                'tiers' => ['business'],
            ],
        ];
    }
}

if (!function_exists('bc_fintech_marketing_live')) {
    /**
     * Is one service buyable right now for this tenant and tier?
     *
     * Everything fails closed: an unknown service, a missing matrix row, a zero tenant or a null
     * tier all come back false. That matters because the card copy invites a purchase.
     */
    function bc_fintech_marketing_live($vendor_id, $tier, $service, array $catalogue = null) {
        $vendor_id = (int)$vendor_id;
        if ($vendor_id <= 0) return false;

        $tier = strtolower(trim((string)$tier));
        if (!in_array($tier, ['personal', 'business'], true)) return false;

        $service = strtolower(trim((string)$service));
        if ($service === '') return false;

        $catalogue = $catalogue === null ? bc_fintech_marketing_services() : $catalogue;
        // A service the catalogue does not describe is never advertised, live or otherwise.
        if (!isset($catalogue[$service])) return false;
        if (!in_array($tier, $catalogue[$service]['tiers'], true)) return false;

        $matrix = bc_fintech_matrix_read($vendor_id);
        if (!isset($matrix[$service])) return false;

        $row = $matrix[$service];
        $row['service_name'] = $service;
        return bc_fintech_tier_effective($row, $tier);
    }
}

if (!function_exists('bc_fintech_marketing_partition')) {
    /**
     * Split the catalogue into what can be sold now and what cannot, for one tier.
     *
     * Returns ['live' => [...], 'soon' => [...]], each entry carrying name/copy/service. The
     * "soon" bucket is NOT hidden by the callers: unavailable cards render dashed and labelled
     * "Coming soon". Hiding them would make the product look smaller than it is and would give a
     * tenant no signal that the service exists at all.
     */
    function bc_fintech_marketing_partition($vendor_id, $tier) {
        $tier = strtolower(trim((string)$tier));
        $catalogue = bc_fintech_marketing_services();
        $live = [];
        $soon = [];

        foreach ($catalogue as $service => $meta) {
            if (!in_array($tier, $meta['tiers'], true)) continue;
            $entry = [
                'service' => $service,
                'name'    => $meta['name'],
                'copy'    => $meta['copy'],
            ];
            if (bc_fintech_marketing_live($vendor_id, $tier, $service, $catalogue)) {
                $live[] = $entry;
            } else {
                $soon[] = $entry;
            }
        }

        return ['live' => $live, 'soon' => $soon];
    }
}
