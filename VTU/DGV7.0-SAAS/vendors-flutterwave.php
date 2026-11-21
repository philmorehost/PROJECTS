<?php
http_response_code(503); header('Content-Type: application/json'); header('Cache-Control: no-store');
echo json_encode(array('status'=>'pending','code'=>'LEGACY_SETTLEMENT_HOLD','message'=>'Legacy settlement is held for reconciliation. No funds were credited or refunded. Preserve the event and reconcile through support.'));
