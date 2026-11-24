<?php
/** Restricted local operator command. Inspection default; no send/settlement capability. */
if(PHP_SAPI!=='cli'){http_response_code(403);exit('CLI only.');}
$options=[];$record=false;
foreach(array_slice($argv,1) as $arg){
 if($arg==='--help'){echo "Usage: php bin/review-webhook-outbox.php --transaction-id=N\nInspection returns an observation digest only. To append a review, add --record plus --request-id=OPAQUE --actor-ref=OPAQUE --evidence-ref=OPAQUE --evidence-sha256=HEX --observation-sha256=HEX --finding=recipient_reports_received|receipt_not_confirmed|escalated\nRecording also requires PAYHUB_WEBHOOK_REVIEW_ENABLED=1. No raw evidence, URLs, secrets or personal data in arguments. Actor is an operator assertion, not independently authenticated by this CLI. No replay, settlement or claim reset.\n";exit;}
 if($arg==='--record'){$record=true;continue;}
 if(!preg_match('/\A--(transaction-id|request-id|actor-ref|evidence-ref|evidence-sha256|observation-sha256|finding)=(.+)\z/D',$arg,$m)||isset($options[$m[1]])){fwrite(STDERR,"Invalid option. Use --help.\n");exit(2);}$options[$m[1]]=$m[2];
}
require_once __DIR__.'/../includes/webhook-review.php';
try{
 $raw=$options['transaction-id']??'';if(!preg_match('/\A[1-9][0-9]{0,9}\z/D',$raw)||(float)$raw>2147483647)throw new InvalidArgumentException('Invalid ID.');$id=(int)$raw;
 $input=[];foreach(['request_id','actor_ref','evidence_ref','evidence_sha256','observation_sha256','finding'] as $key)$input[$key]=$options[str_replace('_','-',$key)]??null;
 if($record){payhub_review_input($input);if(getenv('PAYHUB_WEBHOOK_REVIEW_ENABLED')!=='1')throw new InvalidArgumentException('Recording disabled.');}
 elseif(count($options)!==1)throw new InvalidArgumentException('Review fields require --record.');
}catch(Throwable $e){fwrite(STDERR,"Invalid or disabled review request. Use --help.\n");exit(2);}
try{
 require __DIR__.'/../includes/config.php';
 $db=new PDO('mysql:host='.DB_HOST.';dbname='.DB_NAME.';charset=utf8mb4',DB_USER,DB_PASS,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
 $result=$record?['transaction_id'=>$id,'status'=>payhub_record_webhook_review($db,$id,$input),'advisory_only'=>true]:payhub_inspect_webhook_review($db,$id);
 echo json_encode($result,JSON_THROW_ON_ERROR)."\n";
}catch(Throwable $e){fwrite(STDERR,"Review unavailable, changed, or conflicting. Inspect again with restricted operator tools. If commit outcome is uncertain, retry only the identical request reference and fields; never reset evidence.\n");exit(1);}
