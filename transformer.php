<?php
require_once 'config.php';

// ─── Constants ────────────────────────────────────────────────────────────────
const MANDATORY_FIELDS = ['sku', 'name', 'category', 'price', 'currency', 'hsn_code', 'tax_percent', 'country_of_origin'];

const SIZE_MAP = [
    'xs' => 'XS', 's' => 'S', 'm' => 'M', 'l' => 'L', 'xl' => 'XL',
    'xxl' => 'XXL', '2xl' => 'XXL', 'free size' => 'One Size',
    'freesize' => 'One Size', 'one size' => 'One Size', 'onesize' => 'One Size'
];

const COLOR_MAP = [
    'grey' => 'Gray', 'gray' => 'Gray',
    'navy' => 'Navy Blue', 'navyblue' => 'Navy Blue',
    'multicolor' => 'Multicolor', 'multi' => 'Multicolor'
];

// ─── Validators ───────────────────────────────────────────────────────────────
function checkImageUrlReachability($url) {
    if (!function_exists('curl_init')) {
        return ['ok' => false, 'message' => 'cURL is not available for image URL validation'];
    }

    $timeout = defined('IMAGE_URL_CHECK_TIMEOUT_SECONDS') ? intval(IMAGE_URL_CHECK_TIMEOUT_SECONDS) : 5;
    $verifySsl = defined('FYND_VERIFY_SSL') ? FYND_VERIFY_SSL : true;

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_NOBODY         => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 3,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CONNECTTIMEOUT => $timeout,
        CURLOPT_SSL_VERIFYPEER => $verifySsl,
        CURLOPT_USERAGENT      => 'Fynd Catalog Transformer/1.0'
    ]);

    curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($httpCode >= 200 && $httpCode < 300) {
        return ['ok' => true, 'message' => ''];
    }

    return [
        'ok' => false,
        'message' => $curlError !== '' ? $curlError : 'Image URL returned HTTP ' . $httpCode
    ];
}

function validateRecord($record, $rowNum, &$seenSKUs) {
    $errors = [];
    $warnings = [];

    // 1. Duplicate SKU
    $sku = trim(isset($record['sku']) ? $record['sku'] : '');
    if (in_array($sku, $seenSKUs)) {
        $errors[] = ['type' => 'DUPLICATE_SKU', 'message' => "SKU \"$sku\" appears more than once"];
    } elseif ($sku) {
        $seenSKUs[] = $sku;
    }

    // 2. Missing mandatory fields
    foreach (MANDATORY_FIELDS as $field) {
        if (empty(trim(isset($record[$field]) ? $record[$field] : ''))) {
            $errors[] = ['type' => 'MISSING_FIELD', 'message' => "\"$field\" is required but empty"];
        }
    }

    // 3. Price sanity check
    $price = floatval(isset($record['price']) ? $record['price'] : 0);
    $compareAt = floatval(isset($record['compare_at_price']) ? $record['compare_at_price'] : 0);
    if ($price <= 0) {
        $errors[] = ['type' => 'INVALID_PRICE', 'message' => "Price \"{$record['price']}\" must be a positive number"];
    }
    if ($compareAt > 0 && $price > $compareAt) {
        $warnings[] = ['type' => 'PRICE_MISMATCH', 'message' => "Selling price ($price) is greater than compare_at_price ($compareAt)"];
    }

    // 4. Image URL validation
    $imageUrl = trim(isset($record['image_url']) ? $record['image_url'] : '');
    if (empty($imageUrl)) {
        $errors[] = ['type' => 'MISSING_IMAGE', 'message' => 'No image_url provided'];
    } elseif (!filter_var($imageUrl, FILTER_VALIDATE_URL)) {
        $errors[] = ['type' => 'INVALID_IMAGE_URL', 'message' => "\"$imageUrl\" is not a valid URL"];
    } elseif (defined('VALIDATE_IMAGE_URL_REACHABILITY') && VALIDATE_IMAGE_URL_REACHABILITY) {
        $reachability = checkImageUrlReachability($imageUrl);
        if (!$reachability['ok']) {
            $errors[] = ['type' => 'INVALID_IMAGE_URL', 'message' => "\"$imageUrl\" is not reachable: " . $reachability['message']];
        }
    }

    return ['errors' => $errors, 'warnings' => $warnings];
}

// ─── Normalizers ──────────────────────────────────────────────────────────────
function normalizeSize($raw) {
    $key = strtolower(trim($raw !== null ? $raw : ''));
    return isset(SIZE_MAP[$key]) ? SIZE_MAP[$key] : trim($raw !== null ? $raw : '');
}

function normalizeColor($raw) {
    $key = strtolower(trim($raw !== null ? $raw : ''));
    return isset(COLOR_MAP[$key]) ? COLOR_MAP[$key] : trim($raw !== null ? $raw : '');
}

