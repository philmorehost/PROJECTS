<?php
declare(strict_types=1);

/**
 * Generates scoped remittance report exports in CSV and Bank-Statement PDF formats.
 * Respects existing hierarchy financialScope() and treasury-only gateway fee visibility.
 */
final class RemittanceExport
{
    private const MONTH_NAMES = [
        1 => 'January', 2 => 'February', 3 => 'March', 4 => 'April',
        5 => 'May', 6 => 'June', 7 => 'July', 8 => 'August',
        9 => 'September', 10 => 'October', 11 => 'November', 12 => 'December',
    ];

    /**
     * Parses and normalizes filter parameters from GET query strings.
     * Supports both dedicated export_* params and reports_* history params.
     *
     * @return array{q:string,status:string,year:int,from_month:int,to_month:int,format:string}
     */
    public static function parseFilters(array $query): array
    {
        $rawQ = $query['export_q'] ?? ($query['q'] ?? ($query['reports_q'] ?? ''));
        $q = is_string($rawQ) && preg_match('/^.{0,100}$/us', $rawQ) ? trim($rawQ) : '';

        $rawStatus = $query['export_status'] ?? ($query['status'] ?? ($query['reports_status'] ?? ''));
        $status = in_array($rawStatus, ['pending', 'partial', 'paid', 'verified'], true) ? (string)$rawStatus : '';

        $year = filter_var($query['export_year'] ?? ($query['year'] ?? ($query['reports_year'] ?? 0)), FILTER_VALIDATE_INT);
        if (!$year || $year < 2020 || $year > 2100) {
            $year = 0;
        }

        $fromMonth = filter_var($query['export_from_month'] ?? ($query['from_month'] ?? 0), FILTER_VALIDATE_INT);
        if (!$fromMonth || $fromMonth < 1 || $fromMonth > 12) {
            $fromMonth = 0;
        }

        $toMonth = filter_var($query['export_to_month'] ?? ($query['to_month'] ?? 0), FILTER_VALIDATE_INT);
        if (!$toMonth || $toMonth < 1 || $toMonth > 12) {
            $toMonth = 0;
        }

        $singleMonth = filter_var($query['export_month'] ?? ($query['month'] ?? ($query['reports_month'] ?? 0)), FILTER_VALIDATE_INT);
        if ($fromMonth === 0 && $toMonth === 0 && $singleMonth && $singleMonth >= 1 && $singleMonth <= 12) {
            $fromMonth = (int)$singleMonth;
            $toMonth = (int)$singleMonth;
        }

        if ($fromMonth > 0 && $toMonth > 0 && $fromMonth > $toMonth) {
            [$fromMonth, $toMonth] = [$toMonth, $fromMonth];
        }

        $rawFormat = strtolower(trim((string)($query['export_format'] ?? ($query['format'] ?? 'pdf'))));
        $format = in_array($rawFormat, ['csv', 'pdf'], true) ? $rawFormat : 'pdf';

        return [
            'q' => $q,
            'status' => $status,
            'year' => (int)$year,
            'from_month' => (int)$fromMonth,
            'to_month' => (int)$toMonth,
            'format' => $format,
        ];
    }

    /** @return int[] */
    public static function availableYears(PDO $db, array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        $years = [(int)date('Y')];
        if ($ids) {
            $marks = implode(',', array_fill(0, count($ids), '?'));
            $s = $db->prepare("SELECT DISTINCT period_year FROM remittance_ledgers WHERE parish_id IN ($marks) AND period_year IS NOT NULL ORDER BY period_year DESC");
            $s->execute($ids);
            foreach ($s->fetchAll(PDO::FETCH_COLUMN) as $y) {
                $yi = (int)$y;
                if ($yi >= 2020 && $yi <= 2100) {
                    $years[] = $yi;
                }
            }
        }
        $years = array_values(array_unique($years));
        rsort($years);
        return $years;
    }

    public static function isTreasuryRole(array $user): bool
    {
        return in_array((string)($user['role'] ?? ''), ['provincial_admin', 'provincial_treasurer'], true);
    }

