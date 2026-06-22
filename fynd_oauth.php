<?php
require_once 'config.php';

const FYND_TOKEN_DEBUG_FILE = __DIR__ . '/token_debug.txt';

function ensureFyndTokenTable() {
    $db = getDB();
    $db->exec("
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
}

function ensureFyndOAuthStateTable() {
    $db = getDB();
    $db->exec("
        CREATE TABLE IF NOT EXISTS fynd_oauth_states (
            state VARCHAR(128) NOT NULL PRIMARY KEY,
            company_id VARCHAR(32) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            used_at TIMESTAMP NULL
        )
    ");
}

function fyndSafeRandomToken($length = 32) {
    if (function_exists('random_bytes')) {
        return bin2hex(random_bytes($length));
    }

    if (function_exists('openssl_random_pseudo_bytes')) {
        return bin2hex(openssl_random_pseudo_bytes($length));
    }

    return sha1(uniqid('', true) . mt_rand());
}

function fyndScopeString() {
    return FYND_SCOPES;
}

function normalizeFyndBaseUrl($clusterUrl = '') {
    $clusterUrl = trim($clusterUrl);
    if ($clusterUrl === '') {
        return rtrim(FYND_API_BASE, '/');
    }

    if (strpos($clusterUrl, 'http://') !== 0 && strpos($clusterUrl, 'https://') !== 0) {
        $clusterUrl = 'https://' . $clusterUrl;
    }

    return rtrim($clusterUrl, '/');
}

function fyndCurrentCompanyId() {
    if (!empty($_GET['company_id'])) {
        return trim($_GET['company_id']);
    }

    if (!empty($_POST['company_id'])) {
        return trim($_POST['company_id']);
    }

    return FYND_COMPANY_ID;
}

function saveFyndOAuthState($state, $companyId) {
    ensureFyndOAuthStateTable();

    $stmt = getDB()->prepare("
        INSERT INTO fynd_oauth_states (state, company_id)
        VALUES (?, ?)
        ON DUPLICATE KEY UPDATE company_id = VALUES(company_id), created_at = CURRENT_TIMESTAMP, used_at = NULL
    ");
    $stmt->execute([$state, $companyId]);
}

function createFyndOAuthState($companyId) {
    $state = fyndSafeRandomToken(24);
    saveFyndOAuthState($state, $companyId);

    return $state;
}

function consumeFyndOAuthState($state, $companyId) {
    ensureFyndOAuthStateTable();

    if ($state === '') {
        return ['ok' => false, 'error' => 'Missing OAuth state. Start the flow from /fp/install or the Start Fynd OAuth link.'];
    }

    $stmt = getDB()->prepare("SELECT * FROM fynd_oauth_states WHERE state = ? AND company_id = ? AND used_at IS NULL");
    $stmt->execute([$state, $companyId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        return ['ok' => false, 'error' => 'OAuth state was not found or was already used. Start the OAuth flow again.'];
    }

    $createdAt = strtotime($row['created_at']);
    if ($createdAt && $createdAt < time() - 3600) {
        return ['ok' => false, 'error' => 'OAuth state has expired. Start the OAuth flow again.'];
    }

    $markUsed = getDB()->prepare("UPDATE fynd_oauth_states SET used_at = CURRENT_TIMESTAMP WHERE state = ?");
    $markUsed->execute([$state]);

    return ['ok' => true, 'error' => ''];
}

function fyndAuthorizationUrl($companyId = null, $clusterUrl = '') {
    $companyId = $companyId ? $companyId : FYND_COMPANY_ID;
    $state = createFyndOAuthState($companyId);
    $baseUrl = normalizeFyndBaseUrl($clusterUrl);

    return $baseUrl . '/service/panel/authentication/v1.0/company/' . rawurlencode($companyId) . '/oauth/authorize?' .
        http_build_query([
            'client_id'     => EXTENSION_API_KEY,
            'redirect_uri'  => FYND_REDIRECT_URI,
            'response_type' => 'code',
            'scope'         => fyndScopeString(),
            'state'         => $state
        ]);
}

function fyndOfflineTokenEndpointUrl($companyId = null) {
    $companyId = $companyId ? $companyId : FYND_COMPANY_ID;

    return FYND_API_BASE . '/service/panel/authentication/v1.0/company/' . $companyId . '/oauth/offline-token';
}

function getFyndAuthorizationCodeFromRequest() {
    $keys = ['code', 'authorization_code', 'auth_code', 'oauth_code'];

    foreach ($keys as $key) {
        if (isset($_GET[$key]) && trim($_GET[$key]) !== '') {
            return trim($_GET[$key]);
        }
        if (isset($_POST[$key]) && trim($_POST[$key]) !== '') {
            return trim($_POST[$key]);
        }
    }

    return '';
}

function redactFyndTokenPayload($payload) {
    $data = json_decode($payload, true);
    if (!is_array($data)) {
        return $payload;
    }

    foreach (['access_token', 'refresh_token', 'id_token'] as $field) {
        if (!empty($data[$field])) {
            $data[$field] = '[redacted]';
        }
    }

    return json_encode($data);
}

function redactFyndRequestData($data) {
    $safe = [];
    $redactKeys = ['code', 'authorization_code', 'auth_code', 'oauth_code', 'access_token', 'refresh_token', 'id_token'];

    foreach ($data as $key => $value) {
        if (in_array($key, $redactKeys)) {
            $safe[$key] = '[redacted]';
        } elseif (is_array($value)) {
            $safe[$key] = redactFyndRequestData($value);
        } else {
            $safe[$key] = $value;
        }
    }

    return $safe;
}

function writeFyndTokenDebug($context, $url, $httpCode, $curlError, $response, $requestFields = []) {
    $safeFields = $requestFields;
    foreach (['code', 'authorization_code', 'auth_code', 'oauth_code', 'refresh_token', 'client_secret'] as $field) {
        if (!empty($safeFields[$field])) {
            $safeFields[$field] = '[redacted]';
        }
    }

    file_put_contents(
        FYND_TOKEN_DEBUG_FILE,
        date('Y-m-d H:i:s') . "\n" .
        "Context: $context\n" .
        "URL: $url\n" .
        "HTTP Code: $httpCode\n" .
        "cURL Error: $curlError\n" .
        "Request: " . json_encode($safeFields) . "\n" .
        "Response: " . redactFyndTokenPayload($response) . "\n\n"
    );
}

function validateFyndOAuthCallback($companyId = null) {
    $companyId = $companyId ? $companyId : fyndCurrentCompanyId();

    if (!empty($_GET['client_id']) && $_GET['client_id'] !== EXTENSION_API_KEY) {
        return ['ok' => false, 'error' => 'OAuth callback client_id does not match this extension API key.'];
    }

    if (!empty($_GET['company_id']) && trim($_GET['company_id']) !== (string) $companyId) {
        return ['ok' => false, 'error' => 'OAuth callback company_id does not match the expected company.'];
    }

    $state = isset($_GET['state']) ? trim($_GET['state']) : '';

    return consumeFyndOAuthState($state, $companyId);
}

function requestFyndOfflineToken($fields, $context, $companyId = null) {
    $url = fyndOfflineTokenEndpointUrl($companyId);
    $credentials = base64_encode(EXTENSION_API_KEY . ':' . EXTENSION_API_SECRET);
    $payload = json_encode($fields);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Basic ' . $credentials
        ],
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_SSL_VERIFYPEER => defined('FYND_VERIFY_SSL') ? FYND_VERIFY_SSL : true,
        CURLOPT_TIMEOUT        => 30
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    writeFyndTokenDebug($context, $url, $httpCode, $curlError, $response ?: '', $fields);

    $data = json_decode($response ?: '', true);
    if ($httpCode < 200 || $httpCode >= 300 || empty($data['access_token'])) {
        return null;
    }

    return $data;
}

function getJwtExpiry($token) {
    $parts = explode('.', $token);
    if (count($parts) < 2) {
        return null;
    }

    $payload = strtr($parts[1], '-_', '+/');
    $payload .= str_repeat('=', (4 - strlen($payload) % 4) % 4);
    $data = json_decode(base64_decode($payload), true);

    return isset($data['exp']) ? intval($data['exp']) : null;
}

function getFyndTokenExpiry($tokenData) {
    if (!empty($tokenData['expires_at']) && is_numeric($tokenData['expires_at'])) {
        return intval($tokenData['expires_at']);
    }

    if (!empty($tokenData['expires_in'])) {
        return time() + intval($tokenData['expires_in']);
    }

    if (!empty($tokenData['access_token'])) {
        $jwtExpiry = getJwtExpiry($tokenData['access_token']);
        if ($jwtExpiry) {
            return $jwtExpiry;
        }
    }

    return time() + 3600;
}

function saveFyndTokenResponse($tokenData, $companyId = null, $fallbackRefreshToken = null) {
    ensureFyndTokenTable();

    $companyId = $companyId ? $companyId : FYND_COMPANY_ID;
    $refreshToken = isset($tokenData['refresh_token']) ? $tokenData['refresh_token'] : $fallbackRefreshToken;
    $stmt = getDB()->prepare("
        INSERT INTO fynd_oauth_tokens
            (id, company_id, access_token, refresh_token, token_type, scope, expires_at, raw_response)
        VALUES
            (1, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            company_id = VALUES(company_id),
            access_token = VALUES(access_token),
            refresh_token = VALUES(refresh_token),
            token_type = VALUES(token_type),
            scope = VALUES(scope),
            expires_at = VALUES(expires_at),
            raw_response = VALUES(raw_response)
    ");

    $stmt->execute([
        $companyId,
        $tokenData['access_token'],
        $refreshToken,
        isset($tokenData['token_type']) ? $tokenData['token_type'] : 'Bearer',
        isset($tokenData['scope']) ? $tokenData['scope'] : null,
        getFyndTokenExpiry($tokenData),
        json_encode($tokenData)
    ]);
}

function getStoredFyndTokenRow($companyId = null) {
    ensureFyndTokenTable();

    $companyId = $companyId ? $companyId : FYND_COMPANY_ID;
    $stmt = getDB()->prepare("SELECT * FROM fynd_oauth_tokens WHERE id = 1 AND company_id = ?");
    $stmt->execute([$companyId]);

    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function isFyndAccessTokenFresh($row) {
    if (empty($row['access_token']) || empty($row['expires_at'])) {
        return false;
    }

    return intval($row['expires_at']) > time() + 120;
}

function hasStoredFyndAccessToken($row) {
    return is_array($row) && !empty($row['access_token']);
}

function exchangeFyndAuthorizationCode($code, $companyId = null) {
    $fields = [
        'grant_type'    => 'authorization_code',
        'code'          => $code,
        'client_id'     => EXTENSION_API_KEY,
        'client_secret' => EXTENSION_API_SECRET,
        'scope'         => fyndScopeString()
    ];

    return requestFyndOfflineToken($fields, 'offline_authorization_code', $companyId);
}

function refreshFyndAccessToken($refreshToken, $companyId = null) {
    $fields = [
        'grant_type'    => 'refresh_token',
        'refresh_token' => $refreshToken,
        'client_id'     => EXTENSION_API_KEY,
        'client_secret' => EXTENSION_API_SECRET,
        'scope'         => fyndScopeString()
    ];

    return requestFyndOfflineToken($fields, 'offline_refresh_token', $companyId);
}

function getFyndAccessToken($authorizationCode = null, $companyId = null, $allowStaleStoredTokenFallback = true) {
    $companyId = $companyId ? $companyId : FYND_COMPANY_ID;
    $row = getStoredFyndTokenRow($companyId);
    if (isFyndAccessTokenFresh($row)) {
        writeFyndTokenDebug('stored_token', fyndOfflineTokenEndpointUrl($companyId), 200, '', '{"status":"stored token is still valid"}');
        return $row['access_token'];
    }

    $attemptedRemoteAuth = false;

    $code = trim($authorizationCode !== null ? $authorizationCode : getFyndAuthorizationCodeFromRequest());
    if ($code !== '') {
        $attemptedRemoteAuth = true;
        $tokenData = exchangeFyndAuthorizationCode($code, $companyId);
        if ($tokenData) {
            saveFyndTokenResponse($tokenData, $companyId);
            return $tokenData['access_token'];
        }
    }

    if ($allowStaleStoredTokenFallback && hasStoredFyndAccessToken($row)) {
        writeFyndTokenDebug(
            'stored_token_stale_fallback',
            fyndOfflineTokenEndpointUrl($companyId),
            200,
            '',
            '{"status":"using stored access token because Fynd offline-token endpoint requires a fresh authorization code"}'
        );
        return $row['access_token'];
    }

    if ($attemptedRemoteAuth) {
        return null;
    }

    writeFyndTokenDebug(
        'missing_authorization',
        fyndOfflineTokenEndpointUrl($companyId),
        0,
        'No stored token, refresh token, or Fynd authorization code is available.',
        '{"error":"Open /fp/install or Start Fynd OAuth so Fynd redirects back to /fp/auth with ?code=..."}'
    );

    return null;
}