// ─── Fynd Mapper ──────────────────────────────────────────────────────────────
function mapToFyndProduct($record) {
    return [
        'name'              => trim($record['name']),
        'slug'              => strtolower(preg_replace('/[^a-z0-9]+/', '-', trim($record['name']))),
        'item_code'         => trim($record['sku']),
        'legacy_brand_name' => trim(isset($record['brand']) ? $record['brand'] : 'Generic'),
        'source_category_name' => trim(isset($record['category']) ? $record['category'] : ''),
        'legacy_category_name' => defined('FYND_TARGET_CATEGORY_NAME') ? FYND_TARGET_CATEGORY_NAME : 'Others',
        'template_tag'      => 'supplementary',
        'item_type'         => 'standard',
        'description'       => trim(isset($record['description']) ? $record['description'] : ''),
        'country_of_origin' => trim(isset($record['country_of_origin']) ? $record['country_of_origin'] : 'India'),
        'currency'          => trim(isset($record['currency']) ? $record['currency'] : 'INR'),
        'is_active'         => true,
        'is_set'            => false,
        'media'             => [['url' => trim($record['image_url']), 'type' => 'image']],
        'attributes'        => [
            'color'         => normalizeColor(isset($record['color']) ? $record['color'] : ''),
            'gender'        => 'Unisex',
            'primary_color' => normalizeColor(isset($record['color']) ? $record['color'] : ''),
            'material'      => 'NA'
        ],
        'sizes' => [[
            'size'                        => normalizeSize(isset($record['size']) ? $record['size'] : ''),
            'item_weight'                 => floatval(isset($record['weight_grams']) ? $record['weight_grams'] : 0),
            'item_weight_unit_of_measure' => 'gram',
            'item_length'                 => 25,
            'item_width'                  => 20,
            'item_height'                 => 3,
            'item_dimensions_unit_of_measure' => 'centimeter',
            'identifiers'                 => [[
                'gtin_type'  => 'sku_code',
                'gtin_value' => trim($record['sku']),
                'primary'    => true
            ]],
            'seller_identifier'           => trim($record['sku']),
            'price'                       => floatval(isset($record['compare_at_price']) && floatval($record['compare_at_price']) > 0 ? $record['compare_at_price'] : $record['price']),
            'price_effective' => floatval($record['price']),
            'price_marked'    => floatval(isset($record['compare_at_price']) && floatval($record['compare_at_price']) > 0 ? $record['compare_at_price'] : $record['price']),
            'price_transfer'  => 0,
            'quantity'        => intval(isset($record['stock']) ? $record['stock'] : 0),
            'track_inventory' => true
        ]],
        'tax_identifier' => [
            'tax_rule_id' => FYND_DEFAULT_TAX_RULE_ID
        ],
        'legacy_hsn_code' => trim(isset($record['hsn_code']) ? $record['hsn_code'] : ''),
        'legacy_tax_percent' => floatval(isset($record['tax_percent']) ? $record['tax_percent'] : 0),
        'company_id' => intval(FYND_COMPANY_ID),
        'highlights'  => []
    ];
}

// ─── Main Transform Function ──────────────────────────────────────────────────
function transformCSV($filePath) {
    $db = getDB();

    // Clear previous data
    $db->exec("DELETE FROM products");
    $db->exec("DELETE FROM validation_errors");

    $handle = fopen($filePath, 'r');
    $headers = fgetcsv($handle);
    $headers = array_map('trim', $headers);

    $seenSKUs = [];
    $report = ['total' => 0, 'valid' => 0, 'invalid' => 0, 'warnings' => 0, 'details' => []];
    $rowNum = 2;

    while (($row = fgetcsv($handle)) !== false) {
        $record = array_combine($headers, $row);
        $report['total']++;

        $result = validateRecord($record, $rowNum, $seenSKUs);

        // Save warnings
        foreach ($result['warnings'] as $w) {
            $stmt = $db->prepare("INSERT INTO validation_errors (`row_number`, sku, error_type, error_message) VALUES (?, ?, ?, ?)");
            $stmt->execute([$rowNum, $record['sku'], 'WARNING_' . $w['type'], $w['message']]);
            $report['warnings']++;
        }

        if (!empty($result['errors'])) {
            // Save errors
            foreach ($result['errors'] as $e) {
                $stmt = $db->prepare("INSERT INTO validation_errors (`row_number`, sku, error_type, error_message) VALUES (?, ?, ?, ?)");
                $stmt->execute([$rowNum, $record['sku'], $e['type'], $e['message']]);
            }
            $report['invalid']++;
            $report['details'][] = ['row' => $rowNum, 'sku' => $record['sku'], 'status' => 'rejected', 'errors' => $result['errors']];
        } else {
            // Save valid product to DB
            $product = mapToFyndProduct($record);
            $stmt = $db->prepare("INSERT INTO products (sku, name, data, status) VALUES (?, ?, ?, 'pending') ON DUPLICATE KEY UPDATE data=VALUES(data), status='pending'");
            $stmt->execute([$record['sku'], $record['name'], json_encode($product)]);
            $report['valid']++;
            $report['details'][] = ['row' => $rowNum, 'sku' => $record['sku'], 'status' => 'accepted'];
        }

        $rowNum++;
    }

    fclose($handle);
    return $report;
}

