<?php
/**
 * Tests the diagnostic report writer and its sensitive-value redaction.
 *
 * Run from the DGV7.0-SAAS folder with: php -n tests/requery_logging_test.php
 */

$source_path = dirname(__DIR__) . '/cron/process_requery_queue.php';
$source = file_get_contents($source_path);
if ($source === false) {
    fwrite(STDERR, "Cannot read $source_path\n");
    exit(1);
}

$start = strpos($source, 'function bc_requery_report_safe_text');
$end = strpos($source, 'if (is_dir($log_dir)', $start);
if ($start === false || $end === false || $end <= $start) {
    fwrite(STDERR, "Cannot locate requery report helpers in $source_path\n");
    exit(1);
}

$report_file = tempnam(sys_get_temp_dir(), 'requery-report-');
$run_id = 'test-run';
eval(substr($source, $start, $end - $start));

$assertions = 0;
$failures = 0;
function check_requery_report($label, $condition)
{
    global $assertions, $failures;
    $assertions++;
    if ($condition) {
        echo "  ok   - $label\n";
    } else {
        $failures++;
        echo "  FAIL - $label\n";
    }
}

$safe_text = bc_requery_report_safe_text(
    '{"api_key":"secret-value","email":"vendor@example.com","msisdn":"2348012345678","status":"pending"}',
    array('secret-value')
);
check_requery_report('redacts configured API credential', strpos($safe_text, 'secret-value') === false);
check_requery_report('redacts email address', strpos($safe_text, 'vendor@example.com') === false);
check_requery_report('redacts long phone/account number', strpos($safe_text, '2348012345678') === false);
check_requery_report('preserves useful provider status text', strpos($safe_text, 'pending') !== false);

bc_requery_queue_log('test_event', array(
    'outcome' => 'pending',
    'reference' => '2348012345678',
    'contact' => 'vendor@example.com'
));
$line = file_get_contents($report_file);
$record = json_decode(trim($line), true);
check_requery_report('writes parseable JSON report record', is_array($record));
check_requery_report('report contains run and event identifiers', ($record['run_id'] ?? '') === 'test-run' && ($record['event'] ?? '') === 'test_event');
check_requery_report('redacts identifiers in report fields', ($record['reference'] ?? '') === '[NUMBER]' && ($record['contact'] ?? '') === '[EMAIL]');

$backup_file = $report_file . '.1';
file_put_contents($report_file, str_repeat('x', 5 * 1024 * 1024));
bc_requery_queue_log('after_rotation');
check_requery_report('rotates report after reaching size limit', file_exists($backup_file));

unlink($report_file);
if (file_exists($backup_file)) unlink($backup_file);
if (file_exists($report_file . '.lock')) unlink($report_file . '.lock');
echo "\n$assertions assertions, $failures failures\n";
exit($failures === 0 ? 0 : 1);
