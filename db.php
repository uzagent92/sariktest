<?php
// db.php
$config = require __DIR__ . '/config.php';
$dbcfg = $config['db'];

$DSN = "mysql:host={$dbcfg['host']};dbname={$dbcfg['name']};charset={$dbcfg['charset']}";
try {
    $pdo = new PDO($DSN, $dbcfg['user'], $dbcfg['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
} catch (Exception $e) {
    error_log("DB connection failed: " . $e->getMessage());
    http_response_code(500);
    echo "Database connection error";
    exit;
}
