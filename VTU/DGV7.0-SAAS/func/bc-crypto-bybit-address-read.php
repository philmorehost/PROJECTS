<?php
/** Platform evidence read only. Master-account inventory is NOT a customer allocation. */
require_once __DIR__.'/bc-crypto-bybit-identity.php';
require_once __DIR__.'/bc-crypto-bybit-address.php';
function bc_crypto_bybit_address_scope($coin,$chain) {
    if (!is_string($coin)||!in_array($coin,['TRX','USDT'],true)||$chain!=='TRX') throw new RuntimeException('crypto_address_network_unsupported');
}
/** Uses one captured config for all reads; no injected transport or browser-supplied credentials. */
function bc_crypto_bybit_read_master_address($vendor,$env,$expected,$coin,$chain) {
    throw new RuntimeException('bybit_retired');
}
