<?php
require_once 'fynd_catalog.php';

$token = getFyndAccessToken(null, null, false);
$results = [];

if ($token) {
    file_put_contents(__DIR__ . '/metadata_debug.txt', '');

    $results['brands'] = fyndPlatformRequest(
        'GET',
        '/service/platform/catalog/v1.0/company/' . FYND_COMPANY_ID . '/marketplaces/company-brand-details/',
        $token,
        ['is_active' => 'true', 'page_no' => 1, 'page_size' => 25],
        null,
        'metadata_brands'
    );

    $results['departments'] = fyndPlatformRequest(
        'GET',
        '/service/platform/catalog/v1.0/company/' . FYND_COMPANY_ID . '/departments/',
        $token,
        ['is_active' => 'true', 'page_no' => 1, 'page_size' => 100],
        null,
        'metadata_departments'
    );

    $results['categories'] = fyndPlatformRequest(
        'GET',
        '/service/platform/catalog/v1.0/company/' . FYND_COMPANY_ID . '/category/',
        $token,
        ['page_no' => 1, 'page_size' => 25],
        null,
        'metadata_categories'
    );

    $results['tax_rules'] = fyndPlatformRequest(
        'GET',
        '/service/platform/catalog/v1.0/company/' . FYND_COMPANY_ID . '/taxes/rules',
        $token,
        ['statuses' => 'ACTIVE', 'version_status' => 'LIVE', 'page' => 1, 'limit' => 25],
        null,
        'metadata_tax_rules'
    );

    $results['template_categories'] = [];
    $results['product_templates'] = [];
    $results['existing_products'] = [];
    foreach (metadataItems($results['departments']['body']) as $department) {
        $departmentUid = isset($department['uid']) ? intval($department['uid']) : 0;
        if (!$departmentUid) {
            continue;
        }

        $results['template_categories'][$departmentUid] = [
            'department' => isset($department['name']) ? $department['name'] : ('Department ' . $departmentUid),
            'response' => fyndPlatformRequest(
                'GET',
                '/service/platform/catalog/v1.0/company/' . FYND_COMPANY_ID . '/products/templates/categories/',
                $token,
                ['departments' => (string) $departmentUid, 'item_type' => 'standard'],
                null,
                'metadata_template_categories_' . $departmentUid
            )
        ];

        $results['product_templates'][$departmentUid] = [
            'department' => isset($department['name']) ? $department['name'] : ('Department ' . $departmentUid),
            'response' => fyndPlatformRequest(
                'GET',
                '/service/platform/catalog/v1.0/company/' . FYND_COMPANY_ID . '/products/templates/',
                $token,
                ['department' => $departmentUid, 'page_no' => 1, 'page_size' => 50],
                null,
                'metadata_product_templates_' . $departmentUid
            )
        ];
    }

    try {
        $db = getDB();
        $rows = $db->query("SELECT sku, name, data FROM products WHERE status = 'ingested' ORDER BY updated_at DESC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            $productData = json_decode(isset($row['data']) ? $row['data'] : '', true);
            if (!is_array($productData)) {
                $productData = [];
            }

            $itemCode = trim(isset($productData['item_code']) ? $productData['item_code'] : (isset($row['sku']) ? $row['sku'] : ''));
            $brandUid = 0;
            if (!empty($productData['brand_uid'])) {
                $brandUid = intval($productData['brand_uid']);
            }
            if ($brandUid <= 0) {
                $brandUid = intval(getCatalogSetting('brand_uid', defined('FYND_DEFAULT_BRAND_UID') ? (string) FYND_DEFAULT_BRAND_UID : '0'));
            }
            if ($itemCode === '' || $brandUid <= 0) {
                continue;
            }

            $matchedItem = findFyndProductItemByReference($itemCode, isset($row['name']) ? $row['name'] : '', $brandUid, $token);
            $results['existing_products'][] = [
                'sku' => $itemCode,
                'name' => isset($row['name']) ? $row['name'] : '',
                'brand_uid' => $brandUid,
                'response' => [
                    'body' => $matchedItem
                ]
            ];
        }
    } catch (Exception $e) {
        $results['existing_products_error'] = $e->getMessage();
    }
}

function metadataItems($body) {
    return fyndListItems($body);
}

