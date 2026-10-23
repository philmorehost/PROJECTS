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
    if ($gateway === 'vtpass') {
        if ($type === 'airtime') {
            $plans[] = [
                'name' => strtoupper($network) . " Airtime",
                'code' => 'airtime',
                'price' => 100,
                'days' => 0
            ];
        } elseif ($type === 'cable') {
            $url = "https://vtpass.com/api/service-variations?serviceID=" . $network;

            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_USERAGENT, "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36");
            $response = curl_exec($ch);
            curl_close($ch);

            $data = json_decode($response, true);

            if (isset($data['content']['variations'])) {
                foreach ($data['content']['variations'] as $v) {
                    $plans[] = [
                        'name' => $v['name'],
                        'code' => $v['variation_code'],
                        'price' => $v['variation_amount'],
                        'days' => 30 // Cable usually 30 days
                    ];
                }
            } else {
                throw new Error("Invalid response from VTPASS");
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
        // time (unlike a hand-maintained catalog or a live-fetch endpoint most providers don't expose).
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

        if (!$used_gateway_file) {
            // Fallback: DGV7 API Fetch Protocol (for reseller/white-label DGV7 instances acting as
            // upstream providers — irrelevant for third-party gateways with no embedded plan array,
            // which is the expected/common case for the providers above and will legitimately fail here).
            $vid = $get_logged_admin_details['id'] ?? 1;
            // The stored api_base_url is admin/marketplace-typed and may carry a scheme, a "www."
            // prefix or a trailing slash. Matching it by exact string silently found no row, left
            // $api_key empty, and the client then sent "Authorization: Bearer " - which the DGV7
            // provider correctly reported as "Missing API Key". Match on the normalised host, and
            // require a non-empty key so this can never be sent blank again.
            $gateway_host = strtolower(trim($gateway));
            $gateway_host = preg_replace('#^https?://#i', '', $gateway_host);
            $gateway_host = preg_replace('#^www\.#i', '', $gateway_host);
            $gateway_host = rtrim($gateway_host, '/');
            $gateway_esc  = mysqli_real_escape_string($connection_server, $gateway);
            $gateway_like = mysqli_real_escape_string($connection_server, '%' . $gateway_host . '%');
            $api_q = mysqli_query($connection_server, "SELECT api_key FROM sas_apis WHERE vendor_id='$vid' AND (api_base_url='$gateway_esc' OR api_base_url LIKE '$gateway_like') AND api_key IS NOT NULL AND api_key<>'' LIMIT 1");
            $api_key = '';
            if ($api_q && ($api_row = mysqli_fetch_assoc($api_q))) {
                $api_key = trim($api_row['api_key']);
            }
            if ($api_key === '') {
                // Never send an empty token - it is indistinguishable from a stripped header at the
                // far end. Name the real problem instead.
                throw new Error("No API key saved for $gateway. Add the DGV7 provider's API key under the API settings for this service, then try again.");
            }

            $fetch_base = strtolower(rtrim($gateway, '/'));
            if (!preg_match('/^https?:\/\//i', $fetch_base)) {
                $fetch_base = "https://" . $fetch_base;
            }
            // The key is sent BOTH as a Bearer header and as a query parameter. Many cPanel/LiteSpeed
            // hosts do not expose the Authorization header to PHP, and cURL drops custom headers when
            // a redirect crosses to another host - either one surfaces at the provider as "Missing API
            // Key" even though the key is valid. The query parameter survives both.
            $fetch_url = $fetch_base . "/api/app-backend/fetch-dgv7-plans.php?network=" . urlencode($network) . "&type=" . urlencode($type) . "&api_key=" . urlencode($api_key);

            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $fetch_url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer $api_key", "Content-Type: application/json"]);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);
            $response = curl_exec($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curl_err  = curl_error($ch);
            curl_close($ch);

            if ($http_code === 200 && $response) {
                $data = json_decode($response, true);
                if ($data && isset($data['success']) && $data['success'] === true && isset($data['plans'])) {
                    $plans = $data['plans'];
                } else {
                    throw new Error("Invalid format returned from DGV7 provider: " . ($data['message'] ?? 'Unknown error'));
                }
            } else {
                throw new Error("Failed to connect to DGV7 API at $gateway (HTTP $http_code)" . ($curl_err ? ": ".$curl_err : ".") . " Ensure it is a valid DGV7 URL and that outbound HTTPS is allowed.");
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