    /**
     * Queries all scoped remittance ledger rows matching the requested filters.
     *
     * @return array{filters:array{q:string,status:string,year:int,from_month:int,to_month:int,format:string},rows:array<int,array<string,mixed>>,summary:array<string,mixed>,treasurer:bool,scope_label:string,scope_count:int,period_label:string}
     */
    public static function fetch(PDO $db, array $user, array $query): array
    {
        requireRole($user, ['provincial_admin', 'provincial_treasurer', 'parish_officer', 'area_rep', 'zonal_rep']);
        $filters = self::parseFilters($query);
        $ids = financialScope($db, $user);
        $treasurer = self::isTreasuryRole($user);

        $rows = [];
        if ($ids) {
            $ids = array_values(array_unique(array_map('intval', $ids)));
            $marks = implode(',', array_fill(0, count($ids), '?'));
            $params = $ids;
            $where = "l.parish_id IN ($marks)";

            if ($filters['q'] !== '') {
                $like = '%' . strtr($filters['q'], ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
                $fields = ['h.node_name', 'h.parish_code', 'l.payment_reference', 'CAST(l.gross_income AS CHAR)'];
                $where .= ' AND (' . implode(' OR ', array_map(fn($f) => "$f LIKE ? ESCAPE '!'", $fields)) . ')';
                foreach ($fields as $_) {
                    $params[] = $like;
                }
            }
            if ($filters['status'] !== '') {
                $where .= ' AND l.status = ?';
                $params[] = $filters['status'];
            }
            if ($filters['year'] > 0) {
                $where .= ' AND l.period_year = ?';
                $params[] = $filters['year'];
            }
            if ($filters['from_month'] > 0 && $filters['to_month'] > 0) {
                $where .= ' AND l.period_month >= ? AND l.period_month <= ?';
                $params[] = $filters['from_month'];
                $params[] = $filters['to_month'];
            } elseif ($filters['from_month'] > 0) {
                $where .= ' AND l.period_month >= ?';
                $params[] = $filters['from_month'];
            } elseif ($filters['to_month'] > 0) {
                $where .= ' AND l.period_month <= ?';
                $params[] = $filters['to_month'];
            }

            $sql = "SELECT l.id, l.parish_id, l.period_month, l.period_year, l.gross_income, l.provincial_levy_due, l.amount_paid, l.status, l.payment_reference,
                           h.node_name, h.node_type, h.parish_code,
                           p.node_name AS parent_name, p.node_type AS parent_type,
                           CASE
                             WHEN h.node_type = 'zone' THEN h.node_name
                             WHEN p.node_type = 'zone' THEN p.node_name
                             ELSE COALESCE(z.node_name, '')
                           END AS zone_name,
                           CASE
                             WHEN h.node_type = 'area' THEN h.node_name
                             WHEN p.node_type = 'area' THEN p.node_name
                             ELSE ''
                           END AS area_name
                    FROM remittance_ledgers l
                    JOIN church_hierarchy h ON h.id = l.parish_id
                    LEFT JOIN church_hierarchy p ON h.parent_id = p.id
                    LEFT JOIN church_hierarchy z ON p.parent_id = z.id
                    WHERE $where
                    ORDER BY COALESCE(l.period_year, 0) DESC, COALESCE(l.period_month, 0) DESC, h.node_name ASC, l.id DESC";
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll();
        }

        if ($treasurer && $rows && class_exists('PayHub')) {
            PayHub::attachLedgerFees($db, $rows);
        }

        // Attach latest payment metadata (channel/reference/date) for bank-report richness
        $latestPaymentByLedger = [];
        if ($rows) {
            $ledgerIds = array_values(array_unique(array_map(fn($r) => (int)$r['id'], $rows)));
            $lMarks = implode(',', array_fill(0, count($ledgerIds), '?'));
            try {
                $ps = $db->prepare(
                    "SELECT ledger_id, status, transfer_reference, transfer_date, payhub_reference, payhub_channel, payhub_paid_at
                     FROM payment_notifications
                     WHERE ledger_id IN ($lMarks)
                     ORDER BY CASE WHEN status='approved' THEN 0 WHEN status='pending' THEN 1 ELSE 2 END, id DESC"
                );
                $ps->execute($ledgerIds);
                foreach ($ps->fetchAll() as $pn) {
                    $lid = (int)$pn['ledger_id'];
                    if (!isset($latestPaymentByLedger[$lid])) {
                        $latestPaymentByLedger[$lid] = $pn;
                    }
                }
            } catch (Throwable) {
                // Keep export resilient even if optional notification columns are absent
            }
        }

        $grossTotal = 0;
        $retainedTotal = 0;
        $dueTotal = 0;
        $paidTotal = 0;
        $balanceTotal = 0;
        $feeTotal = 0;
        $netTotal = 0;
        $verifiedCount = 0;

        foreach ($rows as &$r) {
            $grossCents = self::safeCents($r['gross_income'] ?? '0.00');
            $dueCents = self::safeCents($r['provincial_levy_due'] ?? '0.00');
            $paidCents = self::safeCents($r['amount_paid'] ?? '0.00');
            $retainedCents = max(0, $grossCents - $dueCents);
            $balanceCents = max(0, $dueCents - $paidCents);

            $grossTotal += $grossCents;
            $retainedTotal += $retainedCents;
            $dueTotal += $dueCents;
            $paidTotal += $paidCents;
            $balanceTotal += $balanceCents;
            if (($r['status'] ?? '') === 'verified') {
                $verifiedCount++;
            }

            $r['_gross_cents'] = $grossCents;
            $r['_retained_cents'] = $retainedCents;
            $r['_due_cents'] = $dueCents;
            $r['_paid_cents'] = $paidCents;
            $r['_balance_cents'] = $balanceCents;

            if ($treasurer) {
                $feeCents = self::safeCents($r['payhub_fee_total'] ?? '0.00');
                $netCents = array_key_exists('net_settled_total', $r)
                    ? self::safeCents($r['net_settled_total'])
                    : max(0, $paidCents - $feeCents);
                $feeTotal += $feeCents;
                $netTotal += $netCents;
                $r['_fee_cents'] = $feeCents;
                $r['_net_cents'] = $netCents;
            } else {
                unset($r['payhub_fee_total'], $r['net_settled_total'], $r['has_payhub_payment']);
            }

            $pn = $latestPaymentByLedger[(int)$r['id']] ?? null;
            if ($pn) {
                $payRef = trim((string)($pn['payhub_reference'] ?: ($pn['transfer_reference'] ?? '')));
                $payCh = trim((string)($pn['payhub_channel'] ?? ''));
                $payChLabel = $payCh !== '' ? ucwords(str_replace('_', ' ', $payCh)) : ($pn['payhub_reference'] ? 'PayHub' : 'Bank Transfer');
                $payDt = trim((string)($pn['payhub_paid_at'] ?: ($pn['transfer_date'] ?? '')));
                $payDtShort = $payDt !== '' ? date('d M Y', strtotime($payDt) ?: time()) : '';
                $r['_payment_summary'] = trim($payChLabel . ($payRef !== '' ? ' - ' . $payRef : '') . ($payDtShort !== '' ? ' (' . $payDtShort . ')' : ''));
            } else {
                $r['_payment_summary'] = '';
            }
        }
        unset($r);

        [$scopeLabel, $scopeDetail] = self::describeScope($db, $user, $ids);
        $count = count($rows);
        $compliancePct = $count > 0 ? (int)round(($verifiedCount / $count) * 100) : 0;

        $summary = [
            'count' => $count,
            'verified_count' => $verifiedCount,
            'compliance_pct' => $compliancePct,
            'gross_cents' => $grossTotal,
            'retained_cents' => $retainedTotal,
            'due_cents' => $dueTotal,
            'paid_cents' => $paidTotal,
            'balance_cents' => $balanceTotal,
        ];
        if ($treasurer) {
            $summary['fee_cents'] = $feeTotal;
            $summary['net_cents'] = $netTotal;
        }

        return [
            'filters' => $filters,
            'rows' => $rows,
            'summary' => $summary,
            'treasurer' => $treasurer,
            'scope_label' => $scopeLabel,
            'scope_detail' => $scopeDetail,
            'scope_count' => count($ids),
            'period_label' => self::formatPeriodLabel($filters),
        ];
    }

    public static function filename(array $filters, string $ext): string
    {
        $parts = ['remit-reports'];
        if ($filters['year'] > 0) {
            $parts[] = (string)$filters['year'];
        }
        if ($filters['from_month'] > 0 && $filters['to_month'] > 0) {
            if ($filters['from_month'] === $filters['to_month']) {
                $parts[] = str_pad((string)$filters['from_month'], 2, '0', STR_PAD_LEFT);
            } else {
                $parts[] = strtolower(substr(self::MONTH_NAMES[$filters['from_month']], 0, 3))
                    . '-' . strtolower(substr(self::MONTH_NAMES[$filters['to_month']], 0, 3));
            }
        } elseif ($filters['from_month'] > 0) {
            $parts[] = 'from-' . strtolower(substr(self::MONTH_NAMES[$filters['from_month']], 0, 3));
        } elseif ($filters['to_month'] > 0) {
            $parts[] = 'to-' . strtolower(substr(self::MONTH_NAMES[$filters['to_month']], 0, 3));
        }
        if ($filters['status'] !== '') {
            $parts[] = $filters['status'];
        }
        if (count($parts) === 1) {
            $parts[] = 'all';
        }
        $base = preg_replace('/[^a-z0-9_-]+/', '-', strtolower(implode('-', $parts)));
        return trim((string)$base, '-') . '.' . $ext;
    }

    public static function formatPeriodLabel(array $filters): string
    {
        $y = (int)$filters['year'];
        $fm = (int)$filters['from_month'];
        $tm = (int)$filters['to_month'];
        $yearStr = $y > 0 ? (string)$y : 'All Years';
        if ($fm > 0 && $tm > 0) {
            if ($fm === $tm) {
                return self::MONTH_NAMES[$fm] . ' ' . $yearStr;
            }
            return self::MONTH_NAMES[$fm] . ' - ' . self::MONTH_NAMES[$tm] . ' (' . $yearStr . ')';
        }
        if ($fm > 0) {
            return 'From ' . self::MONTH_NAMES[$fm] . ' (' . $yearStr . ')';
        }
        if ($tm > 0) {
            return 'Up to ' . self::MONTH_NAMES[$tm] . ' (' . $yearStr . ')';
        }
        return $y > 0 ? ('Full Year ' . $y) : 'All Recorded Periods';
    }

    public static function csv(PDO $db, array $user, array $query): string
    {
        $bundle = self::fetch($db, $user, $query);
        $treasurer = $bundle['treasurer'];
        $rows = $bundle['rows'];

        $stream = fopen('php://temp', 'w+');
        if (!$stream) {
            throw new RuntimeException('Cannot allocate CSV buffer.');
        }

        try {
            $header = [
                'Period',
                'Year',
                'Month',
                'Reference',
                'Church',
                'Unit Type',
                'Parish Code',
                'Area',
                'Zone',
                'Total Offering (NGN)',
                'Church Retains (NGN)',
                'Provincial Levy Due (NGN)',
                'Confirmed Paid (NGN)',
                'Outstanding Balance (NGN)',
            ];
            if ($treasurer) {
                $header[] = 'Gateway Fees (NGN)';
                $header[] = 'Net Settled (NGN)';
            }
            $header[] = 'Status';
            $header[] = 'Latest Payment';

            fputcsv($stream, $header, ',', '"', '');

            foreach ($rows as $r) {
                $m = (int)($r['period_month'] ?? 0);
                $y = (int)($r['period_year'] ?? 0);
                $period = ($m >= 1 && $m <= 12 && $y > 0)
                    ? date('M Y', mktime(0, 0, 0, $m, 1, $y))
                    : ($y > 0 ? (string)$y : '—');
                $monthName = ($m >= 1 && $m <= 12) ? self::MONTH_NAMES[$m] : '';

                $line = [
                    self::csvSafe($period),
                    $y > 0 ? (string)$y : '',
                    $monthName,
                    self::csvSafe((string)($r['payment_reference'] ?? '')),
                    self::csvSafe((string)($r['node_name'] ?? '')),
                    ucfirst((string)($r['node_type'] ?? '')),
                    self::csvSafe((string)($r['parish_code'] ?? '')),
                    self::csvSafe((string)($r['area_name'] ?? '')),
                    self::csvSafe((string)($r['zone_name'] ?? '')),
                    Money::decimal((int)$r['_gross_cents']),
                    Money::decimal((int)$r['_retained_cents']),
                    Money::decimal((int)$r['_due_cents']),
                    Money::decimal((int)$r['_paid_cents']),
                    Money::decimal((int)$r['_balance_cents']),
                ];
                if ($treasurer) {
                    $line[] = Money::decimal((int)$r['_fee_cents']);
                    $line[] = Money::decimal((int)$r['_net_cents']);
                }
                $line[] = ucfirst((string)($r['status'] ?? ''));
                $line[] = self::csvSafe((string)($r['_payment_summary'] ?? ''));

                fputcsv($stream, $line, ',', '"', '');
            }

            rewind($stream);
            $out = stream_get_contents($stream);
            if ($out === false) {
                throw new RuntimeException('Cannot read generated CSV.');
            }
            return $out;
        } finally {
            fclose($stream);
        }
    }

    public static function pdf(PDO $db, array $user, array $query): string
    {
        $bundle = self::fetch($db, $user, $query);
        $siteTitle = trim(setting($db, 'site_title')) ?: 'RCCG Youth Province';
        return RemittancePdfRenderer::render($bundle, $user, $siteTitle);
    }

    public static function serve(PDO $db, array $user, array $query): never
    {
        $filters = self::parseFilters($query);
        $format = $filters['format'];
        $filename = self::filename($filters, $format);

        if ($format === 'csv') {
            $payload = self::csv($db, $user, $query);
            $contentType = 'text/csv; charset=UTF-8';
        } else {
            $payload = self::pdf($db, $user, $query);
            $contentType = 'application/pdf';
        }

        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, no-store, max-age=0');
        header('Pragma: no-cache');
        header('Content-Type: ' . $contentType);
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($payload));
        echo $payload;
        exit;
    }

