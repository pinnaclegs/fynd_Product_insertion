<?php
require_once 'config.php';
require_once 'fynd_oauth.php';
require_once 'fynd_catalog.php';

function formatIngestDebugJson($value) {
    if (is_string($value)) {
        $decoded = json_decode($value, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            $value = $decoded;
        } else {
            return $value;
        }
    }

    return json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
}

function fyndProductCreateEndpoint() {
    return FYND_API_BASE . '/service/platform/catalog/' . FYND_PRODUCT_CREATE_API_VERSION . '/company/' . FYND_COMPANY_ID . '/products/';
}

function postFyndProductPayload($product, $token, $label) {
    $url = fyndProductCreateEndpoint();
    $requestPayload = json_encode($product);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $token
        ],
        CURLOPT_POSTFIELDS     => $requestPayload,
        CURLOPT_SSL_VERIFYPEER => defined('FYND_VERIFY_SSL') ? FYND_VERIFY_SSL : true,
        CURLOPT_TIMEOUT        => 30
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    file_put_contents(__DIR__ . '/ingest_debug.txt',
        date('Y-m-d H:i:s') . "\n" .
        "Label: $label\n" .
        "SKU: " . $product['item_code'] . "\n" .
        "Method: POST\n" .
        "Endpoint: $url\n" .
        "Request Headers: " . formatIngestDebugJson([
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer [redacted]'
        ]) . "\n" .
        "Request Payload: " . formatIngestDebugJson($product) . "\n" .
        "HTTP Code: $httpCode\n" .
        "cURL Error: $curlError\n" .
        "Response Body: " . formatIngestDebugJson($response ?: '') . "\n\n",
        FILE_APPEND
    );

    return [
        'code'  => $httpCode,
        'body'  => json_decode($response, true),
        'error' => $curlError
    ];
}

// ─── Create Product on Fynd ───────────────────────────────────────────────────
function createFyndProduct($product, $token) {
    $sourceProduct = $product;
    $prepared = prepareFyndProductV3($product, $token);
    if (!$prepared['ok']) {
        $url = fyndProductCreateEndpoint();
        file_put_contents(__DIR__ . '/ingest_debug.txt',
            date('Y-m-d H:i:s') . "\n" .
            "Label: metadata_validation\n" .
            "SKU: " . $product['item_code'] . "\n" .
            "Method: POST\n" .
            "Endpoint: $url\n" .
            "Request Status: not sent to Fynd Catalog API because metadata validation failed\n" .
            "Request Headers: " . formatIngestDebugJson([
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer [redacted]'
            ]) . "\n" .
            "Request Payload: not built; required metadata is missing\n" .
            "HTTP Code: 0\n" .
            "cURL Error: \n" .
            "Response Body: not available; request skipped before Fynd call\n" .
            "Metadata Error: " . $prepared['error'] . "\n\n",
            FILE_APPEND
        );

        return [
            'code'  => 0,
            'body'  => ['message' => $prepared['error']],
            'error' => $prepared['error']
        ];
    }

    $product = $prepared['product'];
    $result = postFyndProductPayload($product, $token, 'create_product');

    if (fyndInvalidProductDescription($result)) {
        $result = retryFyndProductWithDescriptionFallbacks($product, $token, $result);
    }

    if (defined('FYND_ENABLE_CATEGORY_CANDIDATE_RETRY') && FYND_ENABLE_CATEGORY_CANDIDATE_RETRY && fyndCategoryError($result)) {
        $result = retryFyndProductWithCategoryCandidates($sourceProduct, $prepared['product'], $token, $result);
    }

    return $result;
}

