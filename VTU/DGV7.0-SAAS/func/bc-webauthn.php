<?php
/**
 * Pure-PHP WebAuthn attestation & assertion cryptographic verifier (ES256 P-256 & RS256).
 * Requires OpenSSL (`openssl_verify`); no external Composer packages required.
 */

if (!function_exists('bc_webauthn_b64u_encode')) {
    function bc_webauthn_b64u_encode($bin) {
        return rtrim(strtr(base64_encode((string)$bin), '+/', '-_'), '=');
    }
}

if (!function_exists('bc_webauthn_b64u_decode')) {
    function bc_webauthn_b64u_decode($b64u) {
        if (!is_string($b64u) || $b64u === '') return false;
        $b64 = strtr($b64u, '-_', '+/');
        $pad = strlen($b64) % 4;
        if ($pad > 0) $b64 .= str_repeat('=', 4 - $pad);
        return base64_decode($b64, true);
    }
}

if (!function_exists('bc_cbor_decode')) {
    /** Decode a single CBOR item from $data starting at &$offset. Throws RuntimeException on malformed input. */
    function bc_cbor_decode($data, &$offset = 0, $depth = 0) {
        if ($depth > 16) throw new RuntimeException('CBOR nesting limit exceeded.');
        $len = strlen($data);
        if ($offset >= $len) throw new RuntimeException('Unexpected end of CBOR data.');
        $initial = ord($data[$offset++]);
        $major = $initial >> 5;
        $ai = $initial & 0x1F;

        $readLen = function ($aiVal) use ($data, &$offset, $len) {
            if ($aiVal < 24) return $aiVal;
            if ($aiVal === 24) {
                if ($offset + 1 > $len) throw new RuntimeException('Truncated CBOR uint8.');
                return ord($data[$offset++]);
            }
            if ($aiVal === 25) {
                if ($offset + 2 > $len) throw new RuntimeException('Truncated CBOR uint16.');
                $v = unpack('n', substr($data, $offset, 2))[1];
                $offset += 2;
                return $v;
            }
            if ($aiVal === 26) {
                if ($offset + 4 > $len) throw new RuntimeException('Truncated CBOR uint32.');
                $v = unpack('N', substr($data, $offset, 4))[1];
                $offset += 4;
                return $v;
            }
            if ($aiVal === 27) {
                if ($offset + 8 > $len) throw new RuntimeException('Truncated CBOR uint64.');
                $v = unpack('J', substr($data, $offset, 8))[1];
                $offset += 8;
                return $v;
            }
            throw new RuntimeException('Unsupported CBOR additional info.');
        };

        switch ($major) {
            case 0: // unsigned int
                return $readLen($ai);
            case 1: // negative int
                return -1 - $readLen($ai);
            case 2: // byte string
            case 3: // text string
                $slen = $readLen($ai);
                if ($offset + $slen > $len) throw new RuntimeException('Truncated CBOR string.');
                $out = substr($data, $offset, $slen);
                $offset += $slen;
                return $out;
            case 4: // array
                $count = $readLen($ai);
                $arr = array();
                for ($i = 0; $i < $count; $i++) {
                    $arr[] = bc_cbor_decode($data, $offset, $depth + 1);
                }
                return $arr;
            case 5: // map
                $count = $readLen($ai);
                $map = array();
                for ($i = 0; $i < $count; $i++) {
                    $k = bc_cbor_decode($data, $offset, $depth + 1);
                    $v = bc_cbor_decode($data, $offset, $depth + 1);
                    if (is_int($k) || is_string($k)) {
                        $map[$k] = $v;
                    }
                }
                return $map;
            case 6: // tag
                $readLen($ai);
                return bc_cbor_decode($data, $offset, $depth + 1);
            case 7: // simple / bool / null
                if ($ai === 20) return false;
                if ($ai === 21) return true;
                if ($ai === 22) return null;
                if ($ai === 23) return null;
                return $ai;
            default:
                throw new RuntimeException('Unsupported CBOR major type.');
        }
    }
}

if (!function_exists('bc_asn1_len')) {
    function bc_asn1_len($len) {
        if ($len < 128) return chr($len);
        $bin = '';
        while ($len > 0) {
            $bin = chr($len & 0xFF) . $bin;
            $len >>= 8;
        }
        return chr(0x80 | strlen($bin)) . $bin;
    }
}

