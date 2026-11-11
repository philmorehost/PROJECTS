<?php
ob_start();require __DIR__.'/church-portal.php';ob_end_clean();
set_error_handler(function(int $severity,string $message,string $file,int $line):never{throw new ErrorException($message,0,$severity,$file,$line);});
$portalReady=true;$ids=[1];$placeholders='?';$rows=[];$treasurer=false;$user=$officer;$page='remittances';$admin=false;$error=null;$flash=null;$_GET=['pay'=>'payhub'];$_POST=[];
ob_start();require __DIR__.'/../app/payments-view.php';$html=ob_get_clean();
echo 'title:';var_export(str_contains($html,'PayHub Checkout & Virtual Account'));
echo "\npayhub_form:";var_export(str_contains($html,'payhub-form'));
echo "\n";
echo substr($html,0,1200);
