<?php
declare(strict_types=1);
require __DIR__.'/../app/Money.php';require __DIR__.'/../app/helpers.php';
$_SESSION=[];
$role=$argv[1] ?? 'parish_officer';
if(!in_array($role,['parish_officer','provincial_admin','area_rep'],true))throw new RuntimeException('Invalid fixture role.');
$user=['full_name'=>'Test Officer','role'=>$role];$admin=$role==='provincial_admin';$treasurer=$admin;
$page='overview';$rows=[];$due=0;$paid=0;
function hiddenAction(string $action):void{echo '<input type="hidden" name="csrf" value="'.e(csrf()).'"><input type="hidden" name="action" value="'.e($action).'">';}
ob_start();require __DIR__.'/../app/dashboard-actions.php';$dashboard=ob_get_clean();
ob_start();require __DIR__.'/../app/mobile-navigation.php';$navigation=ob_get_clean();
$checks=0;
function verify(bool $condition,string $label):void{global $checks;$checks++;if(!$condition)throw new RuntimeException('FAIL: '.$label);}
verify(str_contains($navigation,'aria-label="Primary footer navigation"'),'named bottom navigation');
verify(str_contains($navigation,'aria-current="page"'),'current page announced');
verify(str_contains($navigation,'aria-labelledby="mobile-menu-title"'),'named modal');
verify(str_contains($navigation,'data-menu-close'),'visible close button');
verify(str_contains($navigation,'mobile-fallback-nav'),'no-script and old-browser fallback');
verify(str_contains($navigation,'aria-expanded="false"'),'menu toggle initial state');
verify(str_contains($navigation,'Sign out securely'),'mobile logout action');
verify(str_contains($navigation,'Portal settings')===$admin,'settings role visibility');
verify(str_contains($navigation,'Security centre')===$admin,'security role visibility');
verify(str_contains($navigation,'My church account')===($role==='parish_officer'),'profile role visibility');
verify(str_contains($dashboard,'Report an offering')===($role==='parish_officer'),'role-aware dashboard action');
verify(str_contains($dashboard,'Manage percentages')===$admin,'admin dashboard shortcut');
verify(str_contains($dashboard,'quick-actions-title'),'dashboard section labelled');
if(isset($argv[2])){
 $ledger='<div class="panel"><div class="panel-heading"><h2>Remittance ledger</h2><label class="search-label"><input id="ledger-search" aria-label="Search ledger"></label><label class="ledger-filter">Status<select id="ledger-status"><option value="">All statuses</option><option value="pending">Pending</option><option value="verified">Verified</option></select></label></div><p id="ledger-result" aria-live="polite"></p><div class="table-wrap"><table id="ledger"><thead><tr><th>Church</th><th>Period</th><th>Total</th><th>Retained</th><th>Due</th><th>Confirmed</th><th>Status</th></tr></thead><tbody>';
 foreach(['pending','verified'] as $status){$ledger.='<tr data-status="'.$status.'">';foreach(['Church / reference'=>'Test parish '.$status,'Service period'=>'Nov 2026','Total offering · 100%'=>'₦100,000.00','Church retains'=>'₦20,000.00','Amount to pay'=>'₦80,000.00','Confirmed amount'=>'₦0.00','Status'=>ucfirst($status)] as $label=>$value)$ledger.='<td data-label="'.e($label).'">'.e($value).'</td>';$ledger.='</tr>';}
 $ledger.='</tbody></table></div><p id="ledger-no-results" hidden>No reports match your search.</p></div>';
 $fixture='<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head><body><aside class="sidebar">Desktop navigation test</aside><div class="workspace"><header class="topbar"><button type="button" class="mobile-menu-trigger icon-button" data-menu-open aria-controls="mobile-menu" aria-expanded="false" aria-label="Open navigation menu">☰</button><div class="breadcrumbs"><span class="breadcrumb-parent">Workspace / </span><strong>Overview</strong></div></header><main class="main"><p class="eyebrow">AUTOMATED TEST FIXTURE — NOT LIVE FINANCIAL DATA</p><h1>Good stewardship starts here.</h1>'.$dashboard.$ledger.'<form><label>Readable input<input id="test-input"></label></form><footer class="footer">Test footer</footer></main></div>'.$navigation.'</body></html>';
 file_put_contents($argv[2],$fixture);
}
echo "PASS: $checks mobile UI assertions for $role\n";
