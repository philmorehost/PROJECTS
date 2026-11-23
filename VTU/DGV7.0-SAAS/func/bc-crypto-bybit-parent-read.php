<?php
/** Explicit authenticated relationship evidence only. No customer binding or address request. */
require_once __DIR__.'/bc-crypto-bybit-identity.php';
function bc_crypto_bybit_read_parent($subSource,$masterSource,$env,$subIdentity,$masterIdentity) {
    throw new RuntimeException('bybit_retired');
}