// ─── Handle Upload ────────────────────────────────────────────────────────────
$report = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['csv_file'])) {
    $uploadPath = __DIR__ . '/uploads/' . basename($_FILES['csv_file']['name']);
    if (!is_dir(__DIR__ . '/uploads')) mkdir(__DIR__ . '/uploads', 0755, true);
    move_uploaded_file($_FILES['csv_file']['tmp_name'], $uploadPath);
    $report = transformCSV($uploadPath);
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Catalog Transformer</title>
    <style>
        body { font-family: Arial, sans-serif; max-width: 900px; margin: 40px auto; padding: 0 20px; }
        h1 { color: #333; }
        .card { background: #f9f9f9; border: 1px solid #ddd; border-radius: 8px; padding: 20px; margin: 20px 0; }
        .success { color: green; } .error { color: red; } .warning { color: orange; }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th, td { padding: 8px 12px; border: 1px solid #ddd; text-align: left; font-size: 14px; }
        th { background: #f0f0f0; }
        .badge { padding: 2px 8px; border-radius: 4px; font-size: 12px; }
        .badge-green { background: #d4edda; color: #155724; }
        .badge-red { background: #f8d7da; color: #721c24; }
        .badge-orange { background: #fff3cd; color: #856404; }
        input[type=file] { padding: 10px; border: 2px dashed #aaa; border-radius: 8px; width: 100%; }
        button { background: #007bff; color: white; padding: 10px 24px; border: none; border-radius: 6px; cursor: pointer; font-size: 16px; }
        button:hover { background: #0056b3; }
        .stats { display: flex; gap: 20px; margin: 20px 0; }
        .stat { background: white; border: 1px solid #ddd; border-radius: 8px; padding: 16px 24px; text-align: center; }
        .stat-num { font-size: 32px; font-weight: bold; }
        .nav { margin-bottom: 20px; }
        .nav a { margin-right: 16px; color: #007bff; text-decoration: none; }
    </style>
</head>
<body>
<div class="nav">
    <a href="index.php">Dashboard</a>
    <a href="transformer.php">Transform</a>
    <a href="ingestor.php">Ingest</a>
</div>

<h1>Step 1 — Catalog Transformer</h1>
<p>Upload your legacy product CSV. The transformer will validate, clean, and prepare it for Fynd ingestion.</p>

<div class="card">
    <form method="POST" enctype="multipart/form-data">
        <p><strong>Select your legacy CSV file:</strong></p>
        <input type="file" name="csv_file" accept=".csv" required>
        <br><br>
        <button type="submit">Transform CSV</button>
    </form>
</div>

<?php if ($report): ?>
<div class="card">
    <h2>Transformation Report</h2>
    <div class="stats">
        <div class="stat"><div class="stat-num"><?= $report['total'] ?></div><div>Total</div></div>
        <div class="stat"><div class="stat-num success"><?= $report['valid'] ?></div><div>Valid</div></div>
        <div class="stat"><div class="stat-num error"><?= $report['invalid'] ?></div><div>Rejected</div></div>
        <div class="stat"><div class="stat-num warning"><?= $report['warnings'] ?></div><div>Warnings</div></div>
    </div>

    <table>
        <tr><th>Row</th><th>SKU</th><th>Status</th><th>Details</th></tr>
        <?php foreach ($report['details'] as $d): ?>
        <tr>
            <td><?= $d['row'] ?></td>
            <td><?= htmlspecialchars($d['sku']) ?></td>
            <td>
                <?php if ($d['status'] === 'accepted'): ?>
                    <span class="badge badge-green">✓ Accepted</span>
                <?php else: ?>
                    <span class="badge badge-red">✗ Rejected</span>
                <?php endif; ?>
            </td>
            <td>
                <?php if (!empty($d['errors'])): ?>
                    <?php foreach ($d['errors'] as $e): ?>
                        <div class="error">• <?= htmlspecialchars($e['message']) ?></div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <span class="success">Ready for ingestion</span>
                <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
    </table>

    <?php if ($report['valid'] > 0): ?>
    <br>
    <a href="ingestor.php"><button type="button">→ Proceed to Ingestion (<?= $report['valid'] ?> products)</button></a>
    <?php endif; ?>
</div>
<?php endif; ?>
</body>
</html>
