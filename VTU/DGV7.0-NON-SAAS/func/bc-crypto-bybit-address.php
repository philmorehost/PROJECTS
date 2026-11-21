<?php
/** Pure offline parser only. No provider call, authority, allocation or active-route writer. */
function bc_crypto_tron_address_validate($address) {
    if (!is_string($address) || !preg_match('/^T[1-9A-HJ-NP-Za-km-z]{33}$/D', $address)) throw new RuntimeException('crypto_address_invalid');
    $alphabet='123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz';
    // Big-endian base conversion using bounded bytes, without GMP or integer overflow.
    $bytes=[0];
    for ($i=0;$i<strlen($address);$i++) {
        $carry=strpos($alphabet,$address[$i]);
        for ($j=count($bytes)-1;$j>=0;$j--) {
            $carry += $bytes[$j]*58;
            $bytes[$j]=$carry & 255;
            $carry=intdiv($carry,256);
        }
        while ($carry>0) {array_unshift($bytes,$carry & 255);$carry=intdiv($carry,256);}
    }
    $raw=pack('C*',...$bytes);
    if (strlen($raw)!==25 || ord($raw[0])!==65 || !hash_equals(substr(hash('sha256',hash('sha256',substr($raw,0,21),true),true),0,4),substr($raw,21))) throw new RuntimeException('crypto_address_invalid');
    // Checksum/version is syntax only: it cannot prove ownership, allocation or network.
    return $address;
}
/** Accept only a decoded result object, NOT an HTTP/envelope response. Caller must authenticate it. */
function bc_crypto_bybit_address_parse($result,$coin,$chain) {
    if (!is_string($coin) || !in_array($coin,['TRX','USDT'],true) || $chain!=='TRX') throw new RuntimeException('crypto_address_network_unsupported');
    if (!is_array($result) || ($result['coin']??null)!==$coin || !is_array($result['chains']??null) || count($result['chains'])<1 || count($result['chains'])>64 || array_keys($result['chains'])!==range(0,count($result['chains'])-1)) throw new RuntimeException('crypto_address_response_invalid');
    $selected=null;$seen=[];
    foreach ($result['chains'] as $row) {
        if (!is_array($row) || !is_string($row['chain']??null) || !preg_match('/^[A-Za-z0-9_-]{1,32}$/D',$row['chain']) || isset($seen[$row['chain']])) throw new RuntimeException('crypto_address_response_invalid');
        $seen[$row['chain']]=true;
        // chainType is a display label, NEVER the canonical chain identifier.
        if ($row['chain']!==$chain) continue;
        if (!is_string($row['tagDeposit']??null) || $row['tagDeposit']!=='') throw new RuntimeException('crypto_address_tag_unsupported');
        $selected=bc_crypto_tron_address_validate($row['addressDeposit']??null);
    }
    if ($selected===null) throw new RuntimeException('crypto_address_chain_missing');
    return ['asset_network'=>$coin.':'.$chain,'address'=>$selected,'tag'=>'',
        'validation'=>'tron_base58check_v1','provider_authenticated'=>false,
        'custody_eligibility_verified'=>false,'customer_attribution_verified'=>false,'live_enabled'=>false];
}
