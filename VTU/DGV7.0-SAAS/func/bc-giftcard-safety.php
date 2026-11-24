<?php
/** Fail-closed gift-card safety gate unless BC_GIFTCARD_WORKER_ENABLED=1. */
function bc_giftcard_execution_enabled(){
    return getenv('BC_GIFTCARD_WORKER_ENABLED') === '1';
}
function bc_giftcard_safety_response(){
    return ['status'=>'error','code'=>'GIFTCARD_SETTLEMENT_HOLD','message'=>'New gift-card purchases and P2P settlement are temporarily unavailable pending safe ledger/provider reconciliation. Existing cards remain viewable. Do not retry or manually refund existing trades.'];
}
function bc_giftcard_action_held($action){
    if (bc_giftcard_execution_enabled()) {
        return false;
    }
    return is_string($action) && in_array($action,['purchase_card','purchase','buy_p2p','send_otp'],true);
}
function bc_giftcard_safety_reply(){
    header('Content-Type: application/json; charset=UTF-8');header('Cache-Control: no-store');http_response_code(503);
    echo json_encode(bc_giftcard_safety_response());exit;
}