    private static function safeCents(mixed $val): int
    {
        $s = trim((string)($val ?? '0'));
        if ($s === '') {
            return 0;
        }
        if (preg_match('/^\d+\.\d{3,}$/', $s)) {
            $s = number_format((float)$s, 2, '.', '');
        }
        if (!preg_match('/^\d{1,13}(?:\.\d{1,2})?$/D', $s)) {
            return 0;
        }
        return Money::cents($s);
    }

    private static function csvSafe(string $val): string
    {
        if ($val !== '' && preg_match('/^[=+\-@\t\r]/', $val)) {
            return "'" . $val;
        }
        return $val;
    }

    /** @return array{0:string,1:string} */
    private static function describeScope(PDO $db, array $user, array $ids): array
    {
        if (self::isTreasuryRole($user)) {
            $unitCount = count($ids);
            return [
                'All Province Churches',
                $unitCount . ' church ' . ($unitCount === 1 ? 'unit' : 'units') . ' in provincial scope',
            ];
        }

        $unitId = (int)($user['hierarchy_id'] ?? 0);
        if ($unitId > 0) {
            $s = $db->prepare(
                "SELECT h.node_name, h.node_type, h.parish_code, p.node_name AS parent_name, p.node_type AS parent_type,
                        CASE WHEN h.node_type='zone' THEN h.node_name WHEN p.node_type='zone' THEN p.node_name ELSE COALESCE(z.node_name,'') END AS zone_name
                 FROM church_hierarchy h
                 LEFT JOIN church_hierarchy p ON h.parent_id = p.id
                 LEFT JOIN church_hierarchy z ON p.parent_id = z.id
                 WHERE h.id = ?"
            );
            $s->execute([$unitId]);
            $u = $s->fetch();
            if ($u) {
                $parts = [ucfirst((string)$u['node_type'])];
                if (!empty($u['parish_code'])) {
                    $parts[] = 'Code ' . $u['parish_code'];
                }
                if (!empty($u['parent_name'])) {
                    $parts[] = 'Under ' . $u['parent_name'];
                }
                if (!empty($u['zone_name']) && $u['zone_name'] !== ($u['parent_name'] ?? '') && $u['node_type'] !== 'zone') {
                    $parts[] = 'Zone: ' . $u['zone_name'];
                }
                if (count($ids) > 1) {
                    $parts[] = count($ids) . ' units in scope';
                }
                return [(string)$u['node_name'], implode(' · ', $parts)];
            }
        }
        return ['Authorized Church Scope', count($ids) . ' church unit(s)'];
    }
}

/**
 * Pure-PHP PDF 1.4 bank-statement report renderer (A4 Landscape, 841.89 x 595.28 pt).
 * Uses standard PDF Type1 Helvetica / Helvetica-Bold with exact WinAnsi metric tables.
 */
final class RemittancePdfRenderer
{
    private const PAGE_W = 841.89;
    private const PAGE_H = 595.28;
    private const MARGIN_X = 36.0;
    private const CONTENT_W = 769.89;
    private const BOTTOM_LIMIT = 540.0;

    // Color palette (RGB 0..1)
    private const C_NAVY = [0.055, 0.227, 0.275];      // #0E3A46
    private const C_TEAL = [0.078, 0.416, 0.388];      // #146A63
    private const C_MINT_BG = [0.949, 0.969, 0.961];   // #F2F7F5
    private const C_ZEBRA = [0.973, 0.984, 0.980];     // #F8FBFA
    private const C_TOTAL_BG = [0.918, 0.953, 0.941];  // #EAF3F0
    private const C_BORDER = [0.827, 0.882, 0.863];    // #D3E1DC
    private const C_INK = [0.090, 0.184, 0.239];       // #172F3D
    private const C_MUTED = [0.290, 0.392, 0.451];     // #4A6473
    private const C_WHITE = [1.0, 1.0, 1.0];

    private const C_BLUE = [0.169, 0.424, 0.690];      // #2B6CB0
    private const C_PURPLE = [0.420, 0.275, 0.757];    // #6B46C1
    private const C_GREEN = [0.020, 0.588, 0.412];     // #059669
    private const C_AMBER = [0.706, 0.325, 0.035];     // #B45309