function retryFyndProductWithDescriptionFallbacks($product, $token, $lastResult) {
    $safeName = isset($product['name']) ? trim((string) $product['name']) : 'Product';
    if ($safeName === '') {
        $safeName = 'Product';
    }

    $variants = [];

    $safeBoth = $product;
    $safeBoth['description'] = $safeName;
    $safeBoth['short_description'] = substr($safeName, 0, 50);
    $variants[] = ['label' => 'create_product_with_safe_description', 'payload' => $safeBoth];

    $descOnly = $product;
    $descOnly['description'] = $safeName;
    unset($descOnly['short_description']);
    $variants[] = ['label' => 'create_product_with_description_only', 'payload' => $descOnly];

    $shortOnly = $product;
    unset($shortOnly['description']);
    $shortOnly['short_description'] = substr($safeName, 0, 50);
    $variants[] = ['label' => 'create_product_with_short_description_only', 'payload' => $shortOnly];

    $neither = $product;
    unset($neither['description'], $neither['short_description']);
    $variants[] = ['label' => 'create_product_without_description_fields', 'payload' => $neither];

    foreach ($variants as $variant) {
        $result = postFyndProductPayload($variant['payload'], $token, $variant['label']);
        if ($result['code'] >= 200 && $result['code'] < 300) {
            return $result;
        }
        if (!fyndInvalidProductDescription($result)) {
            return $result;
        }
        $lastResult = $result;
    }

    return $lastResult;
}

function fyndInvalidProductDescription($result) {
    if (!is_array($result) || !isset($result['body']) || !is_array($result['body'])) {
        return false;
    }

    $message = isset($result['body']['message']) ? strtolower((string) $result['body']['message']) : '';
    return strpos($message, 'invalid product description') !== false;
}

function fyndProductAlreadyExists($result) {
    if (!is_array($result) || !isset($result['body']) || !is_array($result['body'])) {
        return false;
    }

    $message = isset($result['body']['message']) ? strtolower((string) $result['body']['message']) : '';
    return strpos($message, 'product already exists') !== false;
}

function fyndCategoryError($result) {
    if (!is_array($result) || !isset($result['body']) || !is_array($result['body'])) {
        return false;
    }

    $message = isset($result['body']['message']) ? strtolower((string) $result['body']['message']) : '';
    return strpos($message, 'category:') !== false || strpos($message, 'invalid category') !== false || strpos($message, 'category not found') !== false;
}

function retryFyndProductWithCategoryCandidates($sourceProduct, $preparedPayload, $token, $lastResult) {
    $departmentUid = isset($preparedPayload['departments'][0]) ? intval($preparedPayload['departments'][0]) : 0;
    $categoryName = isset($sourceProduct['legacy_category_name']) ? $sourceProduct['legacy_category_name'] : '';
    $preferredTemplateTag = isset($preparedPayload['template_tag']) ? $preparedPayload['template_tag'] : '';
    $currentPair = strtolower($preparedPayload['category_slug'] . '|' . $preparedPayload['template_tag']);

    $brandUid = isset($preparedPayload['brand_uid']) ? intval($preparedPayload['brand_uid']) : 0;
    $candidates = listFyndCategoryTemplateCandidates($departmentUid, $categoryName, $preferredTemplateTag, $token, $brandUid);
    file_put_contents(__DIR__ . '/ingest_debug.txt',
        date('Y-m-d H:i:s') . "\n" .
        "Label: category_candidate_retry_start\n" .
        "SKU: " . $preparedPayload['item_code'] . "\n" .
        "Candidate Count: " . count($candidates) . "\n" .
        "Initial Error: " . json_encode($lastResult['body']) . "\n\n",
        FILE_APPEND
    );

    foreach ($candidates as $candidate) {
        $candidatePair = strtolower($candidate['category_slug'] . '|' . $candidate['template_tag']);
        if ($candidatePair === $currentPair) {
            continue;
        }

        $retryPayload = $preparedPayload;
        $retryPayload['category_slug'] = $candidate['category_slug'];
        $retryPayload['template_tag'] = $candidate['template_tag'];
        $mandatoryAttributes = buildFyndMandatoryAttributes($candidate['category_slug'], $sourceProduct, $token);
        if (!empty($mandatoryAttributes)) {
            $retryPayload['attributes'] = $mandatoryAttributes;
        } else {
            unset($retryPayload['attributes']);
        }

        $result = postFyndProductPayload($retryPayload, $token, 'create_product_category_candidate_' . $candidate['source']);
        if (($result['code'] >= 200 && $result['code'] < 300) || fyndProductAlreadyExists($result)) {
            return $result;
        }

        $lastResult = $result;
        usleep(210000);
    }

    return $lastResult;
}

