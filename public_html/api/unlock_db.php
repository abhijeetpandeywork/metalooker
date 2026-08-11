<?php
header('Content-Type: application/json');
$dirs = [
    dirname(__DIR__, 2) . '/db',
    __DIR__ . '/../includes/storage'
];

foreach ($dirs as $dbDir) {
    if (is_dir($dbDir)) {
        @chmod($dbDir, 0777);
        foreach (glob($dbDir . '/*') as $f) {
            @chmod($f, 0777);
            if (str_contains($f, '-journal') || str_contains($f, '-shm') || str_contains($f, '-wal')) {
                @unlink($f);
            }
        }
    }
}

require_once __DIR__ . '/../includes/db.php';
$mysqlError = null;
$mysqlOk = false;
try {
    $db = Database::getInstance();
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

if (isset($_GET['update_admin']) && $_GET['update_admin'] === '1') {
    try {
        $db = Database::getInstance();
        $newEmail = 'info@digitalrubix.com';
        $newPass  = 'Abhijeet@1998';
        $hash     = password_hash($newPass, PASSWORD_BCRYPT, ['cost' => 12]);

        $stmt = $db->prepare("UPDATE users SET email = ?, password_hash = ? WHERE role = 'super_admin' OR email = 'admin@digitalrubix.com' OR email = ?");
        $stmt->execute([$newEmail, $hash, $newEmail]);

        $users = $db->query("SELECT id, name, email, role FROM users")->fetchAll();
        echo json_encode(['admin_updated' => true, 'affected_rows' => $stmt->rowCount(), 'hash' => $hash, 'users' => $users], JSON_PRETTY_PRINT);
        exit;
    } catch (Exception $eUp) {
        echo json_encode(['error' => $eUp->getMessage()]);
        exit;
    }
}

echo json_encode([
    'mysql_connected' => $mysqlOk,
    'mysql_error' => $mysqlError,
    'sqlite_exists' => file_exists($dbDir . '/metapanel.sqlite')
]);
