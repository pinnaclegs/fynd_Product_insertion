<?php
error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('log_errors', '1');

require_once 'config.php';

function setupStep($db, $label, $sql) {
    try {
        $db->exec($sql);
        echo '<li style="color:#28a745">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . ' created/verified</li>';
    } catch (PDOException $e) {
        appFail($label . ' table failed: ' . $e->getMessage());
    }
}

echo '<!DOCTYPE html><html><head><title>Fynd Extension Setup</title></head>';
echo '<body style="font-family:Arial,sans-serif;max-width:820px;margin:40px auto;padding:0 20px">';
echo '<h1>Fynd Extension Setup</h1>';
echo '<p><strong>PHP:</strong> ' . htmlspecialchars(PHP_VERSION, ENT_QUOTES, 'UTF-8') . '</p>';
echo '<p><strong>Environment:</strong> ' . (IS_LOCAL ? 'Local' : 'Live') . '</p>';
echo '<p><strong>Database:</strong> ' . htmlspecialchars(DB_NAME, ENT_QUOTES, 'UTF-8') . '</p>';

$db = getDB();

echo '<h2>Tables</h2><ul>';

setupStep($db, 'products', "
    CREATE TABLE IF NOT EXISTS products (
        id INT AUTO_INCREMENT PRIMARY KEY,
        sku VARCHAR(100) NOT NULL UNIQUE,
        name VARCHAR(255) NOT NULL,
        data LONGTEXT NOT NULL,
        status ENUM('pending', 'ingested', 'failed') DEFAULT 'pending',
        error_message TEXT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )
");

setupStep($db, 'ingest_log', "
    CREATE TABLE IF NOT EXISTS ingest_log (
        id INT AUTO_INCREMENT PRIMARY KEY,
        sku VARCHAR(100) NOT NULL,
        action VARCHAR(50) NOT NULL,
        message TEXT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )
");

setupStep($db, 'validation_errors', "
    CREATE TABLE IF NOT EXISTS validation_errors (
        id INT AUTO_INCREMENT PRIMARY KEY,
        `row_number` INT NOT NULL,
        sku VARCHAR(100),
        error_type VARCHAR(100) NOT NULL,
        error_message TEXT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )
");

setupStep($db, 'fynd_oauth_tokens', "
    CREATE TABLE IF NOT EXISTS fynd_oauth_tokens (
        id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
        company_id VARCHAR(32) NOT NULL,
        access_token TEXT NOT NULL,
        refresh_token TEXT NULL,
        token_type VARCHAR(40) NULL,
        scope TEXT NULL,
        expires_at INT NULL,
        raw_response LONGTEXT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )
");

setupStep($db, 'fynd_oauth_states', "
    CREATE TABLE IF NOT EXISTS fynd_oauth_states (
        state VARCHAR(128) NOT NULL PRIMARY KEY,
        company_id VARCHAR(32) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        used_at TIMESTAMP NULL
    )
");

setupStep($db, 'catalog_settings', "
    CREATE TABLE IF NOT EXISTS catalog_settings (
        `key` VARCHAR(100) NOT NULL PRIMARY KEY,
        `value` TEXT NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )
");

echo '</ul>';
echo '<h2 style="color:#28a745">Database tables created successfully.</h2>';
echo '<p><a href="index.php">Go to Dashboard</a></p>';
echo '</body></html>';
