<?php
header('Content-Type: application/json');
$dbDir = dirname(__DIR__, 2) . '/db';
$j = @unlink($dbDir . '/metapanel.sqlite-journal');
$s = @unlink($dbDir . '/metapanel.sqlite-shm');
$w = @unlink($dbDir . '/metapanel.sqlite-wal');

@chmod($dbDir . '/metapanel.sqlite', 0777);
@chmod($dbDir, 0777);

require_once __DIR__ . '/../includes/config.php';
$mysqlError = null;
$mysqlOk = false;
try {
    $dsn = sprintf("mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4", DB_HOST, DB_PORT, DB_NAME);
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [PDO::ATTR_TIMEOUT => 5]);
    $mysqlOk = true;
} catch (Exception $e) {
    $mysqlOk = false;
    $mysqlError = $e->getMessage();
}

// Reset SQLite db if requested
if (isset($_GET['reset_sqlite']) && $_GET['reset_sqlite'] === '1') {
    @unlink($dbDir . '/metapanel.sqlite');
    @unlink($dbDir . '/metapanel.sqlite-journal');
    @unlink($dbDir . '/metapanel.sqlite-shm');
    @unlink($dbDir . '/metapanel.sqlite-wal');
}

echo json_encode([
    'unlocked_locks' => ($j || $s || $w),
    'mysql_connected' => $mysqlOk,
    'mysql_error' => $mysqlError,
    'sqlite_exists' => file_exists($dbDir . '/metapanel.sqlite')
]);
