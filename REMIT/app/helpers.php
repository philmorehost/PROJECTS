<?php
declare(strict_types=1);
require_once __DIR__ . '/ChurchNames.php';
require_once __DIR__.'/PaymentProof.php';
require_once __DIR__.'/FinancialHistory.php';
require_once __DIR__.'/PasswordReset.php';
require_once __DIR__.'/RepresentativeManager.php';
require_once __DIR__.'/Impersonation.php';
function e(mixed $s): string { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function redirect(string $page): never { header('Location: index.php?page='.rawurlencode($page), true, 303); exit; }
function csrf(): string { return $_SESSION['csrf'] ??= bin2hex(random_bytes(32)); }
function checkCsrf(): void { if (!hash_equals(csrf(), (string)($_POST['csrf'] ?? ''))) { http_response_code(403); throw new RuntimeException('Your session expired. Refresh and try again.'); } }
function setting(PDO $db, string $key): string { $s=$db->prepare('SELECT setting_value FROM security_settings WHERE setting_key=?'); $s->execute([$key]); return (string)$s->fetchColumn(); }
function audit(PDO $db, string $type, ?string $email=null, string $details=''): void { $db->prepare('INSERT INTO security_audit_logs(ip_address,username_attempted,event_type,details) VALUES(?,?,?,?)')->execute([Security::ip(),$email,$type,$details]); }
function notify(PDO $db, string $email, string $subject, string $body): void { if (filter_var($email,FILTER_VALIDATE_EMAIL)) $db->prepare('INSERT INTO notification_outbox(recipient,subject,body) VALUES(?,?,?)')->execute([$email,$subject,$body]); }
function canRemit(array $user): bool { return in_array($user['role'],['parish_officer','area_rep','zonal_rep'],true); }
function remittingUnit(PDO $db,array $user): int {
 requireRole($user,['parish_officer','area_rep','zonal_rep']);
 $id=(int)($user['hierarchy_id'] ?? 0);$s=$db->prepare('SELECT node_type FROM church_hierarchy WHERE id=?');$s->execute([$id]);
 $expected=['parish_officer'=>'parish','area_rep'=>'area','zonal_rep'=>'zone'];
 if(!$id || $s->fetchColumn()!==$expected[$user['role']])throw new RuntimeException('Your account needs a valid church assignment. Contact the administrator.');
 return $id;
}
function financialScope(PDO $db,array $user): array {
 if(in_array($user['role'],['provincial_admin','provincial_treasurer'],true))return array_map('intval',$db->query('SELECT id FROM church_hierarchy')->fetchAll(PDO::FETCH_COLUMN));
 if(!canRemit($user))return [];
 $id=remittingUnit($db,$user);
 $s=$db->prepare('WITH RECURSIVE tree AS (SELECT id FROM church_hierarchy WHERE id=? UNION ALL SELECT h.id FROM church_hierarchy h JOIN tree t ON h.parent_id=t.id) SELECT id FROM tree');$s->execute([$id]);return array_map('intval',$s->fetchAll(PDO::FETCH_COLUMN));
}
function scope(PDO $db, array $user): array {
 if (in_array($user['role'],['provincial_admin','provincial_treasurer'],true)) return array_map('intval',$db->query("SELECT id FROM church_hierarchy WHERE node_type='parish'")->fetchAll(PDO::FETCH_COLUMN));
 $s=$db->prepare('WITH RECURSIVE tree AS (SELECT id,node_type FROM church_hierarchy WHERE id=? UNION ALL SELECT h.id,h.node_type FROM church_hierarchy h JOIN tree t ON h.parent_id=t.id) SELECT id FROM tree WHERE node_type=\'parish\'');
 $s->execute([$user['hierarchy_id']]); return array_map('intval',$s->fetchAll(PDO::FETCH_COLUMN));
}
function requireRole(array $user, array $roles): void { if (!in_array($user['role'],$roles,true)) { http_response_code(403); throw new RuntimeException('You do not have permission for this action.'); } }

function unitLabel(array $node): string {
 $parts=[ucfirst($node['node_type']).' · '.$node['node_name']];
 if(!empty($node['parish_code']))$parts[]=$node['parish_code'];
 if(!empty($node['parent_name']))$parts[]='Under '.$node['parent_name'];
 if(!empty($node['zone_name']) && $node['zone_name']!==($node['parent_name'] ?? '') && $node['node_type']!=='zone')$parts[]='Zone: '.$node['zone_name'];
 return implode(' — ',$parts);
}
