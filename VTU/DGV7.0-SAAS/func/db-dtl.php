<?php
	// Written per-installation by the install wizard; never shipped with the code.
	// Defaults keep a missing file a clean "no connection" (handled by the caller)
	// instead of include warnings and null-offset notices on every page.
	$db_json_decode = array('server' => 'localhost', 'user' => '', 'pass' => '', 'dbname' => '');
	if (file_exists(__DIR__ . "/db-json.php")) {
	    include(__DIR__ . "/db-json.php");
	}
	$mySqlServer = $db_json_decode["server"] ?? 'localhost';
	$mySqlUser = $db_json_decode["user"] ?? '';
	$mySqlPass = $db_json_decode["pass"] ?? '';
	$mySqlDBName = $db_json_decode["dbname"] ?? '';
