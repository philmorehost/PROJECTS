<?php
/** Internal provider evidence read: raw UIDs come only from authenticated key-info responses. */
require_once __DIR__.'/bc-crypto-bybit-identity.php';
require_once __DIR__.'/bc-crypto-bybit-sub-address.php';
function bc_crypto_bybit_read_sub_address($relationship,$identity,$env,$coin,$chain){
    throw new RuntimeException('bybit_retired');
}
