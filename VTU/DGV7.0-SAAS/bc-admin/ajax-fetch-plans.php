<?php
header('Content-Type: application/json');
session_start();
include("../func/bc-admin-config.php");

if (!isset($_SESSION["admin_session"])) {
    echo json_encode(["success" => false, "message" => "Unauthorized"]);
    exit();
}

$gateway = $_GET['gateway'] ?? '';
$network = $_GET['network'] ?? '';
$type = $_GET['type'] ?? ''; // dd, sme, shared, cg

if (!$gateway || !$network) {
    echo json_encode(["success" => false, "message" => "Missing parameters"]);
    exit();
}

$plans = [];

function extractDays($name) {
    if (preg_match('/(\d+)\s*(?:Day|Day\(s\)|Days)/i', $name, $matches)) {
        return (int)$matches[1];
    }
    if (preg_match('/(\d+)\s*(?:Month|Months)/i', $name, $matches)) {
        return (int)$matches[1] * 30;
    }
    if (preg_match('/Weekly/i', $name)) {
        return 7;
    }
    if (preg_match('/Monthly/i', $name)) {
        return 30;
    }
    return 30; // Default
}

try {
    $gateway_host = strtolower(trim((string)$gateway));
    $gateway_host = preg_replace('#^https?://#i', '', $gateway_host);
    $gateway_host = preg_replace('#^www\.#i', '', $gateway_host);
    $gateway_host = rtrim($gateway_host, '/');

    if ($gateway_host === 'smeplug.ng' && $type === 'airtime') {
        // SME Plug accepts arbitrary airtime amounts, but the documented API does not expose
        // network discount/commission rates. Avoid inventing a rate; configure Airtime manually.
        throw new Error('SME Plug does not expose Airtime discount rates through its API. Configure the Airtime commission manually; data plan prices can be fetched here.');
    } elseif ($gateway_host === 'smeplug.ng' && in_array($type, ['sme', 'cg', 'dd'], true)) {
        $vendor_id = (int)($get_logged_admin_details['id'] ?? 0);
        $api_type = ['sme' => 'sme-data', 'cg' => 'cg-data', 'dd' => 'dd-data'][$type];
        $api_type_esc = mysqli_real_escape_string($connection_server, $api_type);
        $api_query = mysqli_query(
            $connection_server,
            "SELECT api_key FROM sas_apis WHERE vendor_id='$vendor_id' AND api_type='$api_type_esc' AND api_base_url LIKE '%smeplug.ng%' AND status='1' LIMIT 1"
        );
        $api_row = $api_query ? mysqli_fetch_assoc($api_query) : null;
        $smeplug_api_key = trim((string)($api_row['api_key'] ?? ''));
        if ($smeplug_api_key === '') {
            throw new Error("SME Plug API key is not configured for " . strtoupper($api_type) . ".");
        }

        $smeplug_get_json = function($path) use ($smeplug_api_key) {
            $ch = curl_init('https://smeplug.ng/api/v1' . $path);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPGET => true,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_HTTPHEADER => [
                    'Authorization: Bearer ' . $smeplug_api_key,
                    'Accept: application/json'
                ]
            ]);
            $body = curl_exec($ch);
            $errno = curl_errno($ch);
            $http_code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);
            $json = json_decode((string)$body, true);
            if ($errno !== 0 || $http_code < 200 || $http_code >= 300 || !is_array($json)) {
                throw new Error('Unable to retrieve the SME Plug plan catalogue' . ($error !== '' ? ': ' . $error : '.'));
            }
            $provider_status = $json['status'] ?? false;
            $provider_ok = in_array($provider_status, [true, 1, '1', 'true', 'success', 'successful'], true);
            if (!$provider_ok) {
                $message = $json['message'] ?? $json['msg'] ?? 'Provider rejected the request.';
                throw new Error('SME Plug catalogue request failed: ' . (is_scalar($message) ? (string)$message : 'unknown provider error'));
            }
            return $json;
        };

        $networks_payload = $smeplug_get_json('/networks');
        $plans_payload = $smeplug_get_json('/data/plans');
        $provider_networks = $networks_payload['networks'] ?? null;
        $provider_plans = $plans_payload['data'] ?? null;
        if (!is_array($provider_networks) || !is_array($provider_plans)) {
            throw new Error('SME Plug returned an invalid networks or data-plan response.');
        }

        $network_aliases = [
            'mtn' => 'mtn', 'airtel' => 'airtel', '9mobile' => '9mobile',
            'etisalat' => '9mobile', 't2' => '9mobile', 'glo' => 'glo', 'globacom' => 'glo'
        ];
        $target_network = strtolower(trim((string)$network));
        $target_network = $network_aliases[$target_network] ?? $target_network;
        $network_id = null;
        foreach ($provider_networks as $id => $provider_name) {
            if (!is_scalar($provider_name)) continue;
            $normalized_name = strtolower(trim((string)$provider_name));
            $normalized_name = $network_aliases[$normalized_name] ?? $normalized_name;
            if ($normalized_name === $target_network) {
                $network_id = (string)$id;
                break;
            }
        }
        if ($network_id === null || !isset($provider_plans[$network_id]) || !is_array($provider_plans[$network_id])) {
            throw new Error('SME Plug has no data-plan catalogue for ' . strtoupper($target_network) . '.');
        }

        $seen_plan_codes = [];
        $valid_catalogue_plan_count = 0;
        foreach ($provider_plans[$network_id] as $provider_plan) {
            if (!is_array($provider_plan)) continue;
            $plan_id = trim((string)($provider_plan['id'] ?? $provider_plan['plan_id'] ?? $provider_plan['code'] ?? ''));
            $plan_name = trim((string)($provider_plan['name'] ?? $provider_plan['plan_name'] ?? $provider_plan['title'] ?? ''));
            $plan_price = $provider_plan['price'] ?? $provider_plan['amount'] ?? $provider_plan['selling_price'] ?? null;
            if ($plan_id === '' || $plan_name === '' || !is_numeric($plan_price) || (float)$plan_price <= 0) continue;
            $valid_catalogue_plan_count++;

            // SME Plug's documented catalogue labels SME/Gifting in plan names, while some
            // account/catalogue variants may expose the category in a separate field.
            $classification_text = $plan_name;
            foreach (['category', 'plan_type', 'plan_category', 'type', 'product_type', 'service_type'] as $category_field) {
                if (isset($provider_plan[$category_field]) && is_scalar($provider_plan[$category_field])) {
                    $classification_text .= ' ' . (string)$provider_plan[$category_field];
                }
            }
            // Preserve DGV's plan taxonomy: SME labels route to SME Data, Gifting/Corporate/CG
            // labels to CG Data, and other plans to Direct Data.
            $is_sme_plan = stripos($classification_text, 'sme') !== false;
            $is_cg_plan = preg_match('/gifting|corporate|(^|[^a-z])cg([^a-z]|$)/i', $classification_text) === 1;
            if (($type === 'sme' && !$is_sme_plan)
                || ($type === 'cg' && !$is_cg_plan)
                || ($type === 'dd' && ($is_sme_plan || $is_cg_plan))) continue;

            // DGV normalizes plan codes to lowercase during purchase. Convert back to uppercase
            // in the gateway adapter so alpha-numeric SME Plug ids such as AIR1000 are preserved.
            $plan_code = strtolower($plan_id);
            if (isset($seen_plan_codes[$plan_code])) continue;
            $seen_plan_codes[$plan_code] = true;
            $plans[] = [
                'name' => $plan_name,
                'code' => $plan_code,
                'price' => (float)$plan_price,
                'days' => extractDays($plan_name)
            ];
        }

        if (empty($plans)) {
            $catalogue_count = count($provider_plans[$network_id]);
            throw new Error(
                'SME Plug returned ' . $catalogue_count . ' ' . strtoupper($target_network) . ' plan records (' . $valid_catalogue_plan_count . ' with valid IDs, names and prices), but none matched the requested ' . strtoupper($type) . ' category. The provider must expose the category in the plan name or category/type field.'
            );
        }
    } elseif ($gateway_host === 'smeplug.ng') {
        throw new Error('SME Plug plan fetching supports Airtime, SME Data, CG/Gifting Data, and Direct Data. Shared Data is not offered by this catalogue.');
    } elseif ($gateway === 'vtpass') {
        if ($type === 'airtime') {
            $plans[] = [
                'name' => strtoupper($network) . " Airtime",
                'code' => 'airtime',
                'price' => 100,
                'days' => 0
            ];
        } elseif ($type === 'cable') {
            $service_id = strtolower(trim($network));
            $url = "https://vtpass.com/api/service-variations?serviceID=" . rawurlencode($service_id);

            // VTPASS requires the same Basic Authentication used by the
            // purchase gateway. The old fetcher called this endpoint without
            // the configured merchant credentials.
            $vendor_id = (int)($get_logged_admin_details['id'] ?? 0);
            $vtpass_api_key = '';
            if ($vendor_id > 0) {
                $api_query = mysqli_query(
                    $connection_server,
                    "SELECT api_key FROM sas_apis WHERE vendor_id='$vendor_id' AND api_type='cable' AND api_base_url LIKE '%vtpass.com%' LIMIT 1"
                );
                if ($api_row = mysqli_fetch_assoc($api_query)) {
                    $vtpass_api_key = trim((string)$api_row['api_key']);
                }
            }

            if ($vtpass_api_key === '') {
                throw new Error("VTPASS API credentials are not configured for cable services.");
            }

            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                "Authorization: Basic " . base64_encode($vtpass_api_key),
                "Accept: application/json"
            ]);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);
            curl_setopt($ch, CURLOPT_USERAGENT, "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36");
            $response = curl_exec($ch);
            $curl_error = curl_error($ch);
            curl_close($ch);

            if ($response === false || $curl_error !== '') {
                throw new Error("Unable to connect to VTPASS: " . ($curl_error ?: "empty response"));
            }

            $data = json_decode($response, true);

            // VTPASS documents Showmax with both "variations" and the
            // misspelled legacy key "varations". Accept either response key.
            $variation_rows = $data['content']['variations'] ?? $data['content']['varations'] ?? null;

            if (is_array($variation_rows)) {
                foreach ($variation_rows as $v) {
                    if (!is_array($v) || !isset($v['name'], $v['variation_code'], $v['variation_amount'])) {
                        continue;
                    }

                    $plans[] = [
                        'name' => $v['name'],
                        'code' => $v['variation_code'],
                        'price' => $v['variation_amount'],
                        'days' => 30 // Cable usually 30 days
                    ];
                }
            }

            if (empty($plans)) {
                $vtpass_error = $data['content']['errors'] ?? $data['response_description'] ?? null;
                throw new Error($vtpass_error
                    ? "VTPASS Showmax response: " . $vtpass_error
                    : "Invalid response from VTPASS");
            }
        } else {
            $serviceID = ($network === '9mobile') ? 'etisalat-data' : $network . '-data';
            $url = "https://vtpass.com/api/service-variations?serviceID=" . $serviceID;

            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_USERAGENT, "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36");
            $response = curl_exec($ch);
            curl_close($ch);

            $data = json_decode($response, true);

            if (isset($data['content']['variations'])) {
                foreach ($data['content']['variations'] as $v) {
                    $name = $v['name'];
                    $include = true;
                    if ($type === 'sme') {
                        if (stripos($name, 'SME') === false) $include = false;
                    } elseif ($type === 'dd') {
                        if (stripos($name, 'SME') !== false) $include = false;
                        if (stripos($name, 'Gifting') !== false) $include = false;
                        if (stripos($name, 'Corporate') !== false) $include = false;
                    } elseif ($type === 'cg') {
                        if (stripos($name, 'Corporate') === false && stripos($name, 'Gifting') === false) $include = false;
                    }

                    if ($include) {
                        $plans[] = [
                            'name' => $v['name'],
                            'code' => $v['variation_code'],
                            'price' => $v['variation_amount'],
                            'days' => extractDays($v['name'])
                        ];
                    }
                }
            } else {
                throw new Error("Invalid response from VTPASS");
            }
        }
    } elseif ($gateway === 'clubkonnect') {
        $network_map = ['mtn' => 'MTN', 'airtel' => 'Airtel', 'glo' => 'Glo', '9mobile' => 'm_9mobile'];
        $target_network = $network_map[$network] ?? '';

        if ($type === 'airtime') {
            $plans[] = [
                'name' => strtoupper($network) . " Airtime",
                'code' => 'airtime',
                'price' => 100,
                'days' => 0
            ];
        } elseif ($type === 'cable') {
            // ClubKonnect uses static plan codes for Cable TV (no pricing API endpoint)
            // Plans returned with price=0; admin sets prices manually
            $ck_cable_plans = [
                'dstv' => [
                    ['name' => 'DSTV Padi', 'code' => 'padi', 'days' => 30],
                    ['name' => 'DSTV Yanga', 'code' => 'yanga', 'days' => 30],
                    ['name' => 'DSTV Confam', 'code' => 'confam', 'days' => 30],
                    ['name' => 'DSTV Compact', 'code' => 'compact', 'days' => 30],
                    ['name' => 'DSTV Premium', 'code' => 'premium', 'days' => 30],
                    ['name' => 'DSTV Padi Extra View', 'code' => 'padi_extraview', 'days' => 30],
                    ['name' => 'DSTV Yanga Extra View', 'code' => 'yanga_extraview', 'days' => 30],
                    ['name' => 'DSTV Confam Extra View', 'code' => 'confam_extraview', 'days' => 30],
                    ['name' => 'DSTV Compact Extra View', 'code' => 'compact_extra_view', 'days' => 30],
                    ['name' => 'DSTV Compact Plus', 'code' => 'compact_plus', 'days' => 30],
                    ['name' => 'DSTV Compact Plus Extra View', 'code' => 'compact_plus_extra_view', 'days' => 30],
                    ['name' => 'DSTV Compact Plus French Plus Extra View', 'code' => 'compact_plus_frenchplus_extra_view', 'days' => 30],
                    ['name' => 'DSTV Premium Extra View', 'code' => 'premium_extra_view', 'days' => 30],
                    ['name' => 'DSTV Premium French Extra View', 'code' => 'premium_french_extra_view', 'days' => 30],
                ],
                'gotv' => [
                    ['name' => 'GOTV Smallie', 'code' => 'smallie', 'days' => 30],
                    ['name' => 'GOTV Jinja', 'code' => 'jinja', 'days' => 30],
                    ['name' => 'GOTV Jolli', 'code' => 'jolli', 'days' => 30],
                    ['name' => 'GOTV Max', 'code' => 'max', 'days' => 30],
                    ['name' => 'GOTV Super', 'code' => 'super', 'days' => 30],
                ],
                'startimes' => [
                    ['name' => 'Startimes Nova Weekly', 'code' => 'nova_weekly', 'days' => 7],
                    ['name' => 'Startimes Basic Weekly', 'code' => 'basic_weekly', 'days' => 7],
                    ['name' => 'Startimes Smart Weekly', 'code' => 'smart_weekly', 'days' => 7],
                    ['name' => 'Startimes Classic Weekly', 'code' => 'classic_weekly', 'days' => 7],
                    ['name' => 'Startimes Super Weekly', 'code' => 'super_weekly', 'days' => 7],
                    ['name' => 'Startimes Nova', 'code' => 'nova', 'days' => 30],
                    ['name' => 'Startimes Basic', 'code' => 'basic', 'days' => 30],
                    ['name' => 'Startimes Smart', 'code' => 'smart', 'days' => 30],
                    ['name' => 'Startimes Classic', 'code' => 'classic', 'days' => 30],
                    ['name' => 'Startimes Super', 'code' => 'super', 'days' => 30],
                    ['name' => 'Startimes Chinese Dish', 'code' => 'chinese_dish', 'days' => 30],
                    ['name' => 'Startimes Nova Antenna', 'code' => 'nova_antenna', 'days' => 30],
                ],
                'showmax' => [], // SHOWMAX not supported by ClubKonnect
            ];

            $provider_plans = $ck_cable_plans[$network] ?? null;
            if ($provider_plans === null) {
                throw new Exception("Provider '$network' not supported for ClubKonnect Cable TV.");
            }
            if (empty($provider_plans)) {
                throw new Exception("SHOWMAX is not available via ClubKonnect. Please use VTPASS for SHOWMAX plans.");
            }
            foreach ($provider_plans as $p) {
                $plans[] = [
                    'name' => $p['name'],
                    'code' => $p['code'],
                    'price' => 0, // ClubKonnect has no public pricing API; admin must set prices
                    'days' => $p['days']
                ];
            }
        } else {
            $ck_user_id = 'CK101252094'; // Default UserID provided by developer for fetching
            $vid = $_SESSION['admin_id'] ?? $get_logged_admin_details['id'];
            $ck_api_q = mysqli_query($connection_server, "SELECT api_key FROM sas_apis WHERE vendor_id='$vid' AND (api_base_url LIKE '%clubkonnect.com%' OR api_base_url LIKE '%nellobytesystems.com%') LIMIT 1");
            if($ck_api_row = mysqli_fetch_assoc($ck_api_q)){
                $parts = explode(":", trim($ck_api_row['api_key']));
                if(count($parts) >= 2 && !empty(trim($parts[0]))) $ck_user_id = trim($parts[0]);
            }

            $url = "https://www.nellobytesystems.com/APIDatabundlePlansV2.asp?UserID=" . $ck_user_id;

            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_USERAGENT, "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36");
            curl_setopt($ch, CURLOPT_TIMEOUT, 45);
            $response = curl_exec($ch);
            curl_close($ch);

            $data = json_decode(trim($response), true);

            if ($data && isset($data['MOBILE_NETWORK'])) {
                $mn = $data['MOBILE_NETWORK'];
                $network_data = $mn[$target_network] ?? $mn[strtoupper($network)] ?? $mn[ucfirst($network)] ?? null;

                // Fallback for 9mobile if m_9mobile fails
                if (!$network_data && $network === '9mobile') {
                    $network_data = $mn['9mobile'] ?? $mn['ETISALAT'] ?? null;
                }

                if ($network_data && is_array($network_data)) {
                    // The structure is an array of objects, each containing a PRODUCT array
                    foreach ($network_data as $provider_group) {
                        if (isset($provider_group['PRODUCT']) && is_array($provider_group['PRODUCT'])) {
                            foreach ($provider_group['PRODUCT'] as $p) {
                                $name = $p['PRODUCT_NAME'] ?? '';
                                $code = $p['PRODUCT_ID'] ?? ''; // Using PRODUCT_ID as code for buy API
                                $price = $p['PRODUCT_AMOUNT'] ?? 0;

                                if (!$name || !$code) continue;

                                $include = true;
                                if ($type === 'sme') {
                                    if (stripos($name, 'SME') === false) $include = false;
                                } elseif ($type === 'dd') {
                                    // Direct Data should include regular plans, Awoof, Gifting (not corporate), but not SME/CG
                                    if (stripos($name, 'SME') !== false) $include = false;
                                    if (stripos($name, 'CG') !== false) $include = false;
                                    if (stripos($name, 'Corporate') !== false) $include = false;

                                    // Always include these if found
                                    if (stripos($name, 'Awoof') !== false || stripos($name, 'Direct') !== false) $include = true;
                                } elseif ($type === 'cg') {
                                    if (stripos($name, 'CG') === false && stripos($name, 'Corporate') === false) $include = false;
                                }

                                if ($include) {
                                    $plans[] = [
                                        'name' => $name,
                                        'code' => $code,
                                        'price' => $price,
                                        'days' => extractDays($name)
                                    ];
                                }
                            }
                        }
                    }
                } else {
                    throw new Error("Network " . ($target_network ?: $network) . " data not found in provider response.");
                }
            } else {
                throw new Error("Invalid response format from ClubKonnect V2 API");
            }
        }
    } elseif ($gateway === 'naijaresultpins' && $type === 'exam') {
        // Live fetch of available exam card types from NaijaResultPins API
        // GET https://www.naijaresultpins.com/api/v1 returns card types list
        $vid = $get_logged_admin_details['id'];
        $api_q = mysqli_query($connection_server, "SELECT * FROM sas_apis WHERE vendor_id='$vid' AND api_base_url LIKE '%naijaresultpins%' AND api_type='exam' LIMIT 1");
        $api_row = mysqli_fetch_assoc($api_q);

        if (!$api_row || empty($api_row['api_key'])) {
            throw new Error("NaijaResultPins API key not configured. Please set it up in Exam API settings.");
        }

        $api_key = $api_row['api_key'];
        // Normalize the base URL - strip protocol/www to build a consistent URL
        $base_url = $api_row['api_base_url'];
        $base_url = preg_replace('#^https?://#', '', $base_url);
        $base_url = ltrim($base_url, '/');
        // If the stored URL doesn't include www, add it (NaijaResultPins requires www subdomain)
        if (!preg_match('#^www\.#i', $base_url)) {
            $base_url = 'www.' . $base_url;
        }
        $ch = curl_init("https://" . $base_url . "/api/v1");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPGET        => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_HTTPHEADER     => ["Authorization: Bearer $api_key", "Content-Type: application/json"],
        ]);
        $response = curl_exec($ch);
        curl_close($ch);

        $data = json_decode($response, true);

        // NaijaResultPins' real response fields (confirmed live) are card_type_id, card_name,
        // unit_amount, availability — NOT id/name/price/status. The previous field names here never
        // matched anything the API actually returns, so every row was silently skipped and this
        // always fell through to the hardcoded fallback below regardless of what the live account offered.
        $unsupported_products = [];

        if (is_array($data)) {
            foreach ($data as $card_type) {
                $id     = $card_type['card_type_id'] ?? '';
                $name   = $card_type['card_name'] ?? '';
                $price  = $card_type['unit_amount'] ?? 0;
                $availability = $card_type['availability'] ?? '';

                if ($id === '' || $name === '') continue;
                if (stripos($availability, 'out') !== false) continue; // e.g. "Out of Stock"

                // Derive exam type and sub-type from name
                $name_lower = strtolower($name);
                $exam_type = '';
                $sub_type = 'result_checker';

                if (strpos($name_lower, 'waec') !== false) {
                    $exam_type = 'waec';
                } elseif (strpos($name_lower, 'neco') !== false) {
                    $exam_type = 'neco';
                } elseif (strpos($name_lower, 'nabteb') !== false) {
                    $exam_type = 'nabteb';
                } elseif (strpos($name_lower, 'jamb') !== false) {
                    $exam_type = 'jamb';
                    if (strpos($name_lower, 'direct entry') !== false || strpos($name_lower, 'de') !== false) {
                        $sub_type = 'direct_entry';
                    } elseif (strpos($name_lower, 'with mock') !== false) {
                        $sub_type = 'utme_with_mock';
                    } elseif (strpos($name_lower, 'without mock') !== false || strpos($name_lower, 'utme') !== false) {
                        $sub_type = 'utme_without_mock';
                    } else {
                        continue; // Unrecognized JAMB variant — don't guess a sub_type for it.
                    }
                }

                if (empty($exam_type)) {
                    // Board not modeled by this app yet (e.g. NBAIS, EXAMINIFY) — surfaced to the
                    // admin below rather than silently dropped.
                    $unsupported_products[] = "$name (card_type_id: $id)";
                    continue;
                }

                // NaijaResultPins offers more than one card per board (e.g. WAEC Scratch Card vs
                // WAEC Verification Pin) — distinguish them instead of collapsing both into
                // "result_checker", which would silently overwrite one price with the other.
                if ($exam_type !== 'jamb' && strpos($name_lower, 'verif') !== false) {
                    $sub_type = 'verification_pin';
                }

                $plans[] = [
                    'name'  => $name . " (card_type_id: $id)",
                    'code'  => $sub_type,
                    'price' => $price,
                    'days'  => 0,
                    'exam_type' => $exam_type,
                ];
            }
        }

        // Surfaced as a separate top-level field below (not injected into $plans) since $plans is
        // sent verbatim to ajax-save-plans.php by "Apply All" — a fake entry here would get saved
        // as a spurious empty-code pricing row.
        if (!empty($unsupported_products)) {
            $fetch_note = 'Not added — no matching product in this app yet: ' . implode(', ', $unsupported_products);
        }

        if (empty($plans)) {
            // Fallback: return hardcoded known types as guidance
            $plans = [
                ['name' => 'WAEC Scratch Card (card_type_id: 1)', 'code' => 'result_checker', 'price' => 0, 'days' => 0, 'exam_type' => 'waec'],
                ['name' => 'NECO TOKEN (card_type_id: 2)',         'code' => 'result_checker', 'price' => 0, 'days' => 0, 'exam_type' => 'neco'],
                ['name' => 'NABTEB Scratch Card (card_type_id: 3)','code' => 'result_checker', 'price' => 0, 'days' => 0, 'exam_type' => 'nabteb'],
                ['name' => 'JAMB UTME Without Mock (card_type_id: 8)', 'code' => 'utme_without_mock', 'price' => 0, 'days' => 0, 'exam_type' => 'jamb'],
                ['name' => 'JAMB UTME With Mock (card_type_id: 9)',    'code' => 'utme_with_mock',    'price' => 0, 'days' => 0, 'exam_type' => 'jamb'],
                ['name' => 'JAMB Direct Entry (card_type_id: 10)',     'code' => 'direct_entry',      'price' => 0, 'days' => 0, 'exam_type' => 'jamb'],
            ];
        }
    } else {
        // For data/cable/exam/bulk-sms services, prefer the plan codes actually embedded in this
        // vendor's own func/api-gateway/{prefix}-{provider}.php gateway file — that's the file real
        // purchases check against, so it can never drift from what will actually work at checkout
        // time (unlike a hand-maintained catalog). Checked before the reseller/"local vendor"
        // fallback below, since that path is for a different case (reselling through another SAAS
        // vendor on this platform, not a genuine third-party API provider like hdkdata/benzoni/etc.).
        // 'bulk-sms' maps to the 'sms' file prefix because that's what web/func/sms.php itself
        // resolves against (func/api-gateway/sms-*.php, not bulk-sms-*.php) — a pre-existing naming
        // quirk in the app, not something introduced here.
        $gateway_file_type_prefix = ['sme' => 'sme-data', 'cg' => 'cg-data', 'dd' => 'dd-data', 'shared' => 'shared-data', 'cable' => 'cable', 'exam' => 'exam', 'bulk-sms' => 'sms'];
        $used_gateway_file = false;

        if (isset($gateway_file_type_prefix[$type])) {
            require_once __DIR__ . "/../func/bc-gateway-plan-parser.php";
            $gateway_file_path = bc_gateway_resolve_file($gateway_file_type_prefix[$type], $gateway);
            $embedded_codes = $gateway_file_path ? bc_gateway_parse_plan_codes($gateway_file_path, $network) : false;

            if ($embedded_codes !== false && !empty($embedded_codes)) {
                foreach ($embedded_codes as $plan_code => $internal_id) {
                    $plans[] = [
                        'name' => strtoupper(str_replace(['_', '-'], ' ', $plan_code)),
                        'code' => $plan_code,
                        'price' => 0, // No public pricing source for these — admin sets prices manually
                        'days' => extractDays($plan_code)
                    ];
                }
                $used_gateway_file = true;
            }
        }

        // ── Another DGV7 install, possibly on another server ────────────────────────────────────
        // This is the case the fetcher could not handle: the provider has no gateway file of its
        // own, so bc_gateway_resolve_file() falls back to <type>-localserver.php, and the plan list
        // read below out of THIS install's own tables can only ever describe a vendor sharing this
        // database. A seller hosted elsewhere therefore had no reachable code path at all.
        // Only worth calling when a key is stored for this provider: that key is the SELLER's own
        // sas_users api_key, and the seller must have approved API access for it.
        $vid = $get_logged_admin_details['id'] ?? 1;
        $remote_error = null;
        $remote_api_key = '';
        if (bc_remote_vendor_is_candidate($gateway)) {
            $remote_host = bc_remote_vendor_normalize_host($gateway);
            $remote_host_esc = mysqli_real_escape_string($connection_server, $remote_host);
            $remote_url_esc = mysqli_real_escape_string($connection_server, $gateway);
            $key_q = mysqli_query($connection_server, "SELECT api_key FROM sas_apis WHERE vendor_id='$vid' AND (api_base_url='$remote_url_esc' OR api_base_url LIKE '%$remote_host_esc%') AND api_key IS NOT NULL AND api_key<>'' LIMIT 1");
            if ($key_q && ($key_row = mysqli_fetch_assoc($key_q))) {
                $remote_api_key = trim($key_row['api_key']);
            }
        }

        if ($remote_api_key !== '') {
            $fetch_base = strtolower(rtrim($gateway, '/'));
            if (!preg_match('#^https?://#i', $fetch_base)) {
                $fetch_base = 'https://' . $fetch_base;
            }
            // The key is sent BOTH as a Bearer header and as a query parameter. Many cPanel/LiteSpeed
            // hosts do not expose the Authorization header to PHP, and cURL drops custom headers when
            // a redirect crosses to another host - either one surfaces at the SELLER as "Missing API
            // Key" even though the key is valid. The query parameter survives both.
            $fetch_url = $fetch_base . "/api/app-backend/fetch-dgv7-plans.php?network=" . urlencode($network) . "&type=" . urlencode($type) . "&api_key=" . urlencode($remote_api_key);

            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $fetch_url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer $remote_api_key", "Content-Type: application/json"]);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);
            $remote_response = curl_exec($ch);
            $remote_http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $remote_curl_err  = curl_error($ch);
            curl_close($ch);

            // Parse the seller's answer tolerantly: a host whose php.ini displays errors can prepend
            // HTML to otherwise valid JSON, and a strict decode reports that as "invalid format from
            // the provider" - blaming the seller for the buyer's parser.
            $remote_data = function_exists('bc_gateway_json_decode')
                ? bc_gateway_json_decode($remote_response)
                : json_decode((string)$remote_response, true);

            if ($remote_http_code === 200 && is_array($remote_data) && isset($remote_data['success'])
                && $remote_data['success'] === true && isset($remote_data['plans']) && is_array($remote_data['plans'])) {
                $plans = $remote_data['plans'];
                $used_gateway_file = true;
            } elseif (is_array($remote_data) && !empty($remote_data['message'])) {
                $remote_error = "DGV7 provider $gateway refused the request: " . $remote_data['message'];
            } else {
                $remote_error = "Could not read the plan list from the DGV7 provider $gateway (HTTP $remote_http_code)"
                    . ($remote_curl_err ? ": " . $remote_curl_err : "") . ".";
            }
        }

        if (!$used_gateway_file) {
            // Check if it's a local vendor
            $gateway_esc = mysqli_real_escape_string($connection_server, $gateway);
            $check_local = mysqli_query($connection_server, "SELECT id FROM sas_vendors WHERE website_url='$gateway_esc'");
            if (mysqli_num_rows($check_local) > 0) {
                $vendor_a_id = mysqli_fetch_assoc($check_local)['id'];

                // Map type to api_type
                $type_map = [
                    'dd' => 'dd-data',
                    'sme' => 'sme-data',
                    'shared' => 'shared-data',
                    'cg' => 'cg-data',
                    'airtime' => 'airtime',
                    'cable' => 'cable',
                    'electric' => 'electric',
                    'exam' => 'exam',
                    'betting' => 'betting'
                ];
                $api_type = $type_map[$type] ?? $type;

                // Determine which value column to use
                // val_2 is for price (Data/Cable), val_1 is for discount/commission (Airtime/Electric)
                $val_col = in_array($api_type, ['airtime', 'electric', 'betting']) ? 'val_1' : 'val_2';

                // Query plans from Vendor A
                $prod_q = mysqli_query($connection_server, "SELECT id FROM sas_products WHERE vendor_id='$vendor_a_id' AND product_name='$network' LIMIT 1");
                if (mysqli_num_rows($prod_q) > 0) {
                    $vendor_a_product_id = mysqli_fetch_assoc($prod_q)['id'];

                    // Fetch all matching API IDs for this vendor and type
                    $api_ids_q = mysqli_query($connection_server, "SELECT id FROM sas_apis WHERE vendor_id='$vendor_a_id' AND api_type='$api_type'");
                    $api_ids = [];
                    while ($arow = mysqli_fetch_assoc($api_ids_q)) $api_ids[] = $arow['id'];

                    if (!empty($api_ids)) {
                        $api_list = implode(',', $api_ids);
                        $plans_q = mysqli_query($connection_server, "SELECT val_1, $val_col, val_3, val_4 FROM sas_api_parameter_values WHERE vendor_id='$vendor_a_id' AND product_id='$vendor_a_product_id' AND api_id IN ($api_list) AND status=1 GROUP BY val_1");
                        while ($p = mysqli_fetch_assoc($plans_q)) {
                            $plans[] = [
                                'name' => !empty($p['val_4']) ? $p['val_4'] : strtoupper($network) . " " . str_replace(["_", "-"], " ", strtoupper($p['val_1'])),
                                'code' => $p['val_1'],
                                'price' => $p[$val_col],
                                'days' => $p['val_3']
                            ];
                        }
                    } else {
                        throw new Error("No API configured for $api_type on $gateway");
                    }
                } else {
                    throw new Error("Product $network not found on $gateway");
                }
            } else {
                // Nothing could describe this provider. Where we did manage to reach another DGV7
                // install, its own reason ("API approval needed, Contact Admin", a domain that does
                // not serve the script, no outbound HTTPS) is far more actionable than "unsupported
                // gateway", which reads as if the feature did not exist.
                throw new Error($remote_error ?: ("Unsupported gateway: " . $gateway));
            }
        }
    }

    // Sort plans by validity days (ascending)
    usort($plans, function($a, $b) {
        return $a['days'] <=> $b['days'];
    });

    $fetch_response = ["success" => true, "plans" => $plans];
    if (!empty($fetch_note)) {
        $fetch_response["note"] = $fetch_note;
    }
    echo json_encode($fetch_response);

} catch (Exception $e) {
    echo json_encode(["success" => false, "message" => $e->getMessage()]);
} catch (Error $e) {
    echo json_encode(["success" => false, "message" => $e->getMessage()]);
}
?>
