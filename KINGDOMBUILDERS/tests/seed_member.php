<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__).'/app/bootstrap.php';
if (!KB\installed() || !KB\config('local_preview',false) || !str_ends_with(KB\config('db')['name'],'_test')) { fwrite(STDERR,"Disposable local *_test configuration required.\n"); exit(1); }
$email = strtolower($argv[1] ?? ''); $password = $argv[2] ?? '';
if (!KB\valid_email($email) || !KB\valid_password($password)) exit(1);
KB\DB::run('INSERT INTO users(name,email,password_hash,role,status,email_verified_at,created_at,updated_at) VALUES (?,?,?,"member","active",?,?,?) ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)', ['Smoke Verified Member',$email,KB\hash_password($password),KB\now(),KB\now(),KB\now()]);
echo KB\DB::id(),PHP_EOL;