if (!function_exists('bc_asn1_tlv')) {
    function bc_asn1_tlv($tag, $value) {
        return chr($tag) . bc_asn1_len(strlen($value)) . $value;
    }
}

if (!function_exists('bc_asn1_int')) {
    function bc_asn1_int($bytes) {
        $bytes = ltrim((string)$bytes, "\x00");
        if ($bytes === '') $bytes = "\x00";
        if ((ord($bytes[0]) & 0x80) !== 0) $bytes = "\x00" . $bytes;
        return bc_asn1_tlv(0x02, $bytes);
    }
}

if (!function_exists('bc_webauthn_cose_to_pem')) {
    /** Convert a COSE key map (ES256 P-256 or RS256) to PEM SubjectPublicKeyInfo. */
    function bc_webauthn_cose_to_pem($cose) {
        if (!is_array($cose)) return '';
        $kty = (int)($cose[1] ?? 0);
        $alg = (int)($cose[3] ?? 0);

        if ($kty === 2 && ($alg === -7 || $alg === 0)) {
            $crv = (int)($cose[-1] ?? 0);
            $x = $cose[-2] ?? '';
            $y = $cose[-3] ?? '';
            if ($crv !== 1 || !is_string($x) || !is_string($y) || strlen($x) !== 32 || strlen($y) !== 32) {
                return '';
            }
            $prefix = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200');
            $der = $prefix . "\x04" . $x . $y;
            return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
        }

        if ($kty === 3 && ($alg === -257 || $alg === 0)) {
            $n = $cose[-1] ?? '';
            $e = $cose[-2] ?? '';
            if (!is_string($n) || !is_string($e) || $n === '' || $e === '') return '';
            $rsaPub = bc_asn1_tlv(0x30, bc_asn1_int($n) . bc_asn1_int($e));
            $algId = hex2bin('300d06092a864886f70d0101010500');
            $bitString = bc_asn1_tlv(0x03, "\x00" . $rsaPub);
            $der = bc_asn1_tlv(0x30, $algId . $bitString);
            return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
        }

        return '';
    }
}

if (!function_exists('bc_webauthn_origin_matches')) {
    function bc_webauthn_origin_matches($origin, $expectedRpId) {
        if (!is_string($origin) || $origin === '') return false;
        $parts = @parse_url($origin);
        if (!is_array($parts)) return false;
        $scheme = strtolower((string)($parts['scheme'] ?? ''));
        $host = strtolower((string)($parts['host'] ?? ''));
        $rp = strtolower((string)$expectedRpId);
        if ($host === '' || $host !== $rp) return false;
        if ($scheme === 'https') return true;
        if ($scheme === 'http' && in_array($host, array('localhost', '127.0.0.1'), true)) return true;
        return false;
    }
}

if (!function_exists('bc_webauthn_verify_attestation')) {
    /**
     * Verifies a WebAuthn registration response and extracts the PEM public key.
     * Returns ['ok' => true, 'credential_id' => b64u, 'public_key_pem' => pem] or ['ok' => false, 'error' => msg].
     */
    function bc_webauthn_verify_attestation($clientDataB64u, $attestationB64u, $expectedChallengeB64u, $expectedRpId) {
        $clientRaw = bc_webauthn_b64u_decode($clientDataB64u);
        $attRaw = bc_webauthn_b64u_decode($attestationB64u);
        if ($clientRaw === false || $attRaw === false) {
            return array('ok' => false, 'error' => 'Malformed registration payload.');
        }
        $client = json_decode($clientRaw, true);
        if (!is_array($client) || ($client['type'] ?? '') !== 'webauthn.create') {
            return array('ok' => false, 'error' => 'Invalid attestation type.');
        }
        if (!is_string($expectedChallengeB64u) || $expectedChallengeB64u === '' || !hash_equals($expectedChallengeB64u, (string)($client['challenge'] ?? ''))) {
            return array('ok' => false, 'error' => 'Registration challenge mismatch.');
        }
        if (!bc_webauthn_origin_matches((string)($client['origin'] ?? ''), $expectedRpId)) {
            return array('ok' => false, 'error' => 'Origin does not match this site.');
        }

        try {
            $offset = 0;
            $att = bc_cbor_decode($attRaw, $offset);
        } catch (Throwable $e) {
            return array('ok' => false, 'error' => 'Invalid attestation object.');
        }
        $authData = $att['authData'] ?? '';
        if (!is_string($authData) || strlen($authData) < 55) {
            return array('ok' => false, 'error' => 'Incomplete authenticator data.');
        }

        $rpIdHash = substr($authData, 0, 32);
        if (!hash_equals(hash('sha256', (string)$expectedRpId, true), $rpIdHash)) {
            return array('ok' => false, 'error' => 'RP ID verification failed.');
        }
        $flags = ord($authData[32]);
        if (($flags & 0x01) === 0 || ($flags & 0x40) === 0) {
            return array('ok' => false, 'error' => 'Attested credential data missing.');
        }

        $credLen = unpack('n', substr($authData, 53, 2))[1];
        if (55 + $credLen >= strlen($authData)) {
            return array('ok' => false, 'error' => 'Credential ID length invalid.');
        }
        $credIdBin = substr($authData, 55, $credLen);
        $coseOffset = 55 + $credLen;
        try {
            $coseKey = bc_cbor_decode($authData, $coseOffset);
        } catch (Throwable $e) {
            return array('ok' => false, 'error' => 'Could not parse credential public key.');
        }
        $pem = bc_webauthn_cose_to_pem($coseKey);
        if ($pem === '' || @openssl_pkey_get_public($pem) === false) {
            return array('ok' => false, 'error' => 'Unsupported credential key algorithm.');
        }

        return array(
            'ok' => true,
            'credential_id' => bc_webauthn_b64u_encode($credIdBin),
            'public_key_pem' => $pem,
        );
    }
}