    /** WinAnsi standard Helvetica glyph widths (0..255 ASCII printable subset + fallback 556) */
    private const W_REG = [
        32=>278,33=>278,34=>355,35=>556,36=>556,37=>889,38=>667,39=>191,40=>333,41=>333,42=>389,43=>584,44=>278,45=>333,46=>278,47=>278,
        48=>556,49=>556,50=>556,51=>556,52=>556,53=>556,54=>556,55=>556,56=>556,57=>556,58=>278,59=>278,60=>584,61=>584,62=>584,63=>556,
        64=>1015,65=>667,66=>667,67=>722,68=>722,69=>667,70=>611,71=>778,72=>722,73=>278,74=>500,75=>667,76=>556,77=>833,78=>722,79=>778,
        80=>667,81=>778,82=>722,83=>667,84=>611,85=>722,86=>667,87=>944,88=>667,89=>667,90=>611,91=>278,92=>278,93=>278,94=>469,95=>556,
        96=>333,97=>556,98=>556,99=>500,100=>556,101=>556,102=>278,103=>556,104=>556,105=>222,106=>222,107=>500,108=>222,109=>833,110=>556,
        111=>556,112=>556,113=>556,114=>333,115=>500,116=>278,117=>556,118=>500,119=>722,120=>500,121=>500,122=>500,123=>334,124=>260,
        125=>334,126=>584,183=>278,
    ];

    /** WinAnsi standard Helvetica-Bold glyph widths */
    private const W_BOLD = [
        32=>278,33=>333,34=>474,35=>556,36=>556,37=>889,38=>722,39=>238,40=>333,41=>333,42=>389,43=>584,44=>278,45=>333,46=>278,47=>278,
        48=>556,49=>556,50=>556,51=>556,52=>556,53=>556,54=>556,55=>556,56=>556,57=>556,58=>333,59=>333,60=>584,61=>584,62=>584,63=>611,
        64=>975,65=>722,66=>722,67=>722,68=>722,69=>667,70=>611,71=>778,72=>722,73=>278,74=>556,75=>722,76=>611,77=>833,78=>722,79=>778,
        80=>667,81=>778,82=>722,83=>667,84=>611,85=>722,86=>667,87=>944,88=>667,89=>667,90=>611,91=>333,92=>278,93=>333,94=>584,95=>556,
        96=>333,97=>556,98=>611,99=>556,100=>611,101=>556,102=>333,103=>611,104=>611,105=>278,106=>278,107=>556,108=>278,109=>889,110=>611,
        111=>611,112=>611,113=>611,114=>389,115=>556,116=>333,117=>611,118=>556,119=>778,120=>556,121=>556,122=>500,123=>389,124=>280,
        125=>389,126=>584,183=>278,
    ];

    /** @var string[] */
    private array $pages = [];
    private string $buf = '';

    public static function render(array $bundle, array $user, string $siteTitle): string
    {
        $doc = new self();
        return $doc->build($bundle, $user, $siteTitle);
    }

    private function build(array $bundle, array $user, string $siteTitle): string
    {
        $treasurer = (bool)$bundle['treasurer'];
        $rows = $bundle['rows'];
        $summary = $bundle['summary'];
        $filters = $bundle['filters'];

        $statementId = 'RMT-' . date('Ymd') . '-' . strtoupper(substr(md5(json_encode([$filters, $user['id'] ?? 0, $summary['count']]) ?: ''), 0, 6));
        $generatedAt = gmdate('d M Y, H:i') . ' UTC';
        $roleLabel = ucwords(str_replace('_', ' ', (string)($user['role'] ?? 'officer')));
        $preparedFor = trim((string)($user['full_name'] ?? 'Authorized User')) . ' (' . $roleLabel . ')';

        $cols = self::columns($treasurer);

        // Start Page 1
        $this->startPage();
        $y = $this->drawMainHeader($siteTitle, $statementId, $generatedAt, $bundle['period_label'], $preparedFor);
        $y = $this->drawAccountCard($y, $bundle, $user, $filters);
        $y = $this->drawSummaryCards($y, $summary, $treasurer);
        $y = $this->drawTableHeader($y + 6.0, $cols);

        if (!$rows) {
            $this->drawRect(self::MARGIN_X, $y, self::CONTENT_W, 44.0, self::C_MINT_BG, self::C_BORDER, 0.75);
            $this->drawTextCenter('No remittance reports match the selected month, year or status filters.', self::MARGIN_X, self::CONTENT_W, $y + 25.0, 'F2', 9.0, self::C_MUTED);
            $y += 52.0;
        } else {
            foreach ($rows as $idx => $r) {
                $rowSpec = $this->prepareRow($idx + 1, $r, $cols, $treasurer);
                $rowH = $rowSpec['height'];
                if ($y + $rowH > self::BOTTOM_LIMIT) {
                    $this->finishPage();
                    $this->startPage();
                    $y = $this->drawContinuationHeader($siteTitle, $statementId, $bundle['period_label'], $bundle['scope_label']);
                    $y = $this->drawTableHeader($y, $cols);
                }
                $this->drawDataRow($y, $rowSpec, $cols, ($idx % 2) === 1);
                $y += $rowH;
            }

            if ($y + 30.0 > self::BOTTOM_LIMIT) {
                $this->finishPage();
                $this->startPage();
                $y = $this->drawContinuationHeader($siteTitle, $statementId, $bundle['period_label'], $bundle['scope_label']);
                $y = $this->drawTableHeader($y, $cols);
            }
            $y = $this->drawTotalsRow($y, $summary, $cols, $treasurer);
        }

        // Verification footnote at bottom of final statement
        if ($y + 28.0 <= self::BOTTOM_LIMIT) {
            $note = 'END OF STATEMENT  |  Statement Ref ' . $statementId
                . '  |  All figures verified in Nigerian Naira (NGN) from REMIT scoped ledger records.';
            $this->drawTextLeft($note, self::MARGIN_X, $y + 16.0, 'F1', 7.5, self::C_MUTED);
        }

        $this->finishPage();

        // Stamp exact "Page X of Y" footers on every page
        $totalPages = count($this->pages);
        $scopeNote = 'Scope: ' . $bundle['scope_label'] . '  |  Currency: NGN';
        $leftFooter = 'CONFIDENTIAL TREASURY STATEMENT  |  ' . strtoupper($siteTitle) . '  |  REMIT';
        for ($i = 0; $i < $totalPages; $i++) {
            $footer = '';
            $footer .= $this->cmdLine(self::MARGIN_X, 558.0, self::MARGIN_X + self::CONTENT_W, 558.0, self::C_BORDER, 0.75);
            $footer .= $this->cmdTextLeft($leftFooter, self::MARGIN_X, 571.0, 'F1', 7.0, self::C_MUTED);
            $footer .= $this->cmdTextCenter($scopeNote, self::MARGIN_X, self::CONTENT_W, 571.0, 'F1', 7.0, self::C_MUTED);
            $footer .= $this->cmdTextRight('Page ' . ($i + 1) . ' of ' . $totalPages, self::MARGIN_X + self::CONTENT_W, 571.0, 'F2', 7.5, self::C_INK);
            $this->pages[$i] .= $footer;
        }

        return $this->compilePdf($siteTitle, $bundle['period_label']);
    }

    private function drawMainHeader(string $siteTitle, string $statementId, string $generatedAt, string $periodLabel, string $preparedFor): float
    {
        // Top institutional accent stripe across full page width
        $this->drawRect(0.0, 0.0, self::PAGE_W, 5.0, self::C_TEAL);

        // Brand badge + wordmark
        $x = self::MARGIN_X;
        $this->drawRect($x, 22.0, 28.0, 28.0, self::C_TEAL);
        $this->drawTextCenter('R', $x, 28.0, 41.0, 'F2', 16.0, self::C_WHITE);

        $this->drawTextLeft('remit.', $x + 36.0, 38.0, 'F2', 17.0, self::C_NAVY);
        $this->drawTextLeft('PROVINCIAL TREASURY  -  ' . strtoupper($siteTitle), $x + 36.0, 51.0, 'F2', 8.0, self::C_TEAL);
        $this->drawTextLeft('Prepared by: ' . $preparedFor, $x + 36.0, 63.0, 'F1', 7.5, self::C_MUTED);

        // Right-aligned formal statement block
        $rx = self::MARGIN_X + self::CONTENT_W;
        $this->drawTextRight('REMITTANCE TRANSACTION STATEMENT', $rx, 35.0, 'F2', 13.5, self::C_NAVY);
        $this->drawTextRight('Statement Ref: ' . $statementId . '   |   Currency: NGN', $rx, 49.0, 'F1', 8.0, self::C_INK);
        $this->drawTextRight('Period: ' . $periodLabel . '   |   Generated: ' . $generatedAt, $rx, 61.0, 'F1', 7.5, self::C_MUTED);

        $this->drawLine(self::MARGIN_X, 74.0, $rx, 74.0, self::C_BORDER, 1.0);
        return 82.0;
    }

