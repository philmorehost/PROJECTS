<?php
/** Explicit bounded inspection only; no importer, cursor/checkpoint writer or settlement. */
require_once __DIR__.'/bc-crypto-bybit-identity.php';
require_once __DIR__.'/bc-crypto-bybit-deposit-records.php';
function bc_crypto_bybit_read_deposit_page($vendor,$env,$expected,$coin,$start,$end,$cursor) {
    throw new RuntimeException('bybit_retired');
}
