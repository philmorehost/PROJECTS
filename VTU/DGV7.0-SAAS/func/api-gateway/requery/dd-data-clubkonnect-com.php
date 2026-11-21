<?php
require_once __DIR__ . '/../../bc-vtu-runtime.php';
require_once __DIR__ . '/../../bc-clubkonnect-status.php';
$api_response = 'pending';
$api_response_text = '';
$api_response_status = 2;
$api_response_description = 'Transaction Pending | awaiting confirmation from the provider';
if (!empty($requery_reference)) {
    $parts = explode(':', trim($api_detail['api_key'] ?? ''), 2);
    $params = array('UserID' => trim($parts[0] ?? ''), 'APIKey' => trim($parts[1] ?? ''));
    if (!empty($get_api_reference_id)) $params['OrderID'] = $get_api_reference_id;
    else $params['RequestID'] = preg_replace('/[^A-Za-z0-9]/', '', $requery_reference);
    $curl_url = 'https://www.nellobytesystems.com/APIQueryV1.asp?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    $curl_request = curl_init($curl_url);
    curl_setopt($curl_request, CURLOPT_HTTPGET, true);
    curl_setopt($curl_request, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($curl_request, CURLOPT_SSL_VERIFYHOST, 2);
    curl_setopt($curl_request, CURLOPT_SSL_VERIFYPEER, true);
    $curl_result = bc_vtu_curl_exec($curl_request);
    $curl_errno = curl_errno($curl_request);
    $curl_error = curl_error($curl_request);
    $curl_http_code = curl_getinfo($curl_request, CURLINFO_HTTP_CODE);
    curl_close($curl_request);
    $api_response = bc_clubkonnect_query_verdict($curl_result, !$curl_errno && $curl_http_code >= 200 && $curl_http_code < 300);
    $reply = json_decode(ltrim((string)$curl_result, "\xEF\xBB\xBF \r\n\t"), true);
    $reply = is_array($reply) ? array_change_key_case($reply, CASE_LOWER) : array();
    if (!empty($get_api_reference_id) && isset($reply['orderid']) && (!is_scalar($reply['orderid']) || (string)$reply['orderid'] !== (string)$get_api_reference_id)) $api_response = 'pending';
    $api_response_reference = is_scalar($reply['orderid'] ?? null) ? (string)$reply['orderid'] : ($get_api_reference_id ?? '');
    if ($api_response === 'successful') {
        $api_response_status = 1;
        $api_response_text = 'SUCCESS';
        $api_response_description = str_ireplace(array('pending', 'failed'), 'successful', getTransaction($requery_reference, 'description'));
    } elseif ($api_response === 'failed') {
        $api_response_status = 3;
        $api_response_text = 'FAILED'; // Only an explicit terminal cancellation/refund reaches here.
        $api_response_description = 'Transaction Failed | provider confirmed cancellation or refund';
    }
}