    private function drawContinuationHeader(string $siteTitle, string $statementId, string $periodLabel, string $scopeLabel): float
    {
        $this->drawRect(0.0, 0.0, self::PAGE_W, 4.0, self::C_TEAL);
        $rx = self::MARGIN_X + self::CONTENT_W;
        $this->drawTextLeft(strtoupper($siteTitle) . '  -  REMITTANCE TRANSACTION STATEMENT (CONTINUED)', self::MARGIN_X, 26.0, 'F2', 9.5, self::C_NAVY);
        $this->drawTextRight('Ref: ' . $statementId . '  |  ' . $periodLabel . '  |  ' . $scopeLabel, $rx, 26.0, 'F1', 7.5, self::C_MUTED);
        $this->drawLine(self::MARGIN_X, 34.0, $rx, 34.0, self::C_BORDER, 0.75);
        return 42.0;
    }

    private function drawAccountCard(float $y, array $bundle, array $user, array $filters): float
    {
        $cardH = 52.0;
        $this->drawRect(self::MARGIN_X, $y, self::CONTENT_W, $cardH, self::C_MINT_BG, self::C_BORDER, 0.75);
        $midX = self::MARGIN_X + 395.0;
        $this->drawLine($midX, $y + 8.0, $midX, $y + $cardH - 8.0, self::C_BORDER, 0.75);

        // Left: Account / Church Unit Details
        $lx = self::MARGIN_X + 12.0;
        $this->drawTextLeft('ACCOUNT / CHURCH SCOPE', $lx, $y + 14.0, 'F2', 6.8, self::C_TEAL);
        $this->drawTextLeft($this->truncate($bundle['scope_label'], 'F2', 10.0, 370.0), $lx, $y + 27.0, 'F2', 10.0, self::C_INK);
        $this->drawTextLeft($this->truncate($bundle['scope_detail'], 'F1', 7.8, 370.0), $lx, $y + 39.0, 'F1', 7.8, self::C_MUTED);
        $this->drawTextLeft('Account Email: ' . (string)($user['email'] ?? '—'), $lx, $y + 49.0, 'F1', 7.2, self::C_MUTED);

        // Right: Reporting Filters Applied
        $rx = $midX + 14.0;
        $col2 = $rx + 185.0;
        $this->drawTextLeft('STATEMENT FILTERS & CRITERIA', $rx, $y + 14.0, 'F2', 6.8, self::C_TEAL);

        $yearText = $filters['year'] > 0 ? (string)$filters['year'] : 'All Years';
        $monthText = $this->monthRangeLabel($filters);
        $statusText = $filters['status'] !== '' ? ucfirst($filters['status']) : 'All Statuses';
        $searchText = $filters['q'] !== '' ? '"' . $filters['q'] . '"' : 'None';

        $this->drawTextLeft('Year: ', $rx, $y + 27.0, 'F2', 7.8, self::C_MUTED);
        $this->drawTextLeft($yearText, $rx + 28.0, $y + 27.0, 'F2', 7.8, self::C_INK);
        $this->drawTextLeft('Months: ', $rx, $y + 39.0, 'F2', 7.8, self::C_MUTED);
        $this->drawTextLeft($this->truncate($monthText, 'F2', 7.8, 140.0), $rx + 36.0, $y + 39.0, 'F2', 7.8, self::C_INK);

        $this->drawTextLeft('Status: ', $col2, $y + 27.0, 'F2', 7.8, self::C_MUTED);
        $this->drawTextLeft($statusText, $col2 + 34.0, $y + 27.0, 'F2', 7.8, self::C_INK);
        $this->drawTextLeft('Reports: ', $col2, $y + 39.0, 'F2', 7.8, self::C_MUTED);
        $this->drawTextLeft($bundle['summary']['count'] . ' (' . $bundle['summary']['verified_count'] . ' verified)', $col2 + 38.0, $y + 39.0, 'F2', 7.8, self::C_INK);
        $this->drawTextLeft('Search Filter: ' . $this->truncate($searchText, 'F1', 7.2, 290.0), $rx, $y + 49.0, 'F1', 7.2, self::C_MUTED);

        return $y + $cardH + 8.0;
    }

    private function drawSummaryCards(float $y, array $summary, bool $treasurer): float
    {
        $cards = [
            ['TOTAL OFFERING (100%)', self::fmtMoney((int)$summary['gross_cents']), 'Gross offering reported', self::C_BLUE],
            ['CHURCH RETAINED', self::fmtMoney((int)$summary['retained_cents']), 'Retained at local unit', self::C_TEAL],
            ['PROVINCIAL LEVY DUE', self::fmtMoney((int)$summary['due_cents']), 'Total allocation payable', self::C_PURPLE],
            ['CONFIRMED PAID', self::fmtMoney((int)$summary['paid_cents']), $summary['verified_count'] . ' of ' . $summary['count'] . ' verified (' . $summary['compliance_pct'] . '%)', self::C_GREEN],
            ['OUTSTANDING BALANCE', self::fmtMoney((int)$summary['balance_cents']), ((int)$summary['balance_cents'] > 0) ? 'Awaiting payment/approval' : 'Fully settled', ((int)$summary['balance_cents'] > 0) ? self::C_AMBER : self::C_GREEN],
        ];

        $gap = 8.0;
        $n = count($cards);
        $cardW = (self::CONTENT_W - $gap * ($n - 1)) / $n;
        $cardH = 48.0;

        foreach ($cards as $i => [$label, $val, $sub, $accent]) {
            $cx = self::MARGIN_X + $i * ($cardW + $gap);
            $this->drawRect($cx, $y, $cardW, $cardH, self::C_WHITE, self::C_BORDER, 0.75);
            $this->drawRect($cx, $y, $cardW, 3.0, $accent);
            $this->drawTextLeft($label, $cx + 9.0, $y + 15.0, 'F2', 6.4, self::C_MUTED);
            $this->drawTextLeft($this->truncate($val, 'F2', 10.5, $cardW - 16.0), $cx + 9.0, $y + 30.0, 'F2', 10.5, self::C_INK);
            $this->drawTextLeft($this->truncate($sub, 'F1', 6.8, $cardW - 16.0), $cx + 9.0, $y + 41.5, 'F1', 6.8, self::C_MUTED);
        }

        $nextY = $y + $cardH + 6.0;
        if ($treasurer) {
            $barH = 18.0;
            $this->drawRect(self::MARGIN_X, $nextY, self::CONTENT_W, $barH, self::C_TOTAL_BG, self::C_BORDER, 0.6);
            $feeStr = self::fmtMoney((int)($summary['fee_cents'] ?? 0));
            $netStr = self::fmtMoney((int)($summary['net_cents'] ?? $summary['paid_cents']));
            $line = 'TREASURY SETTLEMENT RECONCILIATION:    Confirmed Credits: ' . self::fmtMoney((int)$summary['paid_cents'])
                . '    |    PayHub Gateway Fees: ' . $feeStr
                . '    |    Net Settled to Province: ' . $netStr;
            $this->drawTextLeft($line, self::MARGIN_X + 10.0, $nextY + 12.0, 'F2', 7.3, self::C_NAVY);
            $nextY += $barH + 4.0;
        }

        return $nextY;
    }

