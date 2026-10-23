<?php
/**
 * bc-whatsapp.php — DGV7 Official Edition
 * WhatsApp High-Alert Gateway (Official Meta Cloud API)
 */

if (function_exists('sendWhatsAppAlert')) return;

/**
 * Main Dispatcher: Sends WhatsApp messages via Official Meta Cloud API.
 */
function sendWhatsAppAlert(string $phone, string $message, string $priority = 'high', $vendor_id = 'default'): bool
{
    $provider = getSuperAdminOption('wa_provider', 'official');
    if ($provider === 'sendchamp') {
        return sendSendchampWhatsAppAlert($phone, $message);
    }
    return sendOfficialWhatsAppAlert($phone, $message);
}

/**
 * Sends message via Sendchamp WhatsApp API (Unofficial/Third-party)
 */
function sendSendchampWhatsAppAlert(string $phone, string $message): bool
{
    $key = getSuperAdminOption('wa_sendchamp_key', '');
    $sender = getSuperAdminOption('wa_sendchamp_sender', '');

    if (empty($key) || empty($sender)) return false;

    // Normalize phone number
    $phone = preg_replace('/[^0-9]/', '', $phone);
    if (strlen($phone) < 10) return false;
    if (strlen($phone) === 11 && $phone[0] === '0') {
        $phone = '234' . substr($phone, 1);
    }

    $url = "https://api.sendchamp.com/api/v1/whatsapp/message/send";
    $payload = [
        "sender" => $sender,
        "recipient" => $phone,
        "message" => $message,
        "type" => "text"
    ];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_HTTPHEADER => [
            "Authorization: Bearer $key",
            "Content-Type: application/json",
            "Accept: application/json"
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20
    ]);

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $res_data = json_decode($response, true);
    $success = ($http_code === 200 || $http_code === 201) && isset($res_data['status']) && $res_data['status'] === 'success';

    bc_log_security_event($success ? 'WHATSAPP_SENDCHAMP_SENT' : 'WHATSAPP_SENDCHAMP_FAILED', 'wa_sendchamp', $phone, "HTTP: $http_code | Resp: " . substr($response, 0, 100));

    return $success;
}

/**
 * Sends message via Official WhatsApp Cloud API
 */
function sendOfficialWhatsAppAlert(string $phone, string $message): bool
{
    $token = getSuperAdminOption('wa_official_token', '');
    $phone_id = getSuperAdminOption('wa_official_phone_id', '');
    
    if (empty($token) || empty($phone_id)) return false;

    // Normalize phone number to international format
    $phone = preg_replace('/[^0-9]/', '', $phone);
    if (strlen($phone) < 10) return false;

    // Nigerian normalization (080... -> 23480...)
    if (strlen($phone) === 11 && $phone[0] === '0') {
        $phone = '234' . substr($phone, 1);
    }

    $url = "https://graph.facebook.com/v17.0/$phone_id/messages";
    $payload = [
        "messaging_product" => "whatsapp",
        "recipient_type" => "individual",
        "to" => $phone,
        "type" => "text",
        "text" => ["body" => $message]
    ];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_HTTPHEADER => [
            "Authorization: Bearer $token",
            "Content-Type: application/json"
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15
    ]);
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $success = ($http_code === 200);
    
    bc_log_security_event($success ? 'WHATSAPP_OFFICIAL_SENT' : 'WHATSAPP_OFFICIAL_FAILED', 'wa_official', $phone, "HTTP: $http_code");

    return $success;
}

/**
 * Bulk sender for marketing/notifications
 */
function sendWhatsAppBulk(array $phones, string $message): array
{
    $results = ['sent' => 0, 'failed' => 0];
    foreach ($phones as $phone) {
        if (sendWhatsAppAlert($phone, $message)) {
            $results['sent']++;
        } else {
            $results['failed']++;
        }
        // Small delay to prevent API rate limit (Official supports 80/sec, so 0.1s is safe)
        usleep(100000); 
    }
    return $results;
}

/**
 * Online Check (Simply verifies if credentials exist)
 */
function isWhatsAppGatewayOnline(): bool
{
    $provider = getSuperAdminOption('wa_provider', 'official');
    if ($provider === 'sendchamp') {
        $key = getSuperAdminOption('wa_sendchamp_key', '');
        $sender = getSuperAdminOption('wa_sendchamp_sender', '');
        return (!empty($key) && !empty($sender));
    }
    $token = getSuperAdminOption('wa_official_token', '');
    $phone_id = getSuperAdminOption('wa_official_phone_id', '');
    return (!empty($token) && !empty($phone_id));
}

/**
 * Upload Media to Meta WhatsApp Official API
 * Returns Media ID on success, false on failure
 */