function runFyndPayloadPreflight($products, $token) {
    $result = [
        'total' => count($products),
        'ok' => 0,
        'failed' => 0,
        'items' => []
    ];

    foreach ($products as $p) {
        $productData = json_decode($p['data'], true);
        if (!is_array($productData)) {
            $result['failed']++;
            $result['items'][] = [
                'sku' => $p['sku'],
                'name' => $p['name'],
                'ok' => false,
                'message' => 'Stored product JSON is invalid.',
                'summary' => ''
            ];
            continue;
        }

        $prepared = prepareFyndProductV3($productData, $token);
        if (!$prepared['ok']) {
            $result['failed']++;
            $result['items'][] = [
                'sku' => $p['sku'],
                'name' => $p['name'],
                'ok' => false,
                'message' => $prepared['error'],
                'summary' => ''
            ];
            continue;
        }

        $payload = $prepared['product'];
        $attributeKeys = isset($payload['attributes']) && is_array($payload['attributes'])
            ? implode('|', array_keys($payload['attributes']))
            : 'none';
        $result['ok']++;
        $result['items'][] = [
            'sku' => $p['sku'],
            'name' => $p['name'],
            'ok' => true,
            'message' => 'Ready',
            'summary' => 'brand=' . $payload['brand_uid'] .
                ', department=' . implode(',', $payload['departments']) .
                ', category=' . $payload['category_slug'] .
                ', template=' . $payload['template_tag'] .
                ', tax=' . $payload['tax_identifier']['tax_rule_id'] .
                ', attributes=' . $attributeKeys
        ];
    }

    return $result;
}

// ─── Handle Ingestion Request ─────────────────────────────────────────────────
$message = '';
$ingestResult = null;
$preflightResult = null;
$authorizationCode = getFyndAuthorizationCodeFromRequest();

