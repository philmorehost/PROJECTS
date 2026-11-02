<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli') { http_response_code(404); exit; }
require dirname(__DIR__).'/app/bootstrap.php';
$ok=true;
foreach(KB\Installer::checks() as [$label,$passed,$detail]) { echo ($passed?'PASS':'FAIL').' '.$label.' ('.$detail.')'.PHP_EOL; $ok=$ok&&$passed; }
if(KB\installed()) {
    echo 'Schema: '.KB\DB::value('SELECT MAX(version) FROM schema_migrations').PHP_EOL;
    echo 'Active questions: '.KB\DB::value('SELECT COUNT(*) FROM questions WHERE is_active=1').PHP_EOL;
    echo 'SMTP: '.(KB\Settings::get('smtp_enabled')?'enabled':'disabled; messages remain queued').PHP_EOL;
}
exit($ok?0:1);
