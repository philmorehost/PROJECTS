<?php
/**
 * KYC review queue - the page must stay a fixed size whatever is in the queue.
 *
 * Run with:  C:\xampp\php\php.exe -n tests/kyc_queue_test.php
 *
 * Why this exists: bc-admin/KYCManagement.php rendered the newest 300 users in one request, each row
 * showing evidence links and re-evaluating the vendor's whole check list, and the evidence endpoint
 * streamed documents while still holding the PHP session lock. Reviewing a user with a liveliness
 * video pinned a worker (and the session) until the browser finished downloading it, so the next page
 * in that same session never loaded and Chrome reported the tab as hung (RESULT_CODE_HUNG).
 *
 * Section A runs the SHIPPED paging block (extracted from the file, so a drift fails loudly).
 * Section B asserts the shipped markup/streaming invariants that keep the page small.
 * Section C fails if the two editions drift apart - this file is duplicated in both.
 */

$ED = array(
    'SAAS'     => __DIR__ . '/..',
    'NON-SAAS' => __DIR__ . '/../../DGV7.0-NON-SAAS',
);

$fails = 0;
$checks = 0;
function bc_assert($label, $actual, $expected) {
    global $fails, $checks;
    $checks++;
    if ($actual === $expected) {
        echo "  ok   $label\n";
    } else {
        $fails++;
        echo "  FAIL $label\n";
        echo "         expected: " . var_export($expected, true) . "\n";
        echo "         actual  : " . var_export($actual, true) . "\n";
    }
}
function bc_assert_true($label, $cond) { bc_assert($label, (bool)$cond, true); }

// ── mysqli stubs: the page only needs COUNT(*) here (run with php -n, no mysqli extension) ────────
// Each stubbed result hands out exactly ONE row and then null, the way a real COUNT(*)/GROUP BY
// result ends - a stub that always returns a row turns the counts loop into an infinite one.
$GLOBALS['kyc_stub_total'] = 0;
$GLOBALS['kyc_stub_queries'] = array();
$GLOBALS['kyc_stub_rows'] = array();
function mysqli_query($link, $query) {
    $GLOBALS['kyc_stub_queries'][] = $query;
    $id = 'STUB_RESULT_' . count($GLOBALS['kyc_stub_queries']);
    $GLOBALS['kyc_stub_rows'][$id] = 1;
    return $id;
}
function mysqli_fetch_assoc($result) {
    if (empty($GLOBALS['kyc_stub_rows'][$result])) return null;
    $GLOBALS['kyc_stub_rows'][$result]--;
    return array('c' => $GLOBALS['kyc_stub_total'], 'kyc_status' => 2);
}
function mysqli_real_escape_string($link, $s) { return addslashes($s); }

$shipped = array();
foreach ($ED as $edition => $root) {
    $shipped[$edition] = file_get_contents($root . '/bc-admin/KYCManagement.php');
}

echo "== A. shipped paging arithmetic (executed from the real file) ==\n";

$src = $shipped['SAAS'];
$start = strpos($src, '// ── Paging');
$closure_at = strpos($src, '$kyc_link = function');
if ($start === false || $closure_at === false) {
    echo "  FAIL cannot locate the paging block in the shipped file\n";
    exit(1);
}
$end_brace = strpos($src, "\n};", $closure_at);
if ($end_brace === false) {
    echo "  FAIL cannot locate the end of the \$kyc_link closure\n";
    exit(1);
}
$block = substr($src, $start, ($end_brace + 3) - $start);

$block_plain = str_replace('<?php', '', $block);
if (strpos($block_plain, '<?') !== false) {
    echo "  FAIL the extracted block still contains a php open tag\n";
    exit(1);
}
// Never embed a closing php tag in an eval'd string: it terminates the enclosing file's parser.
$block_plain = str_replace('?' . '>', '', $block_plain);

function kyc_queue_run($block_plain, $total, $get) {
    global $connection_server, $vid, $where, $status_filter, $search, $order;
    $GLOBALS['kyc_stub_total'] = $total;
    $GLOBALS['kyc_stub_queries'] = array();
    $connection_server = 'STUB';
    $vid = 1;
    $where = "vendor_id='1' AND kyc_status='2'";
    $order = 'kyc_submitted_at DESC, reg_date DESC';
    $status_filter = 2;
    $search = '';
    $_GET = $get;
    eval($block_plain);
    return array(
        'per_page' => $per_page, 'page' => $page, 'total_rows' => $total_rows,
        'total_pages' => $total_pages, 'offset' => $offset,
        'first' => $first_on_page, 'last' => $last_on_page, 'link' => $kyc_link,
        'queries' => $GLOBALS['kyc_stub_queries'],
    );
}

// 1000 verified users, 25 a page, on page 3.
$r = kyc_queue_run($block_plain, 1000, array('status' => 2, 'per_page' => 25, 'page' => 3));
bc_assert('per_page honoured', $r['per_page'], 25);
bc_assert('page honoured', $r['page'], 3);
bc_assert('total rows read', $r['total_rows'], 1000);
bc_assert('total pages', $r['total_pages'], 40);
bc_assert('offset', $r['offset'], 50);
bc_assert('first row label', $r['first'], 51);
bc_assert('last row label', $r['last'], 75);
bc_assert_true('the list query is limited to one page', strpos(implode(' ', $r['queries']), 'LIMIT 25 OFFSET 50') !== false);
bc_assert_true('the row count comes from COUNT(*), not from loading rows', strpos(implode(' ', $r['queries']), 'SELECT COUNT(*) AS c') !== false);