if ($_SERVER['REQUEST_METHOD'] === 'GET' && $authorizationCode !== '') {
    $validation = validateFyndOAuthCallback();
    if (!$validation['ok']) {
        $message = '
            <div class="card" style="border-color:red">
                <p class="error">Fynd authorization callback failed validation.</p>
                <pre style="background:#f8f8f8;padding:10px;font-size:12px;overflow:auto">' . htmlspecialchars($validation['error'], ENT_QUOTES, 'UTF-8') . '</pre>
            </div>
        ';
    } else {
        $token = getFyndAccessToken($authorizationCode, fyndCurrentCompanyId());
        if ($token) {
        $message = '
            <div class="card" style="border-color:green">
                <p class="success">✓ Fynd authorization connected. Token stored for ingestion.</p>
            </div>
        ';
        } else {
        $debugContent = file_exists(__DIR__ . '/token_debug.txt') ? file_get_contents(__DIR__ . '/token_debug.txt') : '';
        $message = '
            <div class="card" style="border-color:red">
                <p class="error">✗ Fynd authorization failed.</p>
                <pre style="background:#f8f8f8;padding:10px;font-size:12px;overflow:auto">' . htmlspecialchars($debugContent, ENT_QUOTES, 'UTF-8') . '</pre>
            </div>
        ';
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {

    if ($_POST['action'] === 'save_catalog_settings') {
        $keys = ['brand_uid', 'department_uid', 'category_slug', 'template_tag', 'tax_rule_id'];
        foreach ($keys as $key) {
            setCatalogSetting($key, isset($_POST[$key]) ? $_POST[$key] : '');
        }

        $message = '<p class="success">Catalog defaults saved. Click Check All Payloads to verify the prepared metadata.</p>';
    }

    if ($_POST['action'] === 'auto_catalog_settings') {
        if (defined('FYND_FIXED_MAPPING_MODE') && FYND_FIXED_MAPPING_MODE) {
            $message = '<p class="warning">Auto Pick is disabled in fixed mapping mode. Enter one manual department UID and category slug in Catalog Defaults, then run Check All Payloads.</p>';
        } else {
            @set_time_limit(120);
            $token = getFyndAccessToken(null, null, false);

            if (!$token) {
                $debugContent = file_exists(__DIR__ . '/token_debug.txt') ? file_get_contents(__DIR__ . '/token_debug.txt') : '';
                $message = '
                    <div class="card" style="border-color:red">
                        <p class="error">Fynd authorization is not connected yet.</p>
                        <pre style="background:#f8f8f8;padding:10px;font-size:12px;overflow:auto">' . htmlspecialchars($debugContent, ENT_QUOTES, 'UTF-8') . '</pre>
                    </div>
                ';
            } else {
                $autoDefaults = autoSaveFyndCatalogDefaults($token);
                if ($autoDefaults['ok']) {
                    $message = '<p class="success">' . htmlspecialchars($autoDefaults['message'], ENT_QUOTES, 'UTF-8') . ' Click Check All Payloads to verify.</p>';
                } else {
                    $message = '<p class="error">' . htmlspecialchars($autoDefaults['message'], ENT_QUOTES, 'UTF-8') . '</p>';
                }
            }
        }
    }

    if ($_POST['action'] === 'preflight_all') {
        $db = getDB();
        file_put_contents(__DIR__ . '/ingest_debug.txt', '');
        $token = getFyndAccessToken(null, null, false);

        if (!$token) {
            $debugContent = file_exists(__DIR__ . '/token_debug.txt') ? file_get_contents(__DIR__ . '/token_debug.txt') : '';
            $message = '
                <div class="card" style="border-color:red">
                    <p class="error">Fynd authorization is not connected yet.</p>
                    <pre style="background:#f8f8f8;padding:10px;font-size:12px;overflow:auto">' . htmlspecialchars($debugContent) . '</pre>
                </div>
            ';
        } else {
            $products = $db->query("SELECT * FROM products WHERE status IN ('pending', 'failed') ORDER BY sku")->fetchAll(PDO::FETCH_ASSOC);
            $preflightResult = runFyndPayloadPreflight($products, $token);
            $message = $preflightResult['failed'] > 0
                ? '<p class="error">Preflight found payload issues. Review the report below before ingesting.</p>'
                : '<p class="success">Preflight passed for all pending/failed products.</p>';
        }
    }

    if ($_POST['action'] === 'ingest_all') {
        $db = getDB();
        
        // Clear previous ingest debug log
        file_put_contents(__DIR__ . '/ingest_debug.txt', '');
        
        $token = getFyndAccessToken(null, null, false);

        if (!$token) {
            // Read debug file to show what went wrong
            $debugContent = file_get_contents(__DIR__ . '/token_debug.txt');
            $message = '
                <div class="card" style="border-color:red">
                    <p class="error">✗ Fynd authorization is not connected yet.</p>
                    <p>Launch the private extension from Fynd once so this app receives and stores the OAuth code.</p>
                    <p><strong>Debug info:</strong></p>
                    <pre style="background:#f8f8f8;padding:10px;font-size:12px;overflow:auto">' . htmlspecialchars($debugContent) . '</pre>
                </div>
            ';
        } else {
            $message = '<p class="success">✓ Access token obtained successfully. Ingesting products...</p>';
            @set_time_limit(120);
            $batchSize = defined('FYND_INGEST_BATCH_SIZE') ? max(1, intval(FYND_INGEST_BATCH_SIZE)) : 1;
            $products = $db->query("SELECT * FROM products WHERE status = 'pending' ORDER BY sku LIMIT " . $batchSize)->fetchAll(PDO::FETCH_ASSOC);
            $success = 0;
            $failed  = 0;

            if (empty($products)) {
                $message = '<p class="success">No pending products remain.</p>';
            } else foreach ($products as $p) {
                $productData = json_decode($p['data'], true);
                $result = createFyndProduct($productData, $token);

                if (($result['code'] >= 200 && $result['code'] < 300) || fyndProductAlreadyExists($result)) {
                    $db->prepare("UPDATE products SET status='ingested', error_message=NULL WHERE sku=?")
                       ->execute([$p['sku']]);
                    $db->prepare("INSERT INTO ingest_log (sku, action, message) VALUES (?, 'ingested', ?)")
                       ->execute([$p['sku'], fyndProductAlreadyExists($result) ? 'Product already exists on Fynd' : 'HTTP ' . $result['code']]);
                    $success++;
                } else {
                    $errMsg = json_encode($result['body']) ?: $result['error'];
                    $db->prepare("UPDATE products SET status='failed', error_message=? WHERE sku=?")
                       ->execute([$errMsg, $p['sku']]);
                    $db->prepare("INSERT INTO ingest_log (sku, action, message) VALUES (?, 'failed', ?)")
                       ->execute([$p['sku'], $errMsg]);
                    $failed++;
                }

                usleep(210000); // ~4.8 req/s — safely under Fynd's 5 req/s limit
            }

            $ingestResult = ['success' => $success, 'failed' => $failed];
            if (!empty($products)) {
                $remainingPending = intval($db->query("SELECT COUNT(*) FROM products WHERE status = 'pending'")->fetchColumn());
                $message = '<p class="success">Processed ' . count($products) . ' product(s) in this request. Success: ' . $success . ', failed: ' . $failed . '. Pending remaining: ' . $remainingPending . '.</p>';
            }
        }
    }

    if ($_POST['action'] === 'retry_failed') {
        $db = getDB();
        $db->exec("UPDATE products SET status='pending', error_message=NULL WHERE status='failed'");
        $message = '<p class="success">✓ Failed products reset to pending. Click Ingest to retry.</p>';
    }

    if ($_POST['action'] === 'test_token') {
        $token = getFyndAccessToken(null, null, false);
        $debugContent = file_get_contents(__DIR__ . '/token_debug.txt');
        if ($token) {
            $message = '
                <div class="card" style="border-color:green">
                    <p class="success">✓ Token obtained successfully!</p>
                    <p><strong>Status:</strong> Access token is stored and ready for catalog ingestion.</p>
                    <pre style="background:#f8f8f8;padding:10px;font-size:12px">' . htmlspecialchars($debugContent) . '</pre>
                </div>
            ';
        } else {
            $message = '
                <div class="card" style="border-color:red">
                    <p class="error">✗ No stored Fynd token is available yet.</p>
                    <p>Launch the private extension from Fynd first, then run this check again.</p>
                    <pre style="background:#f8f8f8;padding:10px;font-size:12px;overflow:auto">' . htmlspecialchars($debugContent) . '</pre>
                </div>
            ';
        }
    }
}

// ─── Load Stats ───────────────────────────────────────────────────────────────
$db = getDB();
$stats    = $db->query("SELECT status, COUNT(*) as cnt FROM products GROUP BY status")->fetchAll(PDO::FETCH_KEY_PAIR);
$pending  = isset($stats['pending']) ? $stats['pending'] : 0;
$ingested = isset($stats['ingested']) ? $stats['ingested'] : 0;
$failed   = isset($stats['failed']) ? $stats['failed'] : 0;
$total    = $pending + $ingested + $failed;
$products = $db->query("SELECT sku, name, status, error_message FROM products ORDER BY status, sku")->fetchAll(PDO::FETCH_ASSOC);
$catalogSettings = getCatalogSettings();
$ingestDebugContent = file_exists(__DIR__ . '/ingest_debug.txt') ? trim(file_get_contents(__DIR__ . '/ingest_debug.txt')) : '';
?>
<!DOCTYPE html>
<html>
<head>
    <title>Catalog Ingestor — Fynd Extension</title>
    <style>
        body { font-family: Arial, sans-serif; max-width: 960px; margin: 40px auto; padding: 0 20px; background: #f5f5f5; }
        h1 { color: #333; }
        .card { background: white; border: 1px solid #ddd; border-radius: 8px; padding: 20px; margin: 20px 0; }
        .success { color: #28a745; }
        .error   { color: #dc3545; }
        .warning { color: #ffc107; }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th, td { padding: 8px 12px; border: 1px solid #ddd; text-align: left; font-size: 14px; }
        th { background: #f0f0f0; }
        .badge { padding: 2px 8px; border-radius: 4px; font-size: 12px; }
        .badge-green  { background: #d4edda; color: #155724; }
        .badge-red    { background: #f8d7da; color: #721c24; }
        .badge-orange { background: #fff3cd; color: #856404; }
        button {
            background: #007bff; color: white; padding: 10px 24px;
            border: none; border-radius: 6px; cursor: pointer;
            font-size: 15px; margin-right: 10px; margin-top: 8px;
        }
        button:hover  { background: #0056b3; }
        button.danger { background: #dc3545; }
        button.danger:hover { background: #a71d2a; }
        button.secondary { background: #6c757d; }
        button.secondary:hover { background: #545b62; }
        .stats { display: flex; gap: 16px; margin: 20px 0; flex-wrap: wrap; }
        .stat { background: white; border: 1px solid #ddd; border-radius: 8px; padding: 16px 24px; text-align: center; flex: 1; min-width: 100px; }
        .stat-num { font-size: 32px; font-weight: bold; }
        .nav { margin-bottom: 20px; }
        .nav a { margin-right: 16px; color: #007bff; text-decoration: none; font-size: 15px; }
        .progress-bar  { background: #e9ecef; border-radius: 4px; height: 22px; margin: 10px 0; }
        .progress-fill { background: #28a745; height: 100%; border-radius: 4px; transition: width 0.3s; }
        pre { white-space: pre-wrap; word-break: break-all; }
    </style>
</head>
<body>

<div class="nav">
    <a href="index.php">← Dashboard</a>
    <a href="transformer.php">Transform</a>
    <a href="ingestor.php">Ingest</a>
</div>

<h1>Step 2 — Catalog Ingestor</h1>
<p>Send validated products to the Fynd platform via the Catalog API.</p>

<div class="card">
    <h2>Fynd Connection</h2>
    <p>This private extension needs a one-time authorization code from Fynd before API calls can be tested.</p>
    <p><strong>Extension URL in Fynd Partner:</strong></p>
    <pre style="background:#f8f8f8;padding:10px;font-size:13px;overflow:auto"><?= htmlspecialchars(APP_LIVE_URL, ENT_QUOTES, 'UTF-8') ?></pre>
    <p><strong>Fynd install path:</strong></p>
    <pre style="background:#f8f8f8;padding:10px;font-size:13px;overflow:auto"><?= htmlspecialchars(FYND_INSTALL_URL, ENT_QUOTES, 'UTF-8') ?></pre>
    <p><strong>OAuth redirect URI:</strong></p>
    <pre style="background:#f8f8f8;padding:10px;font-size:13px;overflow:auto"><?= htmlspecialchars(FYND_CALLBACK_URL, ENT_QUOTES, 'UTF-8') ?></pre>
    <p><strong>OAuth authorize URL:</strong></p>
    <?php $authorizeUrl = fyndAuthorizationUrl(); ?>
    <pre style="background:#f8f8f8;padding:10px;font-size:13px;overflow:auto"><?= htmlspecialchars($authorizeUrl, ENT_QUOTES, 'UTF-8') ?></pre>
    <p><a href="<?= htmlspecialchars($authorizeUrl, ENT_QUOTES, 'UTF-8') ?>">Start Fynd OAuth</a> while logged into the Fynd panel.</p>
    <?php if (IS_LOCAL): ?>
        <p class="warning">Local XAMPP cannot receive Fynd's callback from the internet. Launch the live extension from Fynd first, then use this page to verify the stored token.</p>
    <?php endif; ?>
    <form method="POST" style="display:inline">
        <input type="hidden" name="action" value="test_token">
        <button type="submit" class="secondary">Check Stored Fynd Token</button>
    </form>
</div>

<?= $message ?>

<div class="card">
    <h2>Catalog Defaults</h2>
    <p>Fixed mapping mode is enabled for the case study. Brand UID, template tag, and tax rule are preset. Enter one valid <strong>Department UID</strong> and <strong>Category Slug</strong> that Fynd accepts with the <strong>Supplementary</strong> template. Do not use <code>others3</code>.</p>
    <form method="POST">
        <input type="hidden" name="action" value="save_catalog_settings">
        <table>
            <tr>
                <th>Brand UID</th>
                <td><input type="text" name="brand_uid" value="<?= htmlspecialchars($catalogSettings['brand_uid'], ENT_QUOTES, 'UTF-8') ?>" style="width:100%;padding:8px"></td>
            </tr>
            <tr>
                <th>Department UID</th>
                <td><input type="text" name="department_uid" value="<?= htmlspecialchars($catalogSettings['department_uid'], ENT_QUOTES, 'UTF-8') ?>" style="width:100%;padding:8px"></td>
            </tr>
            <tr>
                <th>Category Slug</th>
                <td><input type="text" name="category_slug" value="<?= htmlspecialchars($catalogSettings['category_slug'], ENT_QUOTES, 'UTF-8') ?>" placeholder="copy from Product Templates categories" style="width:100%;padding:8px"></td>
            </tr>
            <tr>
                <th>Template Tag</th>
                <td><input type="text" name="template_tag" value="<?= htmlspecialchars($catalogSettings['template_tag'], ENT_QUOTES, 'UTF-8') ?>" placeholder="copy from Product Templates tag" style="width:100%;padding:8px"></td>
            </tr>
            <tr>
                <th>Tax Rule ID</th>
                <td><input type="text" name="tax_rule_id" value="<?= htmlspecialchars($catalogSettings['tax_rule_id'], ENT_QUOTES, 'UTF-8') ?>" style="width:100%;padding:8px"></td>
            </tr>
        </table>
        <button type="submit">Save Catalog Defaults</button>
    </form>
    <?php if (!(defined('FYND_FIXED_MAPPING_MODE') && FYND_FIXED_MAPPING_MODE)): ?>
    <form method="POST" style="display:inline">
        <input type="hidden" name="action" value="auto_catalog_settings">
        <button type="submit" class="secondary">Auto Pick Fynd Defaults</button>
    </form>
    <?php else: ?>
    <p class="warning">Auto Pick is hidden because fixed mapping mode is on.</p>
    <?php endif; ?>
</div>

<?php if ($ingestResult): ?>
<div class="card">
    <h2>Ingestion Complete</h2>
    <p class="success">✓ Successfully ingested: <strong><?= $ingestResult['success'] ?></strong> products</p>
    <?php if ($ingestResult['failed'] > 0): ?>
    <p class="error">✗ Failed: <strong><?= $ingestResult['failed'] ?></strong> — see table below, click Retry to attempt again</p>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php if ($preflightResult): ?>
<div class="card">
    <h2>Payload Preflight</h2>
    <p>
        Checked <strong><?= $preflightResult['total'] ?></strong> products:
        <span class="success"><?= $preflightResult['ok'] ?> ready</span>,
        <span class="error"><?= $preflightResult['failed'] ?> blocked</span>
    </p>
    <?php if (!empty($preflightResult['items'])): ?>
    <table>
        <tr>
            <th>SKU</th>
            <th>Name</th>
            <th>Status</th>
            <th>Metadata / Issue</th>
        </tr>
        <?php foreach ($preflightResult['items'] as $item): ?>
        <tr>
            <td><?= htmlspecialchars($item['sku'], ENT_QUOTES, 'UTF-8') ?></td>
            <td><?= htmlspecialchars($item['name'], ENT_QUOTES, 'UTF-8') ?></td>
            <td>
                <?php if ($item['ok']): ?>
                    <span class="badge badge-green">Ready</span>
                <?php else: ?>
                    <span class="badge badge-red">Blocked</span>
                <?php endif; ?>
            </td>
            <td>
                <?php if ($item['ok']): ?>
                    <?= htmlspecialchars($item['summary'], ENT_QUOTES, 'UTF-8') ?>
                <?php else: ?>
                    <span class="error"><?= htmlspecialchars($item['message'], ENT_QUOTES, 'UTF-8') ?></span>
                <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
    </table>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php if ($ingestDebugContent !== ''): ?>
<div class="card">
    <h2>Fynd Product API Request / Response</h2>
    <p>Last product ingestion API calls from this web application to Fynd. The Authorization header is redacted.</p>
    <pre style="background:#f8f8f8;padding:12px;font-size:12px;overflow:auto;max-height:520px"><?= htmlspecialchars($ingestDebugContent, ENT_QUOTES, 'UTF-8') ?></pre>
</div>
<?php endif; ?>

<div class="card">
    <h2>Product Status</h2>
    <div class="stats">
        <div class="stat"><div class="stat-num"><?= $total ?></div><div>Total</div></div>
        <div class="stat"><div class="stat-num warning"><?= $pending ?></div><div>Pending</div></div>
        <div class="stat"><div class="stat-num success"><?= $ingested ?></div><div>Ingested</div></div>
        <div class="stat"><div class="stat-num error"><?= $failed ?></div><div>Failed</div></div>
    </div>

    <?php if ($total > 0): ?>
    <div class="progress-bar">
        <div class="progress-fill" style="width: <?= $total > 0 ? round(($ingested / $total) * 100) : 0 ?>%"></div>
    </div>
    <p><?= round(($ingested / max($total, 1)) * 100) ?>% ingested</p>
    <?php endif; ?>

    <div>
        <?php if (($pending + $failed) > 0): ?>
        <form method="POST" style="display:inline">
            <input type="hidden" name="action" value="preflight_all">
            <button type="submit" class="secondary">Check All Payloads</button>
        </form>
        <?php endif; ?>

        <?php if ($pending > 0): ?>
        <form method="POST" style="display:inline">
            <input type="hidden" name="action" value="ingest_all">
            <button type="submit" onclick="return confirm('Start ingesting <?= $pending ?> products to Fynd?')">
                → Ingest <?= $pending ?> Pending Products
            </button>
        </form>
        <?php endif; ?>

        <?php if ($failed > 0): ?>
        <form method="POST" style="display:inline">
            <input type="hidden" name="action" value="retry_failed">
            <button type="submit" class="danger">↻ Retry <?= $failed ?> Failed Products</button>
        </form>
        <?php endif; ?>

        <?php if ($total === 0): ?>
        <p class="warning">⚠ No products found. Please <a href="transformer.php">run the transformer first</a>.</p>
        <?php endif; ?>
    </div>
</div>

<?php if (!empty($products)): ?>
<div class="card">
    <h2>Product List</h2>
    <table>
        <tr>
            <th>SKU</th>
            <th>Name</th>
            <th>Status</th>
            <th>Notes</th>
        </tr>
        <?php foreach ($products as $p): ?>
        <tr>
            <td><?= htmlspecialchars($p['sku']) ?></td>
            <td><?= htmlspecialchars($p['name']) ?></td>
            <td>
                <?php if ($p['status'] === 'ingested'): ?>
                    <span class="badge badge-green">✓ Ingested</span>
                <?php elseif ($p['status'] === 'failed'): ?>
                    <span class="badge badge-red">✗ Failed</span>
                <?php else: ?>
                    <span class="badge badge-orange">⏳ Pending</span>
                <?php endif; ?>
            </td>
            <td style="font-size:13px">
                <?php if ($p['error_message']): ?>
                    <span class="error"><?= htmlspecialchars(substr($p['error_message'], 0, 500), ENT_QUOTES, 'UTF-8') ?></span>
                <?php elseif ($p['status'] === 'ingested'): ?>
                    <span class="success">✓ Live on Fynd</span>
                <?php else: ?>
                    Ready to ingest
                <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
    </table>
</div>
<?php endif; ?>

</body>
</html>
