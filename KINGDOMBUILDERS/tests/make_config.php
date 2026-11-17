<?php
declare(strict_types=1);
/** CLI-only disposable test configuration. Never point this at production. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
if (getenv('KB_LOCAL_PREVIEW') !== '1' || !str_ends_with((string)getenv('KB_DB_NAME'), '_test')) { fwrite(STDERR,"KB_LOCAL_PREVIEW=1 and a *_test database are required.\n"); exit(1); }
$dir = getenv('KB_CONFIG_DIR');
if (!$dir || is_file($dir.'/site.php')) { fwrite(STDERR,"Set an empty KB_CONFIG_DIR outside the web root.\n"); exit(1); }
if (!is_dir($dir) && !mkdir($dir, 0700, true)) exit(1);
$config = ['key'=>base64_encode(random_bytes(32)), 'db'=>['host'=>getenv('KB_DB_HOST') ?: '127.0.0.1', 'port'=>(int)(getenv('KB_DB_PORT') ?: 3306), 'name'=>getenv('KB_DB_NAME'), 'user'=>getenv('KB_DB_USER'), 'password'=>getenv('KB_DB_PASSWORD') ?: '', 'socket'=>''], 'site_url'=>getenv('KB_BASE_URL') ?: 'http://127.0.0.1:8080', 'base_path'=>'', 'local_preview'=>true];
file_put_contents($dir.'/site.php', "<?php\nreturn ".var_export($config, true).";\n");
chmod($dir.'/site.php', 0600);
file_put_contents($dir.'/installed.lock', 'test');
echo "Test configuration created.\n";