    /** @return array<int,array{key:string,label:string,w:float,align:string}> */
    private static function columns(bool $treasurer): array
    {
        if ($treasurer) {
            // Total width = 769.89 pt
            return [
                ['key' => 'idx', 'label' => '#', 'w' => 20.0, 'align' => 'C'],
                ['key' => 'period', 'label' => 'PERIOD', 'w' => 48.0, 'align' => 'L'],
                ['key' => 'ref', 'label' => 'REFERENCE', 'w' => 90.0, 'align' => 'L'],
                ['key' => 'church', 'label' => 'CHURCH / HIERARCHY', 'w' => 135.0, 'align' => 'L'],
                ['key' => 'gross', 'label' => 'OFFERING', 'w' => 66.0, 'align' => 'R'],
                ['key' => 'retained', 'label' => 'RETAINED', 'w' => 60.0, 'align' => 'R'],
                ['key' => 'due', 'label' => 'LEVY DUE', 'w' => 61.0, 'align' => 'R'],
                ['key' => 'paid', 'label' => 'CONFIRMED', 'w' => 65.0, 'align' => 'R'],
                ['key' => 'fee', 'label' => 'FEE', 'w' => 46.0, 'align' => 'R'],
                ['key' => 'net', 'label' => 'NET SETTLED', 'w' => 65.0, 'align' => 'R'],
                ['key' => 'balance', 'label' => 'BALANCE', 'w' => 61.89, 'align' => 'R'],
                ['key' => 'status', 'label' => 'STATUS', 'w' => 52.0, 'align' => 'C'],
            ];
        }
        // Representative view (NO Gateway Fee or Net Settled columns), Total width = 769.89 pt
        return [
            ['key' => 'idx', 'label' => '#', 'w' => 24.0, 'align' => 'C'],
            ['key' => 'period', 'label' => 'PERIOD', 'w' => 58.0, 'align' => 'L'],
            ['key' => 'ref', 'label' => 'REFERENCE / PAYMENT', 'w' => 112.0, 'align' => 'L'],
            ['key' => 'church', 'label' => 'CHURCH / HIERARCHY', 'w' => 162.0, 'align' => 'L'],
            ['key' => 'gross', 'label' => 'OFFERING (100%)', 'w' => 78.0, 'align' => 'R'],
            ['key' => 'retained', 'label' => 'RETAINED', 'w' => 72.0, 'align' => 'R'],
            ['key' => 'due', 'label' => 'LEVY DUE', 'w' => 72.0, 'align' => 'R'],
            ['key' => 'paid', 'label' => 'CONFIRMED', 'w' => 72.0, 'align' => 'R'],
            ['key' => 'balance', 'label' => 'BALANCE', 'w' => 65.89, 'align' => 'R'],
            ['key' => 'status', 'label' => 'STATUS', 'w' => 54.0, 'align' => 'C'],
        ];
    }

    private function drawTableHeader(float $y, array $cols): float
    {
        $h = 22.0;
        $this->drawRect(self::MARGIN_X, $y, self::CONTENT_W, $h, self::C_NAVY);
        $cx = self::MARGIN_X;
        $ty = $y + 14.0;
        foreach ($cols as $c) {
            $w = (float)$c['w'];
            if ($c['align'] === 'R') {
                $this->drawTextRight($c['label'], $cx + $w - 5.0, $ty, 'F2', 6.8, self::C_WHITE);
            } elseif ($c['align'] === 'C') {
                $this->drawTextCenter($c['label'], $cx, $w, $ty, 'F2', 6.8, self::C_WHITE);
            } else {
                $this->drawTextLeft($c['label'], $cx + 5.0, $ty, 'F2', 6.8, self::C_WHITE);
            }
            $cx += $w;
        }
        return $y + $h;
    }

    private function prepareRow(int $idx, array $r, array $cols, bool $treasurer): array
    {
        $m = (int)($r['period_month'] ?? 0);
        $y = (int)($r['period_year'] ?? 0);
        $period = ($m >= 1 && $m <= 12 && $y > 0)
            ? date('M Y', mktime(0, 0, 0, $m, 1, $y))
            : ($y > 0 ? (string)$y : '—');

        $colWByKey = [];
        foreach ($cols as $c) {
            $colWByKey[$c['key']] = (float)$c['w'];
        }

        $churchName = strtoupper(trim((string)($r['node_name'] ?? 'CHURCH')));
        $subParts = [];
        if (!empty($r['parish_code'])) {
            $subParts[] = (string)$r['parish_code'];
        }
        if (!empty($r['area_name']) && $r['area_name'] !== $r['node_name']) {
            $subParts[] = (string)$r['area_name'];
        }
        if (!empty($r['zone_name']) && $r['zone_name'] !== $r['node_name'] && $r['zone_name'] !== ($r['area_name'] ?? '')) {
            $subParts[] = (string)$r['zone_name'];
        }
        $churchSub = implode(' - ', $subParts);

        $churchLines = $this->wrapLines($churchName, 'F2', 7.4, $colWByKey['church'] - 10.0, 2);
        $hasChurchSub = $churchSub !== '';
        $hasPaySub = !empty($r['_payment_summary']);

        $linesCount = max(count($churchLines) + ($hasChurchSub ? 1 : 0), $hasPaySub ? 2 : 1);
        $rowH = max(21.0, 9.0 + $linesCount * 9.5);

        $cells = [
            'idx' => (string)$idx,
            'period' => $period,
            'ref' => (string)($r['payment_reference'] ?? '—'),
            'ref_sub' => (string)($r['_payment_summary'] ?? ''),
            'church_lines' => $churchLines,
            'church_sub' => $churchSub !== '' ? $this->truncate($churchSub, 'F1', 6.4, $colWByKey['church'] - 10.0) : '',
            'gross' => self::fmtNum((int)$r['_gross_cents']),
            'retained' => self::fmtNum((int)$r['_retained_cents']),
            'due' => self::fmtNum((int)$r['_due_cents']),
            'paid' => self::fmtNum((int)$r['_paid_cents']),
            'balance' => self::fmtNum((int)$r['_balance_cents']),
            'status' => strtolower((string)($r['status'] ?? 'pending')),
        ];
        if ($treasurer) {
            $feeC = (int)($r['_fee_cents'] ?? 0);
            $cells['fee'] = $feeC > 0 ? self::fmtNum($feeC) : '-';
            $cells['net'] = ((int)$r['_paid_cents'] > 0) ? self::fmtNum((int)($r['_net_cents'] ?? 0)) : '-';
        }

        return ['height' => $rowH, 'cells' => $cells];
    }

