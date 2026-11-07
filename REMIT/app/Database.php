<?php
declare(strict_types=1);
final class Database {
 public static function connect(array $c): PDO {
  if (!preg_match('/^[a-zA-Z0-9_]+$/', $c['name']) || !preg_match('/^[a-zA-Z0-9.:-]+$/',$c['host'])) throw new RuntimeException('Invalid database host or name.');
  return new PDO('mysql:host='.$c['host'].';dbname='.$c['name'].';charset=utf8mb4',$c['user'],$c['password'],[
   PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
   PDO::ATTR_EMULATE_PREPARES=>false,PDO::ATTR_PERSISTENT=>true]);
 }
 public static function get(): PDO { static $db; return $db ??= self::connect(require ROOT.'/config/database.php'); }
}