// A page past the end is clamped, not rendered empty.
$r = kyc_queue_run($block_plain, 1000, array('status' => 2, 'per_page' => 25, 'page' => 999));
bc_assert('page past the end clamps to the last page', $r['page'], 40);
bc_assert('clamped offset', $r['offset'], 975);

// Nonsense input is normalised instead of reaching the query.
$r = kyc_queue_run($block_plain, 1000, array('status' => 2, 'per_page' => 13, 'page' => -5));
bc_assert('unknown per_page falls back to 25', $r['per_page'], 25);
bc_assert('page below 1 becomes 1', $r['page'], 1);
bc_assert('offset never negative', $r['offset'], 0);

// An empty queue must not divide by zero or claim rows.
$r = kyc_queue_run($block_plain, 0, array('status' => 2));
bc_assert('empty queue reports no rows', $r['total_rows'], 0);
bc_assert('empty queue still one page', $r['total_pages'], 1);
bc_assert('empty queue first label is 0', $r['first'], 0);
bc_assert('empty queue last label is 0', $r['last'], 0);

// Links keep the filter, the page size and (optionally) the row being reviewed.
$r = kyc_queue_run($block_plain, 1000, array('status' => 2, 'per_page' => 50, 'page' => 2));
$link = $r['link'];
bc_assert('tab link keeps status and page size, drops the page number', $link(array('status' => 3, 'page' => null)), 'KYCManagement.php?status=3&per_page=50');
bc_assert('pager link keeps everything', $link(array('page' => 4)), 'KYCManagement.php?status=2&per_page=50&page=4');
bc_assert('review link carries the page to return to', $link(array('view' => 7, 'page' => 2)), 'KYCManagement.php?status=2&per_page=50&view=7&page=2');

echo "\n== B. shipped invariants (both editions) ==\n";
foreach ($shipped as $edition => $src) {
    echo "-- $edition\n";

    bc_assert_true("$edition: the fixed 300-row list is gone", strpos($src, 'LIMIT 300') === false);
    bc_assert_true("$edition: the list is one page", strpos($src, 'LIMIT $per_page OFFSET $offset') !== false);
    bc_assert_true("$edition: per_page is whitelisted", strpos($src, "!in_array(\$per_page, array(25, 50, 100, 200), true)") !== false);
    bc_assert_true("$edition: page is clamped to the page count", strpos($src, 'if ($page > $total_pages) $page = $total_pages;') !== false);
    bc_assert_true("$edition: status counts come from one grouped query", substr_count($src, 'GROUP BY kyc_status') === 1);
    bc_assert_true("$edition: the ORDER BY no longer filesorts on a redundant IS NULL key", strpos($src, 'kyc_submitted_at IS NULL, kyc_submitted_at DESC') === false);

    // Every generated link must be escaped before it lands in markup.
    bc_assert_true("$edition: generated links are escaped", strpos($src, 'href="<?php echo $kyc_link(') === false);
    bc_assert_true("$edition: pager exists", strpos($src, 'pagination pagination-sm') !== false);

    // Evidence streaming must not hold the session lock, and must answer range requests.
    bc_assert_true("$edition: evidence releases the session lock", strpos($src, 'session_write_close();') !== false);
    bc_assert_true("$edition: evidence advertises byte ranges", strpos($src, "header('Accept-Ranges: bytes');") !== false);
    bc_assert_true("$edition: evidence answers a range with 206", strpos($src, 'http_response_code(206)') !== false);
    bc_assert_true("$edition: evidence rejects an impossible range with 416", strpos($src, 'http_response_code(416)') !== false);
    bc_assert_true("$edition: evidence is streamed in chunks, not readfile()'d", strpos($src, 'readfile($path)') === false);
    bc_assert_true("$edition: the session is released before any bytes are sent", strpos($src, 'session_write_close();') < strpos($src, 'echo $chunk;'));

    // Media must not be fetched just because the review page opened.
    bc_assert_true("$edition: video is not preloaded", strpos($src, 'controls preload="none"') !== false);
    bc_assert_true("$edition: images are lazy", strpos($src, 'loading="lazy"') !== false);

    // Approving from the list returns to the same page.
    bc_assert_true("$edition: the decision form returns to its page", strpos($src, 'name="return_page"') !== false);
    bc_assert_true("$edition: the redirect carries the page", strpos($src, '&page=" . max(1, (int)($_POST[\'return_page\'] ?? 1))') !== false);

    // The index the queue needs.
    $tables = file_get_contents($ED[$edition] . '/func/bc-tables.php');
    bc_assert_true("$edition: queue index declared", strpos($tables, "'idx_users_kyc' => '(vendor_id, kyc_status, id)'") !== false);
    // Read the declared version instead of hard-coding it: a later bump for unrelated DDL must not break
    // this test - only a recorded version older than the one adding the queue index must.
    preg_match("/BC_TABLES_VERSION',\s*'([^']+)'/", $tables, $bc_v);
    bc_assert_true("$edition: schema version is at least the one adding the queue index (" . ($bc_v[1] ?? '?') . ')', version_compare(str_replace('-', '.', $bc_v[1] ?? '0'), '2026.09.19.3', '>='));
}

echo "\n== C. the two editions stay in sync ==\n";
bc_assert('KYCManagement.php is identical in both editions', md5($shipped['SAAS']), md5($shipped['NON-SAAS']));

echo "\n----------------------------------------\n";
echo ($checks - $fails) . "/$checks checks passed" . ($fails ? " — $fails FAILED" : '') . "\n";
exit($fails ? 1 : 0);
