<?php
// Private bootstrap: copy outside document root. No application config/provider credentials loaded.
ini_set('display_errors','0');
header('Cache-Control: no-store, private, max-age=0');header('X-Content-Type-Options: nosniff');header('Referrer-Policy: no-referrer');
try {
    $path=__DIR__.'/config.php';
    if(!is_file($path)){http_response_code(404);exit;}
    $config=require $path;
    if(!is_array($config)||($config['enabled']??false)!==true){http_response_code(404);exit;}
    require_once __DIR__.'/http.php';
    $headers=function_exists('getallheaders')?getallheaders():[];
    // No getenv/HTTP credential fallbacks. Config is a private, owner-readable PHP file.
    $connect=static function()use($config){
        $db=$config['database']??null;
        if(!is_array($db)||($db['host']??'')!=='localhost'||!is_string($db['name']??null)||$db['name']===''||!is_string($db['user']??null)||$db['user']===''||!is_string($db['password']??null)||$db['password']==='')throw new RuntimeException('receiver_http_config_invalid');
        mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
        $connection=mysqli_init();$connection->options(MYSQLI_OPT_CONNECT_TIMEOUT,3);
        // cPanel local MySQL only. Remote DB requires a separately reviewed verified-TLS adapter.
        $connection->real_connect('localhost',$db['user'],$db['password'],$db['name']);
        $connection->query('SET SESSION innodb_lock_wait_timeout=3');
        $connection->query('SET SESSION max_execution_time=3000');
        return $connection;
    };
    $receipt=bc_alert_http_accept($_SERVER,$headers,$config,static function($limit){$f=fopen('php://input','rb');if($f===false)throw new RuntimeException('receiver_input_unavailable');try{return stream_get_contents($f,$limit);}finally{fclose($f);}},$connect,time());
    header('Content-Type: application/json');http_response_code(200);echo $receipt;
}catch(Throwable $e){http_response_code(400);header('Content-Type: application/json');echo '{"accepted":false}';}