    private function drawDataRow(float $y, array $rowSpec, array $cols, bool $zebra): void
    {
        $h = (float)$rowSpec['height'];
        $cells = $rowSpec['cells'];
        if ($zebra) {
            $this->drawRect(self::MARGIN_X, $y, self::CONTENT_W, $h, self::C_ZEBRA);
        }
        $this->drawLine(self::MARGIN_X, $y + $h, self::MARGIN_X + self::CONTENT_W, $y + $h, self::C_BORDER, 0.45);

        $cx = self::MARGIN_X;
        $baseY = $y + 13.0;
        foreach ($cols as $c) {
            $k = $c['key'];
            $w = (float)$c['w'];
            if ($k === 'church') {
                $ly = $baseY;
                foreach ($cells['church_lines'] as $cl) {
                    $this->drawTextLeft($cl, $cx + 5.0, $ly, 'F2', 7.3, self::C_INK);
                    $ly += 9.2;
                }
                if ($cells['church_sub'] !== '') {
                    $this->drawTextLeft($cells['church_sub'], $cx + 5.0, $ly, 'F1', 6.3, self::C_MUTED);
                }
            } elseif ($k === 'ref') {
                $this->drawTextLeft($this->truncate($cells['ref'], 'F2', 7.2, $w - 10.0), $cx + 5.0, $baseY, 'F2', 7.2, self::C_INK);
                if ($cells['ref_sub'] !== '') {
                    $this->drawTextLeft($this->truncate($cells['ref_sub'], 'F1', 6.1, $w - 10.0), $cx + 5.0, $baseY + 9.2, 'F1', 6.1, self::C_MUTED);
                }
            } elseif ($k === 'status') {
                [$fill, $fg] = $this->statusColors($cells['status']);
                $pillW = min($w - 6.0, 44.0);
                $pillX = $cx + ($w - $pillW) / 2.0;
                $pillY = $y + ($h - 13.0) / 2.0;
                $this->drawRect($pillX, $pillY, $pillW, 13.0, $fill);
                $this->drawTextCenter(strtoupper($cells['status']), $pillX, $pillW, $pillY + 9.2, 'F2', 6.0, $fg);
            } elseif ($c['align'] === 'R') {
                $val = (string)($cells[$k] ?? '0.00');
                $color = ($k === 'balance' && $val !== '0.00' && $val !== '-') ? self::C_AMBER : self::C_INK;
                $font = ($k === 'paid' || $k === 'net') ? 'F2' : 'F1';
                $this->drawTextRightFit($val, $cx + $w - 5.0, $baseY, $font, 7.4, $w - 8.0, $color);
            } elseif ($c['align'] === 'C') {
                $this->drawTextCenter((string)($cells[$k] ?? ''), $cx, $w, $baseY, 'F1', 7.2, self::C_MUTED);
            } else {
                $this->drawTextLeft($this->truncate((string)($cells[$k] ?? ''), 'F1', 7.3, $w - 8.0), $cx + 5.0, $baseY, 'F1', 7.3, self::C_INK);
            }
            $cx += $w;
        }
    }

    private function drawTotalsRow(float $y, array $summary, array $cols, bool $treasurer): float
    {
        $h = 24.0;
        $this->drawRect(self::MARGIN_X, $y, self::CONTENT_W, $h, self::C_TOTAL_BG);
        $this->drawLine(self::MARGIN_X, $y, self::MARGIN_X + self::CONTENT_W, $y, self::C_NAVY, 1.0);
        $this->drawLine(self::MARGIN_X, $y + $h, self::MARGIN_X + self::CONTENT_W, $y + $h, self::C_NAVY, 1.0);

        $totals = [
            'gross' => self::fmtNum((int)$summary['gross_cents']),
            'retained' => self::fmtNum((int)$summary['retained_cents']),
            'due' => self::fmtNum((int)$summary['due_cents']),
            'paid' => self::fmtNum((int)$summary['paid_cents']),
            'balance' => self::fmtNum((int)$summary['balance_cents']),
        ];
        if ($treasurer) {
            $totals['fee'] = self::fmtNum((int)($summary['fee_cents'] ?? 0));
            $totals['net'] = self::fmtNum((int)($summary['net_cents'] ?? $summary['paid_cents']));
        }

        $cnt = (int)$summary['count'];
        $totalLabel = 'STATEMENT TOTAL (' . $cnt . ($cnt === 1 ? ' REPORT)' : ' REPORTS)');
        $cx = self::MARGIN_X;
        $ty = $y + 15.0;
        foreach ($cols as $c) {
            $k = $c['key'];
            $w = (float)$c['w'];
            if ($k === 'church') {
                $this->drawTextLeft($this->truncate($totalLabel, 'F2', 7.4, $w - 8.0), $cx + 5.0, $ty, 'F2', 7.4, self::C_NAVY);
            } elseif (isset($totals[$k])) {
                $this->drawTextRightFit($totals[$k], $cx + $w - 5.0, $ty, 'F2', 7.4, $w - 8.0, self::C_NAVY);
            } elseif ($k === 'status') {
                $this->drawTextCenter($summary['compliance_pct'] . '%', $cx, $w, $ty, 'F2', 7.2, self::C_TEAL);
            }
            $cx += $w;
        }
        return $y + $h;
    }

    /** @return array{0:float[],1:float[]} */
    private function statusColors(string $status): array
    {
        return match ($status) {
            'verified' => [[0.863, 0.988, 0.906], [0.086, 0.396, 0.204]],
            'paid' => [[0.859, 0.918, 0.996], [0.118, 0.251, 0.686]],
            'partial' => [[0.996, 0.953, 0.780], [0.573, 0.251, 0.055]],
            default => [[1.000, 0.953, 0.804], [0.475, 0.318, 0.000]],
        };
    }

    private function monthRangeLabel(array $filters): string
    {
        $names = [1=>'January',2=>'February',3=>'March',4=>'April',5=>'May',6=>'June',7=>'July',8=>'August',9=>'September',10=>'October',11=>'November',12=>'December'];
        $fm = (int)$filters['from_month'];
        $tm = (int)$filters['to_month'];
        if ($fm > 0 && $tm > 0) {
            return $fm === $tm ? $names[$fm] : ($names[$fm] . ' - ' . $names[$tm]);
        }
        if ($fm > 0) {
            return 'From ' . $names[$fm];
        }
        if ($tm > 0) {
            return 'Up to ' . $names[$tm];
        }
        return 'All Months';
    }

    private static function fmtNum(int $cents): string
    {
        $d = Money::decimal($cents);
        [$n, $f] = explode('.', $d);
        return preg_replace('/\B(?=(\d{3})+(?!\d))/', ',', $n) . '.' . $f;
    }

    private static function fmtMoney(int $cents): string
    {
        return 'NGN ' . self::fmtNum($cents);
    }

    private function startPage(): void
    {
        $this->buf = '';
    }

    private function finishPage(): void
    {
        $this->pages[] = $this->buf;
        $this->buf = '';
    }

    private static function py(float $yTop): float
    {
        return self::PAGE_H - $yTop;
    }

    private function drawRect(float $x, float $yTop, float $w, float $h, array $fill, ?array $stroke = null, float $lw = 0.75): void
    {
        $py = self::PAGE_H - $yTop - $h;
        $out = sprintf("q %.3F %.3F %.3F rg ", $fill[0], $fill[1], $fill[2]);
        if ($stroke !== null) {
            $out .= sprintf("%.3F w %.3F %.3F %.3F RG %.2F %.2F %.2F %.2F re B Q\n", $lw, $stroke[0], $stroke[1], $stroke[2], $x, $py, $w, $h);
        } else {
            $out .= sprintf("%.2F %.2F %.2F %.2F re f Q\n", $x, $py, $w, $h);
        }
        $this->buf .= $out;
    }

    private function drawLine(float $x1, float $yTop1, float $x2, float $yTop2, array $color, float $lw): void
    {
        $this->buf .= $this->cmdLine($x1, $yTop1, $x2, $yTop2, $color, $lw);
    }

    private function cmdLine(float $x1, float $yTop1, float $x2, float $yTop2, array $color, float $lw): string
    {
        return sprintf("q %.3F w %.3F %.3F %.3F RG %.2F %.2F m %.2F %.2F l S Q\n", $lw, $color[0], $color[1], $color[2], $x1, self::py($yTop1), $x2, self::py($yTop2));
    }

    private function drawTextLeft(string $text, float $x, float $yTop, string $font, float $size, array $color): void
    {
        $this->buf .= $this->cmdTextLeft($text, $x, $yTop, $font, $size, $color);
    }

