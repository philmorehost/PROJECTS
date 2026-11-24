<?php
// CLI-only browser fixture; no database, real account or provider bootstrap.
if(isset($_SERVER['REQUEST_METHOD'])){http_response_code(404);exit;}
require __DIR__.'/../DGV7.0-SAAS/func/bc-wallet-funding-page.php';
$base=['available'=>true,'csrf'=>'fixture-csrf','amount'=>'100.25','error'=>'','bank'=>null];
$cases=['ready'=>$base,'blocked'=>array_merge($base,['available'=>false,'error'=>'Funding rollout is pending. Do not transfer.']),'done'=>array_merge($base,['bank'=>['bank_name'=>'<img src=x onerror=alert(1)>','account_name'=>'Fixture Alice','account_number'=>'0123456789']]),'error'=>array_merge($base,['error'=>'Security validation failed. Refresh the page and try again.'])];
$out=[];foreach($cases as $key=>$model){ob_start();bc_fund_render($model);$out[$key]='<!doctype html><html><head><meta name="viewport" content="width=device-width, initial-scale=1"><style>body{font:16px system-ui;margin:16px}*{box-sizing:border-box}input[type=text],button{width:100%;padding:12px}button{min-height:48px}dd{margin-left:0}section{max-width:760px;margin:auto}p,dd{overflow-wrap:anywhere}</style></head><body>'.ob_get_clean().'<script src="/jsfile/bc-wallet-funding.js" defer></script></body></html>';}
echo json_encode($out);