function uploadMediaToMetaWhatsApp($localFilePath) {
    $token = getSuperAdminOption('wa_official_token', '');
    $phone_id = getSuperAdminOption('wa_official_phone_id', '');
    
    if (empty($token) || empty($phone_id) || !file_exists($localFilePath)) return false;

    $url = "https://graph.facebook.com/v18.0/$phone_id/media";
    $mimeType = mime_content_type($localFilePath);
    
    $cfile = new CURLFile($localFilePath, $mimeType, basename($localFilePath));
    $payload = [
        "messaging_product" => "whatsapp",
        "file" => $cfile
    ];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => [
            "Authorization: Bearer $token",
            "Content-Type: multipart/form-data"
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30
    ]);
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $res_data = json_decode($response, true);
    if ($http_code === 200 && isset($res_data['id'])) {
        return $res_data['id'];
    }
    return false;
}

/**
 * Create Official WhatsApp Template via Meta API
 */
function createOfficialWhatsAppTemplate($name, $category, $bodyText, $language = 'en_US', $headerMediaId = null) {
    $token = getSuperAdminOption('wa_official_token', '');
    $biz_id = getSuperAdminOption('wa_official_biz_id', '');
    
    if (empty($token) || empty($biz_id)) return ["status" => false, "message" => "Meta API credentials not configured."];

    $url = "https://graph.facebook.com/v18.0/$biz_id/message_templates";
    $components = [
        [
            "type" => "BODY",
            "text" => $bodyText
        ]
    ];

    if (!empty($headerMediaId)) {
        array_unshift($components, [
            "type" => "HEADER",
            "format" => "IMAGE",
            "example" => [
                "header_handle" => [ $headerMediaId ]
            ]
        ]);
    }

    $payload = [
        "name" => $name,
        "language" => $language,
        "category" => $category,
        "components" => $components
    ];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_HTTPHEADER => [
            "Authorization: Bearer $token",
            "Content-Type: application/json"
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15
    ]);
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $res_data = json_decode($response, true);
    
    if ($http_code === 200 && isset($res_data['id'])) {
        return ["status" => true, "id" => $res_data['id']];
    }
    
    return ["status" => false, "message" => $res_data['error']['message'] ?? "Unknown Meta API Error"];
}

/**
 * Check Template Status
 */
function checkTemplateStatus($name) {
    $token = getSuperAdminOption('wa_official_token', '');
    $biz_id = getSuperAdminOption('wa_official_biz_id', '');
    
    if (empty($token) || empty($biz_id)) return false;

    $url = "https://graph.facebook.com/v18.0/$biz_id/message_templates?name=" . urlencode($name);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_HTTPGET => true,
        CURLOPT_HTTPHEADER => [
            "Authorization: Bearer $token"
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10
    ]);
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $res_data = json_decode($response, true);
    
    if ($http_code === 200 && isset($res_data['data'][0]['status'])) {
        return $res_data['data'][0]['status']; // e.g. APPROVED, REJECTED, PENDING
    }
    return false;
}

/**
 * Sends Template Message via Official WhatsApp Cloud API
 */
function sendWhatsAppTemplate(string $phone, string $template_name, string $language = 'en_US', $headerMediaUrl = null): bool
{
    $provider = getSuperAdminOption('wa_provider', 'official');
    if ($provider === 'sendchamp') {
        // Sendchamp template logic (optional enhancement)
        // For now, unofficial APIs often use simple text messages or their own template system.
        // We'll fallback to a simple alert if it's sendchamp for templates for now, or just return false.
        return false;
    }

    $token = getSuperAdminOption('wa_official_token', '');
    $phone_id = getSuperAdminOption('wa_official_phone_id', '');
    
    if (empty($token) || empty($phone_id)) return false;

    // Normalize phone number
    $phone = preg_replace('/[^0-9]/', '', $phone);
    if (strlen($phone) < 10) return false;

    if (strlen($phone) === 11 && $phone[0] === '0') {
        $phone = '234' . substr($phone, 1);
    }

    $url = "https://graph.facebook.com/v18.0/$phone_id/messages";
    $templateComponent = [
        "name" => $template_name,
        "language" => ["code" => $language]
    ];

    if (!empty($headerMediaUrl)) {
        $templateComponent['components'] = [
            [
                "type" => "header",
                "parameters" => [
                    [
                        "type" => "image",
                        "image" => [ "link" => $headerMediaUrl ]
                    ]
                ]
            ]
        ];
    }

    $payload = [
        "messaging_product" => "whatsapp",
        "recipient_type" => "individual",
        "to" => $phone,
        "type" => "template",
        "template" => $templateComponent
    ];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_HTTPHEADER => [
            "Authorization: Bearer $token",
            "Content-Type: application/json"
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15
    ]);
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return ($http_code === 200);
}