    private function drawTextRight(string $text, float $xRight, float $yTop, string $font, float $size, array $color): void
    {
        $this->buf .= $this->cmdTextRight($text, $xRight, $yTop, $font, $size, $color);
    }

    private function drawTextRightFit(string $text, float $xRight, float $yTop, string $font, float $size, float $maxWidth, array $color): void
    {
        $w = $this->measure($text, $font, $size);
        $fitSize = $w > $maxWidth && $w > 0.0 ? max(5.2, $size * ($maxWidth / $w)) : $size;
        $this->buf .= $this->cmdTextRight($text, $xRight, $yTop, $font, $fitSize, $color);
    }

    private function drawTextCenter(string $text, float $x, float $w, float $yTop, string $font, float $size, array $color): void
    {
        $this->buf .= $this->cmdTextCenter($text, $x, $w, $yTop, $font, $size, $color);
    }

    private function cmdTextLeft(string $text, float $x, float $yTop, string $font, float $size, array $color): string
    {
        $encoded = $this->encodePdfText($text);
        return sprintf("BT /%s %.2F Tf %.3F %.3F %.3F rg 1 0 0 1 %.2F %.2F Tm (%s) Tj ET\n", $font, $size, $color[0], $color[1], $color[2], $x, self::py($yTop), $encoded);
    }

    private function cmdTextRight(string $text, float $xRight, float $yTop, string $font, float $size, array $color): string
    {
        $w = $this->measure($text, $font, $size);
        return $this->cmdTextLeft($text, $xRight - $w, $yTop, $font, $size, $color);
    }

    private function cmdTextCenter(string $text, float $x, float $colW, float $yTop, string $font, float $size, array $color): string
    {
        $w = $this->measure($text, $font, $size);
        return $this->cmdTextLeft($text, $x + max(0.0, ($colW - $w) / 2.0), $yTop, $font, $size, $color);
    }

    private function normalizeText(string $text): string
    {
        $text = str_replace(
            ['₦', '—', '–', '•', '·', '‘', '’', '“', '”', '…', "\r", "\n", "\t"],
            ['NGN ', '-', '-', ' - ', ' - ', "'", "'", '"', '"', '...', ' ', ' ', ' '],
            $text
        );
        $text = (string)preg_replace('/\s+/', ' ', trim($text));
        if (function_exists('iconv')) {
            $conv = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
            if ($conv !== false) {
                $text = $conv;
            }
        }
        return (string)preg_replace('/[^\x20-\x7E]/', '', $text);
    }

    private function encodePdfText(string $text): string
    {
        $s = $this->normalizeText($text);
        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $s);
    }

    private function measure(string $text, string $font, float $size): float
    {
        $s = $this->normalizeText($text);
        $table = $font === 'F2' ? self::W_BOLD : self::W_REG;
        $sum = 0;
        $len = strlen($s);
        for ($i = 0; $i < $len; $i++) {
            $ord = ord($s[$i]);
            $sum += $table[$ord] ?? 556;
        }
        return ($sum / 1000.0) * $size;
    }

    private function truncate(string $text, string $font, float $size, float $maxWidth): string
    {
        $clean = $this->normalizeText($text);
        if ($this->measure($clean, $font, $size) <= $maxWidth) {
            return $clean;
        }
        $ellipsis = '...';
        $ellW = $this->measure($ellipsis, $font, $size);
        $out = '';
        $len = strlen($clean);
        for ($i = 0; $i < $len; $i++) {
            $cand = $out . $clean[$i];
            if ($this->measure($cand, $font, $size) + $ellW > $maxWidth) {
                break;
            }
            $out = $cand;
        }
        return rtrim($out) . $ellipsis;
    }

    /** @return string[] */
    private function wrapLines(string $text, string $font, float $size, float $maxWidth, int $maxLines = 2): array
    {
        $clean = $this->normalizeText($text);
        if ($clean === '') {
            return [''];
        }
        if ($this->measure($clean, $font, $size) <= $maxWidth) {
            return [$clean];
        }
        $words = explode(' ', $clean);
        $lines = [];
        $current = '';
        foreach ($words as $word) {
            $cand = $current === '' ? $word : ($current . ' ' . $word);
            if ($this->measure($cand, $font, $size) <= $maxWidth) {
                $current = $cand;
                continue;
            }
            if ($current !== '') {
                $lines[] = $current;
                if (count($lines) >= $maxLines) {
                    $lines[$maxLines - 1] = $this->truncate($lines[$maxLines - 1] . ' ' . $word, $font, $size, $maxWidth);
                    return $lines;
                }
                $current = $word;
            } else {
                $lines[] = $this->truncate($word, $font, $size, $maxWidth);
                if (count($lines) >= $maxLines) {
                    return $lines;
                }
                $current = '';
            }
        }
        if ($current !== '') {
            if (count($lines) >= $maxLines) {
                $lines[$maxLines - 1] = $this->truncate($lines[$maxLines - 1] . ' ' . $current, $font, $size, $maxWidth);
            } else {
                $lines[] = $current;
            }
        }
        return $lines ?: [$clean];
    }

    private function compilePdf(string $siteTitle, string $periodLabel): string
    {
        $objects = [];
        // 1: Catalog, 2: Pages, 3: F1 Helvetica, 4: F2 Helvetica-Bold, 5: Info
        $objects[3] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>";
        $objects[4] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>";

        $metaTitle = $this->encodePdfText($siteTitle . ' - Remittance Statement (' . $periodLabel . ')');
        $metaCreator = $this->encodePdfText('REMIT Provincial Treasury');
        $metaDate = 'D:' . gmdate('YmdHis') . 'Z';
        $objects[5] = "<< /Title ($metaTitle) /Author ($metaCreator) /Creator ($metaCreator) /CreationDate ($metaDate) >>";

        $pageRefs = [];
        $nextId = 6;
        $canCompress = function_exists('gzcompress');

        foreach ($this->pages as $stream) {
            $contentId = $nextId++;
            $pageId = $nextId++;
            if ($canCompress) {
                $compressed = gzcompress($stream, 6);
                if ($compressed !== false) {
                    $objects[$contentId] = "<< /Length " . strlen($compressed) . " /Filter /FlateDecode >>\nstream\n" . $compressed . "\nendstream";
                } else {
                    $objects[$contentId] = "<< /Length " . strlen($stream) . " >>\nstream\n" . $stream . "\nendstream";
                }
            } else {
                $objects[$contentId] = "<< /Length " . strlen($stream) . " >>\nstream\n" . $stream . "\nendstream";
            }
            $objects[$pageId] = sprintf(
                "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %.2F %.2F] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents %d 0 R >>",
                self::PAGE_W,
                self::PAGE_H,
                $contentId
            );
            $pageRefs[] = $pageId . ' 0 R';
        }

        $objects[1] = "<< /Type /Catalog /Pages 2 0 R >>";
        $objects[2] = "<< /Type /Pages /Kids [" . implode(' ', $pageRefs) . "] /Count " . count($pageRefs) . " >>";

        ksort($objects);
        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [0 => 0];
        $maxId = (int)max(array_keys($objects));
        for ($id = 1; $id <= $maxId; $id++) {
            $offsets[$id] = strlen($pdf);
            $pdf .= $id . " 0 obj\n" . $objects[$id] . "\nendobj\n";
        }

        $xrefOffset = strlen($pdf);
        $pdf .= "xref\n0 " . ($maxId + 1) . "\n";
        $pdf .= "0000000000 65535 f \n";
        for ($id = 1; $id <= $maxId; $id++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$id]);
        }
        $pdf .= "trailer\n<< /Size " . ($maxId + 1) . " /Root 1 0 R /Info 5 0 R >>\n";
        $pdf .= "startxref\n" . $xrefOffset . "\n%%EOF\n";
        return $pdf;
    }
}