if (!function_exists('bc_webauthn_verify_assertion')) {
    /**
     * Verifies a WebAuthn login assertion signature against a stored PEM SubjectPublicKeyInfo.
     * Returns ['ok' => true] or ['ok' => false, 'error' => msg].
     */
    function bc_webauthn_verify_assertion($clientDataB64u, $authDataB64u, $signatureB64u, $storedPem, $expectedChallengeB64u, $expectedRpId) {
        if (!function_exists('openssl_verify')) {
            return array('ok' => false, 'error' => 'OpenSSL verification is unavailable.');
        }
        $clientRaw = bc_webauthn_b64u_decode($clientDataB64u);
        $authDataRaw = bc_webauthn_b64u_decode($authDataB64u);
        $sigRaw = bc_webauthn_b64u_decode($signatureB64u);
        if ($clientRaw === false || $authDataRaw === false || $sigRaw === false || strlen($authDataRaw) < 37) {
            return array('ok' => false, 'error' => 'Malformed biometric assertion.');
        }
        if (!is_string($storedPem) || strpos($storedPem, '-----BEGIN PUBLIC KEY-----') === false) {
            return array('ok' => false, 'error' => 'Biometric credential must be re-registered.');
        }

        $client = json_decode($clientRaw, true);
        if (!is_array($client) || ($client['type'] ?? '') !== 'webauthn.get') {
            return array('ok' => false, 'error' => 'Invalid assertion type.');
        }
        if (!is_string($expectedChallengeB64u) || $expectedChallengeB64u === '' || !hash_equals($expectedChallengeB64u, (string)($client['challenge'] ?? ''))) {
            return array('ok' => false, 'error' => 'Security challenge mismatch. Please try again.');
        }
        if (!bc_webauthn_origin_matches((string)($client['origin'] ?? ''), $expectedRpId)) {
            return array('ok' => false, 'error' => 'Origin verification failed.');
        }

        $rpIdHash = substr($authDataRaw, 0, 32);
        if (!hash_equals(hash('sha256', (string)$expectedRpId, true), $rpIdHash)) {
            return array('ok' => false, 'error' => 'Site identity verification failed.');
        }
        $flags = ord($authDataRaw[32]);
        if (($flags & 0x01) === 0) {
            return array('ok' => false, 'error' => 'User presence was not confirmed.');
        }

        $pub = @openssl_pkey_get_public($storedPem);
        if ($pub === false) {
            return array('ok' => false, 'error' => 'Invalid stored public key.');
        }

        $signedData = $authDataRaw . hash('sha256', $clientRaw, true);
        $verified = @openssl_verify($signedData, $sigRaw, $pub, OPENSSL_ALGO_SHA256);
        if ($verified !== 1) {
            return array('ok' => false, 'error' => 'Biometric signature verification failed.');
        }

        return array('ok' => true);
    }
}
