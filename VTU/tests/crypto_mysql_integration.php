<?php
/** Destructive ONLY inside a new, randomly named disposable database. No application config loaded. */
if (PHP_SAPI !== 'cli' || getenv('BC_CRYPTO_TEST_MYSQL') !== 'DISPOSABLE_ONLY') {
    fwrite(STDERR, "Refusing: set BC_CRYPTO_TEST_MYSQL=DISPOSABLE_ONLY on an isolated test server.\n"); exit(2);
}
set_exception_handler(function(Throwable $e) {
    // GitHub annotation keeps failures inspectable without downloading external log archives.
    fwrite(STDERR, '::error::'.str_replace(["\r","\n"], ' ', $e->getMessage())."\n"); exit(1);
});
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$edition=getenv('BC_CRYPTO_TEST_EDITION') ?: 'SAAS';
if (!in_array($edition,['SAAS','NON-SAAS'],true)) throw new RuntimeException('Invalid edition');
$base=dirname(__DIR__).'/DGV7.0-'.$edition.'/func/';
require $base.'bc-stepup.php'; // Real production prepared-query helpers, not a storage double.
require $base.'bc-crypto-v2-schema.php';
require $base.'bc-crypto-governance-schema.php';
require $base.'bc-crypto-reservations.php';
function connect_test() {
    $db=new mysqli(getenv('BC_CRYPTO_TEST_HOST') ?: '127.0.0.1',getenv('BC_CRYPTO_TEST_USER') ?: 'root',getenv('BC_CRYPTO_TEST_PASSWORD') ?: '', '', (int)(getenv('BC_CRYPTO_TEST_PORT') ?: 3306));
    $db->query('SET SESSION innodb_lock_wait_timeout=10');
    return $db;
}
$connection_server=connect_test();
$scope=['vendor_id'=>7,'user_id'=>11,'environment'=>'testnet','asset_network'=>'USDT:TRX'];
$vendor=['role'=>'vendor','id'=>7,'single_owner'=>false];
$platform=['role'=>'spadmin','id'=>1];
// Child processes connect independently; their database name must match the parent's random namespace.
if (($argv[1]??'')==='worker') {
    $name=$argv[2]??'';
    if (!preg_match('/^crypto_ci_[a-f0-9]{24}$/D',$name)) throw new RuntimeException('Unsafe database name');
    $connection_server->select_db($name);
    file_put_contents($argv[3],(string)$connection_server->thread_id);
    try {
        if ($argv[4]==='reserve') $result=bc_crypto_v2_reservation($scope,$argv[5],$argv[6]);
        else $result=bc_crypto_gov_save($vendor,7,'testnet','control',(int)$argv[5],['mode'=>'stop'],7);
        echo json_encode(['result'=>$result]);
    } catch(Throwable $e) { echo json_encode(['error'=>$e->getMessage()]); }
    exit;
}
$n=0;$workers=[];$files=[];
function check_db($ok,$label) {global $n;if(!$ok)throw new RuntimeException($label);$n++;echo "ok $n - $label\n";}
function reject_db($fn,$code) {try{$fn();}catch(Throwable $e){check_db($e->getMessage()===$code,'reject '.$code);return;}throw new RuntimeException('Unexpected success: '.$code);}
function scalar_db($sql) {return array_values(bc_q_row($sql))[0];}
function start_worker($action,$reference,$units='') {
    global $workers,$files,$name;
    $file=tempnam(sys_get_temp_dir(),'crypto-ci-');$files[]=$file;
    $p=proc_open([PHP_BINARY,__FILE__,'worker',$name,$file,$action,$reference,$units],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    if (!is_resource($p)) throw new RuntimeException('Worker failed to start');
    fclose($pipes[0]);$w=[$p,$pipes,$file];$workers[]=$w;return $w;
}
function finish_worker($w) {
    $out=stream_get_contents($w[1][1]);$err=stream_get_contents($w[1][2]);fclose($w[1][1]);fclose($w[1][2]);
    if(proc_close($w[0])!==0)throw new RuntimeException('Worker failed: '.$err);
    return json_decode($out,true,512,JSON_THROW_ON_ERROR);
}
function wait_for_lock($w) {
    $deadline=microtime(true)+8;
    do {
        $id=(int)file_get_contents($w[2]);
        if($id && (int)scalar_db('SELECT COUNT(*) FROM performance_schema.data_lock_waits w JOIN performance_schema.threads t ON t.THREAD_ID=w.REQUESTING_THREAD_ID WHERE t.PROCESSLIST_ID='.$id)>0) {check_db(true,'independent connection observed waiting on InnoDB row lock');return;}
        usleep(20000);
    } while(microtime(true)<$deadline);
    throw new RuntimeException('No observed lock wait (requires MySQL 8 performance_schema access)');
}
$name='crypto_ci_'.bin2hex(random_bytes(12));
$created=false;
try {
    $connection_server->query('CREATE DATABASE `'.$name.'`');$created=true;
    $connection_server->select_db($name);
    // Minimal legacy fixture: never an application's sas_users or live balance table.
    bc_q('CREATE TABLE sas_users (id BIGINT UNSIGNED PRIMARY KEY,vendor_id BIGINT UNSIGNED,status INT,is_blocked INT,force_password_change INT,kyc_status INT) ENGINE=InnoDB');
    bc_q('INSERT INTO sas_users VALUES(11,7,1,0,0,2)');
    $ddl=array_merge(bc_crypto_v2_schema_statements(),bc_crypto_governance_schema_statements());
    foreach($ddl as $sql) bc_q($sql);
    foreach($ddl as $sql) bc_q($sql); // Additive installer is repeatable, not a schema repair tool.
    check_db((int)scalar_db("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND engine='InnoDB'")===8,'seven production tables plus fixture are InnoDB; second install succeeds');
    check_db((int)scalar_db('SELECT COUNT(*) FROM sas_crypto_v2_asset_policies')===0,'migration creates no enabled policies');
    reject_db(fn()=>bc_crypto_v2_reservation($scope,'closed-first-001','1'),'crypto_emergency_stop');
    $network=['asset_network'=>'USDT:TRX','platform_enabled'=>false,'provider_deposit_available'=>true,'provider_withdraw_available'=>true,'confirmations'=>'20','withdrawal_confirmations'=>'40'];
    bc_crypto_gov_store_snapshot(7,['provider'=>'bybit','environment'=>'testnet','security'=>['read_only'=>true,'ip_binding_verified'=>true],'networks'=>[$network]]);
    $input=['mode'=>'approve','scale'=>'6','minimum'=>'0.000001','maximum'=>'100','fee_bps'=>'100','confirmations'=>'40','cooldown_seconds'=>'86400','valid_seconds'=>'3600'];
    foreach([[$platform,0],[$vendor,7]] as [$actor,$scopeId]) {
        bc_crypto_gov_save($actor,$scopeId,'testnet','USDT:TRX',0,$input,7);
        bc_crypto_gov_save($actor,$scopeId,'testnet','control',0,['mode'=>'resume'],7);
    }
    reject_db(fn()=>bc_crypto_v2_reservation($scope,'account-closed-001','1'),'crypto_policy_closed');
    // Explicit synthetic funding ONLY in this randomly created disposable database. Not an opening-entry implementation.
    bc_q("INSERT INTO sas_crypto_v2_asset_policies VALUES(7,'testnet','USDT:TRX',6,1,1,'matched',?,100000000)",[time()+3600]);
    foreach(['available'=>'100000000','reserved'=>'0','held'=>'7654321'] as $bucket=>$units) bc_q("INSERT INTO sas_crypto_v2_accounts(vendor_id,user_id,environment,asset_network,scale,bucket,units) VALUES(7,11,'testnet','USDT:TRX',6,?,?)",[$bucket,$units]);
    $a=start_worker('reserve','concurrent-same-001','1000000');$b=start_worker('reserve','concurrent-same-001','1000000');
    $ra=finish_worker($a);$rb=finish_worker($b);
    check_db(isset($ra['result'],$rb['result']) && $ra['result']['duplicate']!==$rb['result']['duplicate'],'concurrent identical reference commits once and returns duplicate');
    check_db((int)scalar_db('SELECT COUNT(*) FROM sas_crypto_v2_operations')===1 && (int)scalar_db('SELECT COUNT(*) FROM sas_crypto_v2_entries')===2,'exactly one operation and balanced pair');
    check_db(scalar_db("SELECT units FROM sas_crypto_v2_accounts WHERE bucket='available'")==='99000000','exact decimal debit');
    reject_db(fn()=>bc_crypto_v2_reservation($scope,'concurrent-same-001','2'),'crypto_idempotency_conflict');
    // Audit insert failure must roll back the preceding policy UPDATE, using a real server trigger.
    bc_q("CREATE TRIGGER fail_policy_audit BEFORE INSERT ON sas_crypto_v2_policy_audit FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='injected audit failure'");
    $before=bc_crypto_gov_row(7,'testnet','control');$audits=scalar_db('SELECT COUNT(*) FROM sas_crypto_v2_policy_audit');
    try {bc_crypto_gov_save($vendor,7,'testnet','control',1,['mode'=>'stop'],7);throw new LogicException('Audit unexpectedly succeeded');} catch(mysqli_sql_exception $e) {check_db($e->getSqlState()==='45000','server rejected audit insert');}
    check_db(bc_crypto_gov_row(7,'testnet','control')===$before && scalar_db('SELECT COUNT(*) FROM sas_crypto_v2_policy_audit')===$audits,'audit failure rolls back document revision and audit');
    bc_q('DROP TRIGGER fail_policy_audit');
    // Hold an uncommitted stop; admission must block, then reject after the stop commits.
    $connection_server->begin_transaction();
    bc_q("UPDATE sas_crypto_v2_governance SET document='{\"stopped\":true}' WHERE vendor_id=0 AND policy_key='control'");
    $w=start_worker('reserve','stopped-wait-001','1');wait_for_lock($w);$connection_server->commit();
    check_db((finish_worker($w)['error']??'')==='crypto_emergency_stop','waiting admission sees committed stop');
    check_db((int)scalar_db('SELECT COUNT(*) FROM sas_crypto_v2_operations')===1,'stopped admission creates no journal');
    bc_crypto_v2_reservation($scope,'release-stopped-001','1000000','concurrent-same-001');
    reject_db(fn()=>bc_crypto_v2_reservation($scope,'release-again-001','1000000','concurrent-same-001'),'crypto_reservation_already_released');
    check_db(scalar_db("SELECT units FROM sas_crypto_v2_accounts WHERE bucket='held'")==='7654321','release during stop preserves held funds');
    // Two editors starting from the same revision cannot silently overwrite each other.
    $connection_server->begin_transaction();bc_crypto_gov_row(7,'testnet','control',true);
    $w=start_worker('stop','1');wait_for_lock($w);
    bc_q("UPDATE sas_crypto_v2_governance SET revision=revision+1 WHERE vendor_id=7 AND policy_key='control'");$connection_server->commit();
    check_db((finish_worker($w)['error']??'')==='crypto_policy_edit_conflict','waiting stale editor rejects committed newer revision');
    check_db(scalar_db("SELECT units FROM sas_crypto_v2_accounts WHERE bucket='available'")==='100000000' && scalar_db("SELECT units FROM sas_crypto_v2_accounts WHERE bucket='reserved'")==='0','reserve and release conserve exact synthetic balance');
    check_db((int)scalar_db('SELECT COUNT(*) FROM sas_crypto_v2_entries')===4,'failed operations leave no partial entries');
    echo "$n real MySQL checks passed ($edition); no provider calls or application database used.\n";
} finally {
    $connection_server->rollback();
    foreach($workers as $w) if(is_resource($w[0])) {proc_terminate($w[0]);proc_close($w[0]);}
    foreach($files as $file) if(is_file($file))unlink($file);
    if($created)$connection_server->query('DROP DATABASE `'.$name.'`');
}
