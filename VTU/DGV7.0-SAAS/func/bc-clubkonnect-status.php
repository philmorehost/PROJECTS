<?php
/** Query replies are evidence of delivery, not permission to retry a purchase.
 * ClubKonnect dispute guide: only 200 confirms success; 201/299 are uncertain.
 * Authentication/configuration errors describe the query, not the original order.
 */
if (!function_exists('bc_clubkonnect_query_verdict')) {
    function bc_clubkonnect_query_verdict($body, $transport_ok = true) {
        $reply = json_decode(ltrim((string)$body, "\xEF\xBB\xBF \r\n\t"), true);
        if (!$transport_ok || !is_array($reply)) return 'pending';
        $reply = array_change_key_case($reply, CASE_LOWER);
        $code = is_scalar($reply['statuscode'] ?? null) ? trim((string)$reply['statuscode']) : '';
        $state = strtoupper(trim(is_scalar($reply['orderstatus'] ?? $reply['status'] ?? null) ? (string)($reply['orderstatus'] ?? $reply['status']) : ''));
        // Unknown/conflicting states must never authorize delivery or a refund.
        if ($code === '200' && in_array($state, array('', 'ORDER_COMPLETED', 'SUCCESS', 'SUCCESSFUL', 'DELIVERED'), true)) return 'successful';
        $cancel_codes = array('500','501','506','507','508','509','510','511','512','513','514','515','516','517','518','519','520','599');
        if (in_array($code, $cancel_codes, true) && $state === 'ORDER_CANCELLED') return 'failed';
        if ($code === '899' && $state === 'ORDER_REFUNDED') return 'failed';
        // 521 means already completed/cancelled/refunded: it cannot disambiguate.
        return 'pending';
    }
}
