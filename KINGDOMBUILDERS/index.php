<?php
declare(strict_types=1);
require __DIR__ . '/app/bootstrap.php';

use KB\Admin;
use KB\Auth;
use KB\Controllers;
use KB\Installer;
use KB\Security;
use KB\UserError;
use function KB\{render,installed,redirect,url,json_response,header_security};

function route(): string {
    $route = $_GET['route'] ?? '';
    if (!is_string($route)) return '';
    if ($route === '') {
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/',PHP_URL_PATH) ?: '/';
        $base = \KB\base_path();
        if ($base && str_starts_with($path,$base.'/')) $path = substr($path,strlen($base));
        $route = trim($path,'/');
        if ($route === 'index.php') $route = '';
    }
    return trim($route,'/');
}

try {
    $route = route();
    if (in_array($route,['reset-password','verify-email'],true)) header('Referrer-Policy: no-referrer');
    if ($route === 'favicon.svg') {
        $letter = \KB\initial(); $etag = '"' . hash('sha256',$letter) . '"';
        header('Content-Type: image/svg+xml; charset=utf-8');
        header('Cache-Control: private, max-age=300, must-revalidate');
        header('ETag: ' . $etag);
        if (trim($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) { http_response_code(304); exit; }
        echo '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64"><rect width="64" height="64" rx="16" fill="#142d3a"/><text x="32" y="45" text-anchor="middle" font-family="Georgia,serif" font-size="43" font-weight="bold" fill="#e9c46a">' . \KB\e($letter) . '</text></svg>';
        exit;
    }
    if ($route === 'health') {
        if (installed()) \KB\DB::value('SELECT 1');
        json_response(['status'=>'ok','build'=>\KB\VERSION,'php'=>PHP_VERSION]);
    }
    if ($route === 'text-size') {
        Security::check();
        $size = in_array(post('size'), ['standard','large','xlarge'], true) ? post('size') : 'standard';
        setcookie('kb_text', $size, ['expires'=>time()+31536000,'path'=>(\KB\base_path()?:'/'),'secure'=>Security::requestHttps(),'httponly'=>true,'samesite'=>'Lax']);
        $back = post('back');
        if ($back === '' || !str_starts_with($back,'/') || str_starts_with($back,'//') || preg_match('/[\r\n]/', $back)) $back = url();
        header('Location: ' . $back, true, 303); exit;
    }
    if (!installed() && $route !== 'install') redirect('install');
    if ($route === 'install') Installer::handle();
    if (!\KB\Activation::allowed() && !in_array($route,['admin/authorization','login','logout'],true)) redirect('admin/authorization');
    if ($route === '') Controllers::home();
    elseif ($route === 'support/return') { Security::check(); if(\KB\is_post()) { Auth::returnToAdmin(); redirect('admin'); } redirect('dashboard'); }
    elseif (in_array($route,['login','register','forgot-password','reset-password','verify-email','logout'],true)) Controllers::auth($route);
    elseif ($route === 'auth/google') Controllers::google('start');
    elseif ($route === 'auth/google/callback') Controllers::google('callback');
    elseif (in_array($route,['dashboard','account'],true)) Controllers::$route();
    elseif (str_starts_with($route,'quiz') || str_starts_with($route,'api/quiz') || $route==='result') Controllers::quiz($route);
    elseif (str_starts_with($route,'admin')) Admin::handle($route);
    else throw new UserError('This page could not be found.',404);
} catch (UserError $e) {
    if (str_starts_with(route(),'api/')) json_response(['error'=>$e->getMessage()],$e->status);
    render('errors/message',['heading'=>$e->status===404?'Page not found':'We couldn’t complete that request','message'=>$e->getMessage()],$e->status);
}
