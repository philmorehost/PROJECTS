<?php
// Development-only router. Apache/LiteSpeed deployment uses .htaccess.
declare(strict_types=1);
$path=rawurldecode(parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH) ?: '/');
if(preg_match('~(?:^|/)(?:\.|app(?:/|$)|config(?:/|$)|storage(?:/|$)|database(?:/|$)|tests(?:/|$)|bin(?:/|$))~',$path) || str_contains($path,'..')) {http_response_code(404);exit;}
if(in_array($path,['/sw.js','/manifest.webmanifest','/offline.html'],true))return false;
if(str_starts_with($path,'/public/assets/') && preg_match('/\.(css|js|png)$/D',$path) && is_file(__DIR__.$path)) return false;
if($path!=='/' && $path!=='/index.php'){http_response_code(404);exit;}
require __DIR__.'/index.php';
