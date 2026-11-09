<?php
// php-version/logout.php
session_start();
session_destroy();
header("Location: login.php");
exit;
