<?php
declare(strict_types=1);
const ROOT = __DIR__ . '/..';
ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
session_name('remit_session');
session_set_cookie_params(['httponly'=>true, 'secure'=>!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off', 'samesite'=>'Lax', 'path'=>rtrim(dirname($_SERVER['SCRIPT_NAME']), '/') . '/']);
session_start();
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header("Content-Security-Policy: default-src 'self'; style-src 'self'; script-src 'self'; img-src 'self'; base-uri 'none'; frame-ancestors 'none'; form-action 'self'");
require __DIR__.'/Database.php';
require __DIR__.'/Money.php';
require __DIR__.'/Security.php';
function e(mixed $s): string { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function redirect(string $page): never { header('Location: index.php?page='.rawurlencode($page), true, 303); exit; }
function csrf(): string { return $_SESSION['csrf'] ??= bin2hex(random_bytes(32)); }
function checkCsrf(): void { if (!hash_equals(csrf(), (string)($_POST['csrf'] ?? ''))) { http_response_code(403); throw new RuntimeException('Your session expired. Refresh and try again.'); } }
function setting(PDO $db, string $key): string { $s=$db->prepare('SELECT setting_value FROM security_settings WHERE setting_key=?'); $s->execute([$key]); return (string)$s->fetchColumn(); }
function audit(PDO $db, string $type, ?string $email=null, string $details=''): void { $db->prepare('INSERT INTO security_audit_logs(ip_address,username_attempted,event_type,details) VALUES(?,?,?,?)')->execute([Security::ip(),$email,$type,$details]); }
function notify(PDO $db, string $email, string $subject, string $body): void { if (filter_var($email,FILTER_VALIDATE_EMAIL)) $db->prepare('INSERT INTO notification_outbox(recipient,subject,body) VALUES(?,?,?)')->execute([$email,$subject,$body]); }
function scope(PDO $db, array $user): array {
 if (in_array($user['role'],['provincial_admin','provincial_treasurer'],true)) return array_map('intval',$db->query("SELECT id FROM church_hierarchy WHERE node_type='parish'")->fetchAll(PDO::FETCH_COLUMN));
 $s=$db->prepare('WITH RECURSIVE tree AS (SELECT id,node_type FROM church_hierarchy WHERE id=? UNION ALL SELECT h.id,h.node_type FROM church_hierarchy h JOIN tree t ON h.parent_id=t.id) SELECT id FROM tree WHERE node_type=\'parish\'');
 $s->execute([$user['hierarchy_id']]); return array_map('intval',$s->fetchAll(PDO::FETCH_COLUMN));
}
function requireRole(array $user, array $roles): void { if (!in_array($user['role'],$roles,true)) { http_response_code(403); throw new RuntimeException('You do not have permission for this action.'); } }
