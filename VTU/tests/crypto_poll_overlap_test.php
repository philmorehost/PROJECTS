<?php
require dirname(__DIR__).'/DGV7.0-SAAS/func/bc-crypto-poll-overlap.php';
$n=0;foreach([false,true] as $pair)foreach([false,true] as $conflict)foreach([false,true] as $snapshot){$want=$conflict?'conflict':(!$pair?'first_seen':($snapshot?'repeat':'changed'));if(bc_crypto_poll_overlap_classify($pair,$conflict,$snapshot)!==$want)throw new RuntimeException('Classification failed');$n++;}
foreach(['bc-crypto-poll-overlap.php','bc-crypto-poll-overlap-schema.php'] as $f){$a=file_get_contents(dirname(__DIR__).'/DGV7.0-SAAS/func/'.$f);if($a!==file_get_contents(dirname(__DIR__).'/DGV7.0-NON-SAAS/func/'.$f))throw new RuntimeException('Edition mismatch');token_get_all($a,TOKEN_PARSE);$n++;}
echo "$n overlap classification/parity checks passed; no import or settlement.\n";
