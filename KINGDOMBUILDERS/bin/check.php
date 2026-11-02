<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli') { http_response_code(404); exit; }
require dirname(__DIR__).'/app/bootstrap.php';
$ok=true;
echo 'Build: '.KB\VERSION.' (PHP '.PHP_VERSION.')'.PHP_EOL;
foreach(['app/Core/Cron.php'=>'cron instructions','app/Core/MailError.php'=>'SMTP diagnostics','app/Views/admin/_cron.php'=>'cron card','app/Views/admin/cron.php'=>'cron page'] as $file=>$label) {
    $exists=is_file(dirname(__DIR__).'/'.$file);
    echo ($exists?'PASS':'FAIL').' '.$label.' file ('.$file.')'.PHP_EOL; $ok=$ok&&$exists;
}
foreach(KB\Installer::checks() as [$label,$passed,$detail]) { echo ($passed?'PASS':'FAIL').' '.$label.' ('.$detail.')'.PHP_EOL; $ok=$ok&&$passed; }
if(KB\installed()) {
    echo 'Schema: '.KB\DB::value('SELECT MAX(version) FROM schema_migrations').PHP_EOL;
    echo 'Active questions: '.KB\DB::value('SELECT COUNT(*) FROM questions WHERE is_active=1').PHP_EOL;
    echo 'SMTP: '.(KB\Settings::get('smtp_enabled')?'enabled':'disabled; messages remain queued').PHP_EOL;
}
exit($ok?0:1);
