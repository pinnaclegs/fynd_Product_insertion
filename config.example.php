<?php
// Copy this file to config.php and fill in environment-specific values.

$host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'localhost';
define('IS_LOCAL', $host === 'localhost' || $host === '127.0.0.1');

if (IS_LOCAL) {
    define('DB_HOST', 'localhost');
    define('DB_NAME', 'fynd');
    define('DB_USER', 'root');
    define('DB_PASS', '');
} else {
    define('DB_HOST', 'your-db-host');
    define('DB_NAME', 'your-db-name');
    define('DB_USER', 'your-db-user');
    define('DB_PASS', 'your-db-password');
}

define('EXTENSION_API_KEY',    'your-extension-api-key');
define('EXTENSION_API_SECRET', 'your-extension-api-secret');
define('FYND_COMPANY_ID',      'your-company-id');
define('FYND_API_BASE',        'https://api.fynd.com');
define('FYND_VERIFY_SSL',      !IS_LOCAL);
define('FYND_PRODUCT_CREATE_API_VERSION', 'v3.0');

define('APP_LIVE_URL',         'https://your-live-domain.example');
define('APP_LOCAL_URL',        'http://localhost/Apps/fynd-extension');
define('FYND_INSTALL_URL',     APP_LIVE_URL . '/fp/install');
define('FYND_CALLBACK_URL',    APP_LIVE_URL . '/fp/auth');
define('FYND_REDIRECT_URI',    FYND_CALLBACK_URL);
define('FYND_SCOPES',          'company/products/read,company/products/write,company/inventory/read,company/inventory/write');

define('FYND_FIXED_MAPPING_MODE',    true);
define('FYND_DEFAULT_BRAND_UID',     0);
define('FYND_DEFAULT_CATEGORY_SLUG', '');
define('FYND_DEFAULT_DEPARTMENT_UID', 0);
define('FYND_DEFAULT_TEMPLATE_TAG',  'supplementary');
define('FYND_DEFAULT_TAX_RULE_ID',   '');
define('FYND_TARGET_CATEGORY_NAME',  'Others');
define('FYND_AUTO_CREATE_TAX_RULE',  true);
define('FYND_ENABLE_CATEGORY_CANDIDATE_RETRY', false);
define('FYND_TAX_COMPONENT_NAME',    'GST');
define('FYND_TRADER_NAME',           'your-trader-name');
define('FYND_TRADER_TYPE',           'Manufacturer');
define('FYND_TRADER_ADDRESS',        'India');
define('VALIDATE_IMAGE_URL_REACHABILITY', false);
define('IMAGE_URL_CHECK_TIMEOUT_SECONDS', 5);
define('FYND_INGEST_BATCH_SIZE',     1);

function appFail($message) {
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/html; charset=utf-8');
    }

    die(
        '<h2 style="color:#dc3545">Application setup error</h2>' .
        '<pre style="white-space:pre-wrap;background:#f8f8f8;padding:12px;border:1px solid #ddd">' .
        htmlspecialchars($message, ENT_QUOTES, 'UTF-8') .
        '</pre>'
    );
}

function getDB() {
    static $pdo = null;
    if ($pdo === null) {
        if (!class_exists('PDO')) {
            appFail('The PDO PHP extension is not enabled on this server. Enable PDO and pdo_mysql.');
        }

        if (!in_array('mysql', PDO::getAvailableDrivers(), true)) {
            appFail('The pdo_mysql PHP extension is not enabled on this server. Enable pdo_mysql.');
        }

        try {
            $pdo = new PDO(
                'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8',
                DB_USER,
                DB_PASS,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );
        } catch (PDOException $e) {
            appFail('Database connection failed: ' . $e->getMessage());
        }
    }

    return $pdo;
}
