<?php
// Only the disposable integration runner may create these synthetic legacy-wallet fixtures.
if (!isset($created,$name) || !$created || !preg_match('/^crypto_ci_[a-f0-9]{24}$/D',$name)) throw new RuntimeException('Disposable runner required');
bc_q("ALTER TABLE sas_users ADD username VARCHAR(225) NOT NULL DEFAULT '', ADD email VARCHAR(225) NOT NULL DEFAULT '', ADD balance DECIMAL(30,8) NOT NULL DEFAULT 0");
bc_q("UPDATE sas_users SET username='ada',balance=100 WHERE id=11");
bc_q("CREATE TABLE sas_transactions (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, vendor_id INT NOT NULL, username VARCHAR(225), product_unique_id VARCHAR(225),
 type_alternative VARCHAR(225), reference VARCHAR(225), api_reference VARCHAR(225), amount DECIMAL(30,8), discounted_amount DECIMAL(30,8),
 balance_before DECIMAL(30,8), balance_after DECIMAL(30,8), description TEXT, mode VARCHAR(30), api_website VARCHAR(225), status INT
) ENGINE=InnoDB");
// Exact replay-event DDL currently shipped by the reviewed legacy migration.
$schema_source=file_get_contents($base.'bc-tables.php');
if (!preg_match('/CREATE TABLE IF NOT EXISTS sas_wallet_mutation_events \(.*?\) ENGINE=InnoDB/s',$schema_source,$match)) throw new RuntimeException('Wallet migration DDL missing');
bc_q($match[0]);
$wallet=function($direction,$reference,$amount='25',$label='Data',$description='Synthetic fixture') {
 return bc_incident_wallet_post(7,'ada',$direction,'08000000000',$label,$reference,'',$amount,$amount,$description,'API','merchant.invalid',3);
};
check_db($wallet('debit','native-wallet-001')==='success','MySQL wallet debit passes transaction preflight');
check_db(scalar_db("SELECT balance FROM sas_users WHERE id=11")==='75.00000000','actual wallet debits exact amount');
check_db($wallet('debit','native-wallet-001')==='failed' && (int)scalar_db('SELECT COUNT(*) FROM sas_transactions')===1,'replayed wallet debit cannot authorize another delivery');
check_db($wallet('credit','native-refund-001','25','Refund',"Refund for Ref:'native-wallet-001'")==='success','exact linked refund succeeds');
check_db($wallet('credit','native-refund-002','25','Refund',"Refund for Ref:'native-wallet-001'")==='success' && scalar_db("SELECT balance FROM sas_users WHERE id=11")==='100.00000000','refund replay does not mint funds');
$connection_server->begin_transaction();
check_db($wallet('debit','nested-wallet-001')==='failed','wallet rejects nested transaction before first read');
bc_q("UPDATE sas_users SET balance=1 WHERE id=11");$connection_server->rollback();
check_db(scalar_db("SELECT balance FROM sas_users WHERE id=11")==='100.00000000','wallet preflight never implicitly commits caller work');
$connection_server->autocommit(false);
check_db($wallet('debit','autocommit-wallet-001')==='failed','wallet refuses autocommit-off session');
$connection_server->rollback();$connection_server->autocommit(true);
$a=start_worker('wallet','concurrent-wallet-001','25');$b=start_worker('wallet','concurrent-wallet-001','25');
$results=[finish_worker($a),finish_worker($b)];
check_db(count(array_filter($results,fn($r)=>($r['result']??'')==='success'))===1 && count(array_filter($results,fn($r)=>($r['result']??'')==='failed'))===1,'competing wallet reference authorizes exactly one purchase');
check_db(scalar_db("SELECT balance FROM sas_users WHERE id=11")==='75.00000000','competing wallet reference debits once');
$balance=scalar_db("SELECT balance FROM sas_users WHERE id=11");$events=scalar_db('SELECT COUNT(*) FROM sas_wallet_mutation_events');
$connection_server->query("CREATE TRIGGER fail_wallet_receipt BEFORE INSERT ON sas_transactions FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='injected wallet receipt failure'");
check_db($wallet('debit','wallet-receipt-fail-001')==='failed','receipt insert failure refuses purchase authorization');
check_db(scalar_db("SELECT balance FROM sas_users WHERE id=11")===$balance && scalar_db('SELECT COUNT(*) FROM sas_wallet_mutation_events')===$events,'failed receipt rolls back real balance and replay claim');
$connection_server->query('DROP TRIGGER fail_wallet_receipt');
check_db($wallet('debit','wallet-overdraw-001','76')==='failed' && scalar_db("SELECT balance FROM sas_users WHERE id=11")===$balance,'wallet rejects insufficient funds without partial writes');