function h($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function firstMetadataItem($body) {
    $items = metadataItems($body);
    return count($items) > 0 ? $items[0] : (is_array($body) ? $body : []);
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Fynd Catalog Metadata</title>
    <style>
        body { font-family: Arial, sans-serif; max-width: 1100px; margin: 40px auto; padding: 0 20px; background: #f5f5f5; }
        .card { background: white; border: 1px solid #ddd; border-radius: 8px; padding: 20px; margin: 20px 0; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #eee; padding: 8px; text-align: left; font-size: 13px; vertical-align: top; }
        th { background: #f8f9fa; }
        pre { white-space: pre-wrap; word-break: break-word; background: #f8f8f8; padding: 10px; }
        .error { color: #dc3545; }
        .success { color: #28a745; }
    </style>
</head>
<body>
<h1>Fynd Catalog Metadata</h1>
<p><a href="index.php">Dashboard</a> | <a href="ingestor.php">Ingestor</a></p>

<?php if (!$token): ?>
    <div class="card">
        <p class="error">No stored Fynd token found. Complete OAuth install first.</p>
        <pre><?= h(file_exists(__DIR__ . '/token_debug.txt') ? file_get_contents(__DIR__ . '/token_debug.txt') : '') ?></pre>
    </div>
<?php else: ?>
    <div class="card">
        <p class="success">Token found. Metadata fetched from Fynd.</p>
        <p>Use these values in <strong>config.php</strong> if automatic lookup cannot resolve your catalog fields.</p>
        <pre>define('FYND_DEFAULT_BRAND_UID', 0);
define('FYND_DEFAULT_DEPARTMENT_UID', 0);
define('FYND_DEFAULT_CATEGORY_SLUG', '');
define('FYND_DEFAULT_TEMPLATE_TAG', 'supplementary');
define('FYND_DEFAULT_TAX_RULE_ID', '');</pre>
    </div>

    <div class="card">
        <h2>Brands</h2>
        <table><tr><th>Name</th><th>Brand UID</th><th>UID candidates</th><th>Raw</th></tr>
        <?php foreach (metadataItems($results['brands']['body']) as $item): ?>
            <tr>
                <td><?= h(isset($item['brand_name']) ? $item['brand_name'] : (isset($item['name']) ? $item['name'] : (isset($item['brand']['name']) ? $item['brand']['name'] : ''))) ?></td>
                <td><strong><?= h(fyndBrandUidFromItem($item)) ?></strong></td>
                <td><?= h(json_encode([
                    'brand_id' => isset($item['brand_id']) ? $item['brand_id'] : '',
                    'uid' => isset($item['uid']) ? $item['uid'] : '',
                    'brand_uid' => isset($item['brand_uid']) ? $item['brand_uid'] : '',
                    'brand.uid' => isset($item['brand']['uid']) ? $item['brand']['uid'] : '',
                    'brand.id' => isset($item['brand']['id']) ? $item['brand']['id'] : ''
                ])) ?></td>
                <td><pre><?= h(json_encode($item)) ?></pre></td>
            </tr>
        <?php endforeach; ?>
        </table>
    </div>

    <div class="card">
        <h2>Departments</h2>
        <table><tr><th>Name</th><th>UID</th><th>Slug</th><th>Raw</th></tr>
        <?php foreach (metadataItems($results['departments']['body']) as $item): ?>
            <tr>
                <td><?= h(isset($item['name']) ? $item['name'] : '') ?></td>
                <td><?= h(isset($item['uid']) ? $item['uid'] : (isset($item['id']) ? $item['id'] : '')) ?></td>
                <td><?= h(isset($item['slug']) ? $item['slug'] : '') ?></td>
                <td><pre><?= h(json_encode($item)) ?></pre></td>
            </tr>
        <?php endforeach; ?>
        </table>
    </div>

    <div class="card">
        <h2>Categories</h2>
        <table><tr><th>Name</th><th>Slug</th><th>UID</th><th>Departments</th><th>Raw</th></tr>
        <?php foreach (metadataItems($results['categories']['body']) as $item): ?>
            <tr>
                <td><?= h(isset($item['name']) ? $item['name'] : '') ?></td>
                <td><strong><?= h(fyndCategorySlugFromItem($item)) ?></strong></td>
                <td><?= h(isset($item['uid']) ? $item['uid'] : '') ?></td>
                <td><strong><?= h(fyndCategoryDepartmentUidFromItem($item)) ?></strong><br><?= h(isset($item['departments']) ? json_encode($item['departments']) : '') ?></td>
                <td><pre><?= h(json_encode($item)) ?></pre></td>
            </tr>
        <?php endforeach; ?>
        </table>
    </div>

    <div class="card">
        <h2>Tax Rules</h2>
        <table><tr><th>Name</th><th>Rule ID</th><th>Status</th><th>Default</th><th>Raw</th></tr>
        <?php foreach (metadataItems($results['tax_rules']['body']) as $item): ?>
            <?php $rule = isset($item['rule']) && is_array($item['rule']) ? $item['rule'] : $item; ?>
            <tr>
                <td><?= h(isset($rule['name']) ? $rule['name'] : '') ?></td>
                <td><?= h(isset($rule['_id']) ? $rule['_id'] : (isset($rule['id']) ? $rule['id'] : '')) ?></td>
                <td><?= h(isset($rule['status']) ? $rule['status'] : '') ?></td>
                <td><?= h(!empty($rule['is_default']) ? 'yes' : 'no') ?></td>
                <td><pre><?= h(json_encode($item)) ?></pre></td>
            </tr>
        <?php endforeach; ?>
        </table>
    </div>

    <div class="card">
        <h2>Template Categories</h2>
        <table><tr><th>Department</th><th>Name</th><th>Slug</th><th>Template</th><th>Raw</th></tr>
        <?php foreach ($results['template_categories'] as $departmentUid => $entry): ?>
            <?php foreach (metadataItems($entry['response']['body']) as $item): ?>
            <tr>
                <td><?= h($entry['department'] . ' (' . $departmentUid . ')') ?></td>
                <td><?= h(isset($item['name']) ? $item['name'] : '') ?></td>
                <td><strong><?= h(fyndTemplateCategorySlugFromItem($item)) ?></strong></td>
                <td><strong><?= h(fyndTemplateSlugFromItem($item)) ?></strong></td>
                <td><pre><?= h(json_encode($item)) ?></pre></td>
            </tr>
            <?php endforeach; ?>
        <?php endforeach; ?>
        </table>
    </div>

    <div class="card">
        <h2>Product Templates</h2>
        <table><tr><th>Department</th><th>Name</th><th>Tag</th><th>Categories</th><th>Raw</th></tr>
        <?php foreach ($results['product_templates'] as $departmentUid => $entry): ?>
            <?php foreach (metadataItems($entry['response']['body']) as $item): ?>
            <tr>
                <td><?= h($entry['department'] . ' (' . $departmentUid . ')') ?></td>
                <td><?= h(isset($item['name']) ? $item['name'] : '') ?></td>
                <td><strong><?= h(fyndTemplateTagFromTemplate($item)) ?></strong></td>
                <td><?= h(json_encode(fyndTemplateCategoriesFromTemplate($item))) ?></td>
                <td><pre><?= h(json_encode($item)) ?></pre></td>
            </tr>
            <?php endforeach; ?>
        <?php endforeach; ?>
        </table>
    </div>

    <div class="card">
        <h2>Existing Fynd Products</h2>
        <p>If any product was already ingested successfully, reuse its category and template pair here instead of hunting through the Fynd UI.</p>
        <?php if (!empty($results['existing_products_error'])): ?>
            <p class="error"><?= h($results['existing_products_error']) ?></p>
        <?php endif; ?>
        <table><tr><th>SKU</th><th>Name</th><th>Brand UID</th><th>Category Slug</th><th>Template Tag</th><th>Departments</th><th>Raw</th></tr>
        <?php foreach ($results['existing_products'] as $entry): ?>
            <?php $item = firstMetadataItem($entry['response']['body']); ?>
            <?php $pair = fyndProductCategoryTemplateFromItem($item); ?>
            <tr>
                <td><?= h($entry['sku']) ?></td>
                <td><?= h($entry['name']) ?></td>
                <td><?= h($entry['brand_uid']) ?></td>
                <td><strong><?= h(isset($pair['category_slug']) ? $pair['category_slug'] : '') ?></strong></td>
                <td><strong><?= h(isset($pair['template_tag']) ? $pair['template_tag'] : '') ?></strong></td>
                <td><?= h(isset($item['departments']) ? json_encode($item['departments']) : '') ?></td>
                <td><pre><?= h(json_encode($item)) ?></pre></td>
            </tr>
        <?php endforeach; ?>
        </table>
    </div>
<?php endif; ?>
</body>
</html>
