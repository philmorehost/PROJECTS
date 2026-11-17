<?php
// php-version/db.php

class Database {
    private static $pdo = null;

    public static function connect() {
        if (self::$pdo === null) {
            if (!file_exists(__DIR__ . '/config.php')) {
                header("Location: " . BASE_URL . "install/index.php");
                exit;
            }
            require_once __DIR__ . '/config.php';
            try {
                self::$pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME, DB_USER, DB_PASS);
                self::$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                self::$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            } catch (PDOException $e) {
                die("Database connection failed: " . $e->getMessage());
            }
        }
        return self::$pdo;
    }
}
