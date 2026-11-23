<?php
/** Fixed Google endpoints; never include the admin page from a worker. */
    // ── Helper: generate a Google OAuth2 JWT for FCM v1 API ───────────────
    function _fcm_base64url($data) {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    function generateFcmAccessToken($service_account_json) {
        $sa = json_decode($service_account_json, true);
        if (empty($sa['client_email']) || empty($sa['private_key'])) {
            return null;
        }

        $now     = time();
        $header  = _fcm_base64url(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
        $payload = _fcm_base64url(json_encode([
            'iss'   => $sa['client_email'],
            'sub'   => $sa['client_email'],
            'aud'   => 'https://oauth2.googleapis.com/token',
            'iat'   => $now,
            'exp'   => $now + 3600,
            'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
        ]));

        $signing_input = "$header.$payload";
        $private_key   = openssl_pkey_get_private($sa['private_key']);
        if (!$private_key) return null;

        if (!openssl_sign($signing_input, $signature, $private_key, 'SHA256')) return null;
        $jwt = $signing_input . '.' . _fcm_base64url($signature);

        // Exchange JWT for access token
        $ch = curl_init('https://oauth2.googleapis.com/token');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query([
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion'  => $jwt,
            ]),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT        => 15,
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code !== 200) return null;

        $data = json_decode($resp, true);
        return $data['access_token'] ?? null;
    }

    function sendFcmTopicMessage($project_id, $access_token, $topic, $data_payload) {
        $body = json_encode([
            'message' => [
                'topic' => $topic,
                'data'  => $data_payload,
            ],
        ]);
        $ch = curl_init("https://fcm.googleapis.com/v1/projects/{$project_id}/messages:send");
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                "Authorization: Bearer $access_token",
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT        => 20,
        ]);
        $resp      = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return ['code' => $http_code, 'body' => json_decode($resp, true)];
    }

