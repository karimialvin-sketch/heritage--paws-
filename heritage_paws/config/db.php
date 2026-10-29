<?php
// Database connection settings - edit these to match your MySQL Workbench server.
define('DB_HOST', 'localhost');
define('DB_NAME', 'heritage_paws');
define('DB_USER', 'root');
define('DB_PASS', 'Zxcvbnm.5');          // put your MySQL root password here
define('SITE_NAME', 'Heritage Paws');

// Folder where dog photos are stored (relative to project root)
define('UPLOAD_DIR', __DIR__ . '/../uploads/');
define('UPLOAD_URL', '/heritage_paws/uploads/');   // adjust if your folder name/path differs
define('BASE_URL',  '/heritage_paws');              // adjust if your folder name/path differs

try {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );
} catch (PDOException $e) {
    die('Database connection failed. Check config/db.php. ' . htmlspecialchars($e->getMessage()));
}

