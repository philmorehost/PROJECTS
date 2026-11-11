<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
const ROOT=__DIR__.'/..';require ROOT.'/app/Database.php';require ROOT.'/app/helpers.php';
try{echo ApprovalNotifications::dispatch(Database::get(),25)." approval emails accepted by mail transport.\n";}catch(Throwable $e){fwrite(STDERR,"Approval mail delivery failed. Check database and server mail configuration.\n");exit(1);}
