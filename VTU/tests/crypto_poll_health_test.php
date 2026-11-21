<?php
require dirname(__DIR__).'/DGV7.0-SAAS/func/bc-crypto-poll-health.php';
$n=0;function health_check($s,$want,$action){global $n;$r=bc_crypto_poll_health($s,1000);if($r['health']!==$want||$r['recommended_action']!==$action||$r['automatic_retry_authorized']||$r['replacement_window_authorized']||$r['history_complete']||$r['settlement_enabled'])throw new RuntimeException('Health mismatch '.$want);$n++;}
$s=['state'=>'ready','valid_until_epoch'=>1100,'page_count'=>0,'lease_until'=>0,'failed_attempts'=>0];
health_check($s,'ready_for_manual_attempt','fresh_stepup_and_current_guards_required');
health_check(array_merge($s,['lease_until'=>1001]),'read_lease_held','wait_or_explicitly_stop_no_parallel_retry');
health_check(array_merge($s,['lease_until'=>1000]),'lease_expired_unknown_outcome','refresh_revision_before_manual_retry');
health_check(array_merge($s,['failed_attempts'=>1]),'attempt_review_required','review_evidence_and_failures_before_manual_retry');
health_check(array_merge($s,['failed_attempts'=>1,'lease_until'=>1001]),'read_lease_held','wait_or_explicitly_stop_no_parallel_retry');
health_check(array_merge($s,['valid_until_epoch'=>1000,'lease_until'=>1001]),'expired','stop_then_review_replacement_window');
health_check(array_merge($s,['page_count'=>100]),'page_limit','stop_and_investigate_coverage_gap');
health_check(array_merge($s,['state'=>'exhausted','valid_until_epoch'=>900]),'window_exhausted','review_coverage_before_new_window');
health_check(array_merge($s,['state'=>'stopped','lease_until'=>1100]),'stopped','preserve_evidence_no_restart');
foreach(['bc-crypto-poll-health.php','bc-crypto-poll-review.php'] as $f){$a=file_get_contents(dirname(__DIR__).'/DGV7.0-SAAS/func/'.$f);token_get_all($a,TOKEN_PARSE);if($a!==file_get_contents(dirname(__DIR__).'/DGV7.0-NON-SAAS/func/'.$f))throw new RuntimeException('Edition mismatch');$n++;}
echo "$n polling health and edition parity checks passed; advice grants no retry or settlement authority.\n";
