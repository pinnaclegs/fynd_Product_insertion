<?php
require_once 'fynd_oauth.php';

const FYND_METADATA_DEBUG_FILE = __DIR__ . '/metadata_debug.txt';

function ensureCatalogSettingsTable() {
    static $done = false;
    if ($done) {
        return true;
    }

    try {
        $db = getDB();
        $db->exec("
            CREATE TABLE IF NOT EXISTS catalog_settings (
                `key` VARCHAR(100) NOT NULL PRIMARY KEY,
                `value` TEXT NULL,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            )
        ");
        $done = true;
        return true;
    } catch (Exception $e) {
        return false;
    }
}

function getCatalogSetting($key, $default = '') {
    if (!ensureCatalogSettingsTable()) {
        return $default;
    }

    try {
        $db = getDB();
        $stmt = $db->prepare("SELECT `value` FROM catalog_settings WHERE `key` = ?");
        $stmt->execute([$key]);
        $value = $stmt->fetchColumn();
        return $value === false ? $default : trim((string) $value);
    } catch (Exception $e) {
        return $default;
    }
}

function setCatalogSetting($key, $value) {
    if (!ensureCatalogSettingsTable()) {
        return false;
    }

    $db = getDB();
    $stmt = $db->prepare("
        INSERT INTO catalog_settings (`key`, `value`) VALUES (?, ?)
        ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)
    ");
    $stmt->execute([$key, trim((string) $value)]);
    return true;
}

function getCatalogSettings() {
    return [
        'brand_uid' => getCatalogSetting('brand_uid', defined('FYND_DEFAULT_BRAND_UID') ? (string) FYND_DEFAULT_BRAND_UID : '0'),
        'department_uid' => getCatalogSetting('department_uid', defined('FYND_DEFAULT_DEPARTMENT_UID') ? (string) FYND_DEFAULT_DEPARTMENT_UID : '0'),
        'category_slug' => getCatalogSetting('category_slug', defined('FYND_DEFAULT_CATEGORY_SLUG') ? FYND_DEFAULT_CATEGORY_SLUG : ''),
        'template_tag' => getCatalogSetting('template_tag', defined('FYND_DEFAULT_TEMPLATE_TAG') ? FYND_DEFAULT_TEMPLATE_TAG : 'supplementary'),
        'tax_rule_id' => getCatalogSetting('tax_rule_id', defined('FYND_DEFAULT_TAX_RULE_ID') ? FYND_DEFAULT_TAX_RULE_ID : '')
    ];
}

function writeFyndMetadataDebug($label, $url, $httpCode, $curlError, $response) {
    file_put_contents(
        FYND_METADATA_DEBUG_FILE,
        date('Y-m-d H:i:s') . "\n" .
        "Label: $label\n" .
        "URL: $url\n" .
        "HTTP Code: $httpCode\n" .
        "cURL Error: $curlError\n" .
        "Response: " . substr($response ?: '', 0, 4000) . "\n\n",
        FILE_APPEND
    );
}

function fyndPlatformRequest($method, $path, $token, $query = [], $body = null, $label = 'platform_request') {
    $url = FYND_API_BASE . $path;
    $cleanQuery = [];

    foreach ($query as $key => $value) {
        if ($value !== null && $value !== '' && $value !== []) {
            $cleanQuery[$key] = $value;
        }
    }

    if (!empty($cleanQuery)) {
        $url .= '?' . http_build_query($cleanQuery);
    }

    $headers = [
        'Authorization: Bearer ' . $token,
        'Content-Type: application/json'
    ];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => strtoupper($method),
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_SSL_VERIFYPEER => defined('FYND_VERIFY_SSL') ? FYND_VERIFY_SSL : true,
        CURLOPT_TIMEOUT        => 30
    ]);

    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    writeFyndMetadataDebug($label, $url, $httpCode, $curlError, $response ?: '');

    return [
        'code'  => $httpCode,
        'body'  => json_decode($response ?: '', true),
        'raw'   => $response,
        'error' => $curlError,
        'url'   => $url
    ];
}

function firstFyndListItem($response) {
    $items = fyndListItems($response);
    return count($items) > 0 ? $items[0] : null;
}

function fyndListItems($response) {
    if (!is_array($response)) {
        return [];
    }

    if (isset($response['items']) && is_array($response['items']) && count($response['items']) > 0) {
        return $response['items'];
    }

    if (isset($response['data']) && is_array($response['data']) && count($response['data']) > 0) {
        return $response['data'];
    }

    if (isset($response['results']) && is_array($response['results']) && count($response['results']) > 0) {
        return $response['results'];
    }

    return [];
}

function normalizeFyndText($value) {
    return strtolower(trim((string) $value));
}

function firstMatchingFyndItem($items, $needle, $nameFields) {
    $needle = normalizeFyndText($needle);
    if ($needle === '') {
        return count($items) > 0 ? $items[0] : null;
    }

    foreach ($items as $item) {
        foreach ($nameFields as $field) {
            $value = fyndNestedValue($item, $field);
            if (normalizeFyndText($value) === $needle) {
                return $item;
            }
        }
    }

    return count($items) > 0 ? $items[0] : null;
}

function fyndNestedValue($item, $path) {
    if (!is_array($item)) {
        return '';
    }

    $parts = explode('.', $path);
    $value = $item;
    foreach ($parts as $part) {
        if (!is_array($value) || !isset($value[$part])) {
            return '';
        }
        $value = $value[$part];
    }

    return $value;
}

function fyndBrandUidFromItem($item) {
    if (!is_array($item)) {
        return 0;
    }

    if (!empty($item['brand_id'])) return intval($item['brand_id']);
    if (!empty($item['uid'])) return intval($item['uid']);
    if (!empty($item['brand_uid'])) return intval($item['brand_uid']);
    if (!empty($item['brand']['uid'])) return intval($item['brand']['uid']);
    if (!empty($item['brand']['id'])) return intval($item['brand']['id']);

    return 0;
}

function fyndCategorySlugFromItem($item) {
    if (!is_array($item)) {
        return '';
    }

    if (!empty($item['slug'])) return $item['slug'];
    if (!empty($item['category_slug'])) return $item['category_slug'];

    return '';
}

function fyndCategoryDepartmentUidFromItem($item) {
    if (!is_array($item) || empty($item['departments'])) {
        return 0;
    }

    $departments = $item['departments'];
    if (is_array($departments)) {
        $first = reset($departments);
        if (is_numeric($first)) {
            return intval($first);
        }
        if (is_array($first)) {
            if (!empty($first['uid'])) return intval($first['uid']);
            if (!empty($first['id']) && is_numeric($first['id'])) return intval($first['id']);
        }
    }

    return 0;
}

function fyndTemplateCategorySlugFromItem($item) {
    if (!is_array($item)) {
        return '';
    }

    if (!empty($item['slug'])) return $item['slug'];
    if (!empty($item['category_slug'])) return $item['category_slug'];
    if (!empty($item['slug_key'])) return $item['slug_key'];

    return '';
}

function fyndTemplateSlugFromItem($item) {
    if (!is_array($item)) {
        return '';
    }

    if (!empty($item['template_slug'])) return $item['template_slug'];
    if (!empty($item['template_tag'])) return $item['template_tag'];
    if (!empty($item['template']['slug'])) return $item['template']['slug'];
    if (!empty($item['template']['tag'])) return $item['template']['tag'];

    return '';
}

function configuredFyndCategorySlug() {
    $candidate = getCatalogSetting('category_slug', defined('FYND_DEFAULT_CATEGORY_SLUG') ? trim(FYND_DEFAULT_CATEGORY_SLUG) : '');
    $placeholders = ['', 'your-category-slug', 'category-slug', 'your_category_slug'];

    return in_array(strtolower($candidate), $placeholders) ? '' : $candidate;
}

function configuredFyndTemplateTag() {
    $candidate = getCatalogSetting('template_tag', defined('FYND_DEFAULT_TEMPLATE_TAG') ? trim(FYND_DEFAULT_TEMPLATE_TAG) : '');
    $placeholders = ['', 'your-template-tag', 'template-tag', 'your_template_tag'];

    return in_array(strtolower($candidate), $placeholders) ? '' : $candidate;
}

function fyndFixedMappingMode() {
    return defined('FYND_FIXED_MAPPING_MODE') && FYND_FIXED_MAPPING_MODE;
}

function fyndTargetCategoryName() {
    return defined('FYND_TARGET_CATEGORY_NAME') && trim(FYND_TARGET_CATEGORY_NAME) !== ''
        ? trim(FYND_TARGET_CATEGORY_NAME)
        : 'Others';
}

function resolveFyndBrandUid($brandName, $token) {
    static $cache = [];

    $settingBrandUid = intval(getCatalogSetting('brand_uid', '0'));
    if ($settingBrandUid > 0) {
        return $settingBrandUid;
    }

    if (FYND_DEFAULT_BRAND_UID) {
        return intval(FYND_DEFAULT_BRAND_UID);
    }

    $key = strtolower(trim($brandName));
    if (isset($cache[$key])) {
        return $cache[$key];
    }

    $response = fyndPlatformRequest(
        'GET',
        '/service/platform/catalog/v1.0/company/' . FYND_COMPANY_ID . '/marketplaces/company-brand-details/',
        $token,
        ['is_active' => 'true', 'q' => $brandName, 'page_no' => 1, 'page_size' => 10],
        null,
        'resolve_brand'
    );

    $items = fyndListItems($response['body']);
    if (empty($items)) {
        $fallbackResponse = fyndPlatformRequest(
            'GET',
            '/service/platform/catalog/v1.0/company/' . FYND_COMPANY_ID . '/marketplaces/company-brand-details/',
            $token,
            ['is_active' => 'true', 'page_no' => 1, 'page_size' => 10],
            null,
            'resolve_brand_fallback'
        );
        $items = fyndListItems($fallbackResponse['body']);
    }

    $item = firstMatchingFyndItem($items, $brandName, ['brand_name', 'name', 'brand.name']);
    $uid = fyndBrandUidFromItem($item);

    $cache[$key] = $uid;
    return $uid;
}

function resolveFyndDepartmentUid($categoryName, $token) {
    static $cache = [];

    $settingDepartmentUid = intval(getCatalogSetting('department_uid', '0'));
    if ($settingDepartmentUid > 0) {
        return $settingDepartmentUid;
    }

    if (FYND_DEFAULT_DEPARTMENT_UID) {
        return intval(FYND_DEFAULT_DEPARTMENT_UID);
    }

    $key = strtolower(trim($categoryName));
    if (isset($cache[$key])) {
        return $cache[$key];
    }

    $response = fyndPlatformRequest(
        'GET',
        '/service/platform/catalog/v1.0/company/' . FYND_COMPANY_ID . '/departments/',
        $token,
        ['is_active' => 'true', 'search' => $categoryName, 'page_no' => 1, 'page_size' => 10],
        null,
        'resolve_department'
    );

    $items = fyndListItems($response['body']);
    if (empty($items)) {
        $fallbackResponse = fyndPlatformRequest(
            'GET',
            '/service/platform/catalog/v1.0/company/' . FYND_COMPANY_ID . '/departments/',
            $token,
            ['is_active' => 'true', 'page_no' => 1, 'page_size' => 10],
            null,
            'resolve_department_fallback'
        );
        $items = fyndListItems($fallbackResponse['body']);
    }

    $item = firstMatchingFyndItem($items, $categoryName, ['name', 'slug']);
    $uid = 0;
    if (is_array($item)) {
        if (!empty($item['uid'])) {
            $uid = intval($item['uid']);
        } elseif (!empty($item['id']) && is_numeric($item['id'])) {
            $uid = intval($item['id']);
        }
    }

    $cache[$key] = $uid;
    return $uid;
}

function resolveFyndCategorySlug($categoryName, $departmentUid, $token) {
    $metadata = resolveFyndCategoryMetadata($categoryName, $departmentUid, $token);
    return $metadata['slug'];
}

function resolveFyndCategoryMetadata($categoryName, $departmentUid, $token) {
    static $cache = [];

    $key = strtolower(trim($categoryName)) . '|' . intval($departmentUid);
    if (isset($cache[$key])) {
        return $cache[$key];
    }

    $query = ['q' => $categoryName, 'page_no' => 1, 'page_size' => 10];
    if ($departmentUid) {
        $query['department'] = intval($departmentUid);
    }

    $response = fyndPlatformRequest(
        'GET',
        '/service/platform/catalog/v1.0/company/' . FYND_COMPANY_ID . '/category/',
        $token,
        $query,
        null,
        'resolve_category'
    );

    $items = fyndListItems($response['body']);
    if (empty($items)) {
        $fallbackResponse = fyndPlatformRequest(
            'GET',
            '/service/platform/catalog/v1.0/company/' . FYND_COMPANY_ID . '/category/',
            $token,
            ['page_no' => 1, 'page_size' => 10],
            null,
            'resolve_category_fallback'
        );
        $items = fyndListItems($fallbackResponse['body']);
    }

    $item = firstMatchingFyndItem($items, $categoryName, ['name', 'slug', 'category_slug']);
    $configuredCategorySlug = configuredFyndCategorySlug();
    $slug = $configuredCategorySlug !== '' ? $configuredCategorySlug : fyndCategorySlugFromItem($item);
    $resolvedDepartmentUid = fyndCategoryDepartmentUidFromItem($item);

    $cache[$key] = [
        'slug' => $slug,
        'department_uid' => $resolvedDepartmentUid
    ];
    return $cache[$key];
}

function resolveFyndCategoryMetadataBySlug($categorySlug, $token) {
    static $cache = [];

    $categorySlug = trim((string) $categorySlug);
    if ($categorySlug === '') {
        return ['slug' => '', 'department_uid' => 0];
    }
    if (isset($cache[$categorySlug])) {
        return $cache[$categorySlug];
    }

    $response = fyndPlatformRequest(
        'GET',
        '/service/platform/catalog/v1.0/company/' . FYND_COMPANY_ID . '/category/',
        $token,
        ['slug' => $categorySlug, 'page_no' => 1, 'page_size' => 25],
        null,
        'resolve_category_by_slug_' . $categorySlug
    );

    $items = fyndListItems($response['body']);
    $item = firstMatchingFyndItem($items, $categorySlug, ['slug', 'category_slug', 'name']);

    $cache[$categorySlug] = [
        'slug' => fyndCategorySlugFromItem($item),
        'department_uid' => fyndCategoryDepartmentUidFromItem($item)
    ];

    return $cache[$categorySlug];
}

function resolveFyndTemplateCategoryMetadata($categoryName, $departmentUid, $preferredTemplateTag, $token) {
    static $cache = [];

    if (!$departmentUid) {
        return ['slug' => '', 'template_tag' => ''];
    }

    $key = strtolower(trim($categoryName)) . '|' . intval($departmentUid) . '|' . strtolower(trim($preferredTemplateTag));
    if (isset($cache[$key])) {
        return $cache[$key];
    }

    $response = fyndPlatformRequest(
        'GET',
        '/service/platform/catalog/v1.0/company/' . FYND_COMPANY_ID . '/products/templates/categories/',
        $token,
        ['departments' => (string) intval($departmentUid), 'item_type' => 'standard'],
        null,
        'resolve_template_category'
    );

    $items = fyndListItems($response['body']);
    $preferredItems = [];
    foreach ($items as $item) {
        if ($preferredTemplateTag !== '' && normalizeFyndText(fyndTemplateSlugFromItem($item)) === normalizeFyndText($preferredTemplateTag)) {
            $preferredItems[] = $item;
        }
    }

    $item = null;
    if (!empty($preferredItems)) {
        $item = firstMatchingFyndItem($preferredItems, $categoryName, ['name', 'slug', 'slug_key']);
    }
    if (!$item && !empty($items)) {
        $item = firstMatchingFyndItem($items, $categoryName, ['name', 'slug', 'slug_key']);
    }

    $cache[$key] = [
        'slug' => fyndTemplateCategorySlugFromItem($item),
        'template_tag' => fyndTemplateSlugFromItem($item)
    ];
    return $cache[$key];
}

function listFyndProductTemplates($departmentUid, $token) {
    static $cache = [];

    if (!$departmentUid) {
        return [];
    }
    if (isset($cache[$departmentUid])) {
        return $cache[$departmentUid];
    }

    $response = fyndPlatformRequest(
        'GET',
        '/service/platform/catalog/v1.0/company/' . FYND_COMPANY_ID . '/products/templates/',
        $token,
        ['department' => intval($departmentUid), 'page_no' => 1, 'page_size' => 50],
        null,
        'product_templates_' . intval($departmentUid)
    );

    $cache[$departmentUid] = fyndListItems($response['body']);
    return $cache[$departmentUid];
}

function listFyndDepartmentsForTemplates($token) {
    static $cache = null;

    if ($cache !== null) {
        return $cache;
    }

    $cache = [];
    $pageNo = 1;
    do {
        $response = fyndPlatformRequest(
            'GET',
            '/service/platform/catalog/v1.0/company/' . FYND_COMPANY_ID . '/departments/',
            $token,
            ['is_active' => 'true', 'page_no' => $pageNo, 'page_size' => 100],
            null,
            'template_departments_' . $pageNo
        );

        foreach (fyndListItems($response['body']) as $item) {
            $uid = 0;
            if (!empty($item['uid'])) {
                $uid = intval($item['uid']);
            } elseif (!empty($item['id']) && is_numeric($item['id'])) {
                $uid = intval($item['id']);
            }

            if ($uid > 0) {
                $cache[] = [
                    'uid' => $uid,
                    'name' => isset($item['name']) ? $item['name'] : ('Department ' . $uid)
                ];
            }
        }

        $hasNext = isset($response['body']['page']['has_next']) && $response['body']['page']['has_next'];
        $pageNo++;
    } while ($hasNext && $pageNo <= 10);

    return $cache;
}

function listFyndTemplateCategories($departmentUid, $token) {
    static $cache = [];

    if (!$departmentUid) {
        return [];
    }
    if (isset($cache[$departmentUid])) {
        return $cache[$departmentUid];
    }

    $response = fyndPlatformRequest(
        'GET',
        '/service/platform/catalog/v1.0/company/' . FYND_COMPANY_ID . '/products/templates/categories/',
        $token,
        ['departments' => (string) intval($departmentUid), 'item_type' => 'standard'],
        null,
        'template_categories_' . intval($departmentUid)
    );

    $cache[$departmentUid] = fyndListItems($response['body']);
    return $cache[$departmentUid];
}

function validateFyndProductTemplate($templateTag, $itemType, $token, $bulk = false) {
    static $cache = [];

    $templateTag = trim((string) $templateTag);
    $itemType = trim((string) $itemType);
    $cacheKey = strtolower($templateTag . '|' . $itemType . '|' . ($bulk ? '1' : '0'));
    if (isset($cache[$cacheKey])) {
        return $cache[$cacheKey];
    }

    if ($templateTag === '' || $itemType === '') {
        $cache[$cacheKey] = ['code' => 0, 'body' => [], 'raw' => '', 'error' => 'Missing template tag or item type', 'url' => ''];
        return $cache[$cacheKey];
    }

    $cache[$cacheKey] = fyndPlatformRequest(
        'GET',
        '/service/platform/catalog/v1.0/company/' . FYND_COMPANY_ID . '/products/templates/' . rawurlencode($templateTag) . '/validation/schema/',
        $token,
        ['item_type' => $itemType, 'bulk' => $bulk ? 'true' : 'false'],
        null,
        'validate_product_template_' . strtolower($templateTag)
    );

    return $cache[$cacheKey];
}

function fyndTemplateValidationDetails($responseBody) {
    if (!is_array($responseBody)) {
        return [];
    }

    if (!empty($responseBody['template_details']) && is_array($responseBody['template_details'])) {
        return $responseBody['template_details'];
    }

    return [];
}

function fyndTemplateValidationPair($templateTag, $itemType, $token) {
    $response = validateFyndProductTemplate($templateTag, $itemType, $token, false);
    $details = fyndTemplateValidationDetails($response['body']);

    $categories = [];
    if (!empty($details['categories']) && is_array($details['categories'])) {
        foreach ($details['categories'] as $categorySlug) {
            $categorySlug = trim((string) $categorySlug);
            if ($categorySlug !== '') {
                $categories[] = $categorySlug;
            }
        }
    }

    $departments = [];
    if (!empty($details['departments']) && is_array($details['departments'])) {
        foreach ($details['departments'] as $departmentUid) {
            if ($departmentUid !== '' && $departmentUid !== null) {
                $departments[] = intval($departmentUid);
            }
        }
    }

    $categorySlug = !empty($categories) ? $categories[0] : '';
    $departmentUid = !empty($departments) ? intval($departments[0]) : 0;
    if ($categorySlug !== '' && !$departmentUid) {
        $resolvedCategory = resolveFyndCategoryMetadataBySlug($categorySlug, $token);
        if (!empty($resolvedCategory['department_uid'])) {
            $departmentUid = intval($resolvedCategory['department_uid']);
        }
    }

    return [
        'category_slug' => $categorySlug,
        'department_uid' => $departmentUid,
        'template_tag' => $templateTag,
        'categories' => $categories,
        'departments' => $departments,
        'response' => $response
    ];
}

function findFyndFirstUsableTemplatePair($token, $preferredTemplateTag = '') {
    static $cache = [];

    $cacheKey = strtolower(trim($preferredTemplateTag));
    if (isset($cache[$cacheKey])) {
        return $cache[$cacheKey];
    }

    $fallback = ['department_uid' => 0, 'department_name' => '', 'category_slug' => '', 'template_tag' => '', 'source' => ''];

    foreach (listFyndDepartmentsForTemplates($token) as $department) {
        foreach (listFyndProductTemplates($department['uid'], $token) as $template) {
            $tag = fyndTemplateTagFromTemplate($template);
            $categories = fyndTemplateCategoriesFromTemplate($template);
            if ($tag === '' || empty($categories)) {
                continue;
            }

            $pair = [
                'department_uid' => intval($department['uid']),
                'department_name' => $department['name'],
                'category_slug' => (string) $categories[0],
                'template_tag' => $tag,
                'source' => 'product_template'
            ];

            if ($fallback['category_slug'] === '') {
                $fallback = $pair;
            }
            if ($preferredTemplateTag !== '' && normalizeFyndText($tag) === normalizeFyndText($preferredTemplateTag)) {
                $cache[$cacheKey] = $pair;
                return $cache[$cacheKey];
            }
        }

        foreach (listFyndTemplateCategories($department['uid'], $token) as $item) {
            $slug = fyndTemplateCategorySlugFromItem($item);
            $tag = fyndTemplateSlugFromItem($item);
            if ($slug === '' || $tag === '') {
                continue;
            }

            $pair = [
                'department_uid' => intval($department['uid']),
                'department_name' => $department['name'],
                'category_slug' => $slug,
                'template_tag' => $tag,
                'source' => 'template_category'
            ];

            if ($fallback['category_slug'] === '') {
                $fallback = $pair;
            }
            if ($preferredTemplateTag !== '' && normalizeFyndText($tag) === normalizeFyndText($preferredTemplateTag)) {
                $cache[$cacheKey] = $pair;
                return $cache[$cacheKey];
            }
        }
    }

    $cache[$cacheKey] = $fallback;
    return $cache[$cacheKey];
}

function fyndCategoryLevelFromItem($item) {
    if (!is_array($item)) {
        return 0;
    }
    if (!empty($item['level']) && is_numeric($item['level'])) {
        return intval($item['level']);
    }
    if (!empty($item['hierarchy']) && is_array($item['hierarchy'])) {
        $first = reset($item['hierarchy']);
        if (is_array($first)) {
            if (!empty($first['l3'])) return 3;
            if (!empty($first['l2'])) return 2;
            if (!empty($first['l1'])) return 1;
        }
    }

    return 0;
}

function findFyndFirstListCategory($token) {
    static $cache = null;

    if ($cache !== null) {
        return $cache;
    }

    $targetCategoryName = fyndTargetCategoryName();
    $targetSlug = strtolower(preg_replace('/[^a-z0-9]+/', '-', $targetCategoryName));
    $queries = [
        ['slug' => $targetSlug, 'page_no' => 1, 'page_size' => 25],
        ['q' => $targetCategoryName, 'level' => 3, 'page_no' => 1, 'page_size' => 25],
        ['q' => $targetCategoryName, 'page_no' => 1, 'page_size' => 25]
    ];
    $fallback = ['department_uid' => 0, 'department_name' => '', 'category_slug' => '', 'template_tag' => 'supplementary', 'source' => 'list_categories_' . $targetSlug];

    foreach ($queries as $index => $query) {
        $response = fyndPlatformRequest(
            'GET',
            '/service/platform/catalog/v1.0/company/' . FYND_COMPANY_ID . '/category/',
            $token,
            $query,
            null,
            'auto_defaults_list_categories_' . ($index + 1)
        );

        $items = fyndListItems($response['body']);
        $targetItem = firstMatchingFyndItem($items, $targetCategoryName, ['name', 'slug', 'category_slug']);
        $orderedItems = [];
        if ($targetItem) {
            $orderedItems[] = $targetItem;
        }
        foreach ($items as $item) {
            if ($item !== $targetItem) {
                $orderedItems[] = $item;
            }
        }

        foreach ($orderedItems as $item) {
            $slug = fyndCategorySlugFromItem($item);
            $departmentUid = fyndCategoryDepartmentUidFromItem($item);
            if ($slug === '' || !$departmentUid) {
                continue;
            }

            $candidate = [
                'department_uid' => $departmentUid,
                'department_name' => '',
                'category_slug' => $slug,
                'template_tag' => 'supplementary',
                'source' => 'list_categories'
            ];

            if ($fallback['category_slug'] === '') {
                $fallback = $candidate;
            }
            if (fyndCategoryLevelFromItem($item) === 3) {
                $cache = $candidate;
                return $cache;
            }
        }
    }

    $cache = $fallback;
    return $cache;
}

function findFyndSupplementaryTemplatePair($token, $preferredTemplateTag = 'supplementary') {
    $preferredTemplateTag = $preferredTemplateTag !== '' ? $preferredTemplateTag : 'supplementary';
    $listCategory = findFyndFirstListCategory($token);
    $departmentUids = [];

    $configuredDepartmentUid = intval(getCatalogSetting('department_uid', defined('FYND_DEFAULT_DEPARTMENT_UID') ? (string) FYND_DEFAULT_DEPARTMENT_UID : '0'));
    if ($configuredDepartmentUid > 0) {
        $departmentUids[] = $configuredDepartmentUid;
    }
    if (!empty($listCategory['department_uid'])) {
        $departmentUids[] = intval($listCategory['department_uid']);
    }

    $departmentUids = array_values(array_unique(array_filter($departmentUids)));
    foreach ($departmentUids as $departmentUid) {
        foreach (listFyndProductTemplates($departmentUid, $token) as $template) {
            $tag = fyndTemplateTagFromTemplate($template);
            if (normalizeFyndText($tag) !== normalizeFyndText($preferredTemplateTag)) {
                continue;
            }

            $categorySlug = pickCategoryFromTemplate($template, fyndTargetCategoryName());
            if ($categorySlug !== '') {
                return [
                    'department_uid' => $departmentUid,
                    'department_name' => '',
                    'category_slug' => $categorySlug,
                    'template_tag' => $tag,
                    'source' => 'product_template_' . $preferredTemplateTag
                ];
            }
        }

        foreach (listFyndTemplateCategories($departmentUid, $token) as $item) {
            $slug = fyndTemplateCategorySlugFromItem($item);
            $tag = fyndTemplateSlugFromItem($item);
            if ($slug !== '' && normalizeFyndText($tag) === normalizeFyndText($preferredTemplateTag)) {
                return [
                    'department_uid' => $departmentUid,
                    'department_name' => '',
                    'category_slug' => $slug,
                    'template_tag' => $tag,
                    'source' => 'template_categories_' . $preferredTemplateTag
                ];
            }
        }
    }

    return ['department_uid' => 0, 'department_name' => '', 'category_slug' => '', 'template_tag' => $preferredTemplateTag, 'source' => ''];
}

function fyndTaxRuleItemMatchesPercent($item, $taxPercent) {
    if ($taxPercent <= 0 || !is_array($item) || empty($item['versions']) || !is_array($item['versions'])) {
        return false;
    }

    $expected = floatval($taxPercent) / 100;
    foreach ($item['versions'] as $version) {
        $components = isset($version['components']) && is_array($version['components']) ? $version['components'] : [];
        foreach ($components as $component) {
            $slabs = isset($component['slabs']) && is_array($component['slabs']) ? $component['slabs'] : [];
            foreach ($slabs as $slab) {
                if (isset($slab['rate']) && abs(floatval($slab['rate']) - $expected) < 0.0001) {
                    return true;
                }
            }
        }
    }

    return false;
}

function resolveFyndTaxRuleIdFast($token, $taxPercent = 0) {
    $configured = configuredFyndTaxRuleId();
    if ($configured !== '' && $taxPercent <= 0) {
        return $configured;
    }

    $items = listFyndTaxRules($token, ['statuses' => 'ACTIVE', 'version_status' => 'LIVE', 'page' => 1, 'limit' => 25], 'auto_defaults_tax_rules');
    $fallback = '';
    $default = '';

    foreach ($items as $item) {
        $id = fyndTaxRuleIdFromItem($item);
        if ($id === '') {
            continue;
        }
        if ($fallback === '') {
            $fallback = $id;
        }
        if (fyndTaxRuleItemMatchesPercent($item, $taxPercent)) {
            return $id;
        }
        if (fyndTaxRuleIsDefault($item)) {
            $default = $id;
        }
    }

    if ($configured !== '') {
        return $configured;
    }

    return $default !== '' ? $default : $fallback;
}

function autoSaveFyndCatalogDefaults($token) {
    if (fyndFixedMappingMode()) {
        return [
            'ok' => false,
            'message' => 'Auto Pick is disabled in fixed mapping mode. Save one manual department_uid + category_slug pair in Catalog Defaults and then run Check All Payloads.',
            'settings' => []
        ];
    }

    $preferredTemplateTag = configuredFyndTemplateTag();
    $preferredTemplateTag = $preferredTemplateTag !== '' ? $preferredTemplateTag : 'supplementary';
    $pair = findFyndSupplementaryTemplatePair($token, $preferredTemplateTag);
    if ($pair['category_slug'] === '' || $pair['template_tag'] === '' || !$pair['department_uid']) {
        $validatedPair = fyndTemplateValidationPair($preferredTemplateTag, 'standard', $token);
        if ($validatedPair['category_slug'] !== '' && $validatedPair['department_uid']) {
            $pair = [
                'category_slug' => $validatedPair['category_slug'],
                'department_uid' => $validatedPair['department_uid'],
                'template_tag' => $validatedPair['template_tag'],
                'source' => 'template_validation'
            ];
        }
    }
    if ($pair['category_slug'] === '' || $pair['template_tag'] === '' || !$pair['department_uid']) {
        $validatedPair = fyndTemplateValidationPair($preferredTemplateTag, 'standard', $token);
        $details = [];
        if (!empty($validatedPair['categories'])) {
            $details[] = 'validated categories=' . implode(',', $validatedPair['categories']);
        }
        if (!empty($validatedPair['departments'])) {
            $details[] = 'validated departments=' . implode(',', $validatedPair['departments']);
        }
        $suffix = !empty($details) ? ' Fynd template validation returned ' . implode('; ', $details) . '.' : ' Fynd template validation also returned no usable categories/departments.';
        return ['ok' => false, 'message' => 'No category allowed by the Supplementary product template was returned by Fynd. The app checked /products/templates/, /products/templates/categories/, and /products/templates/{slug}/validation/schema/.'.$suffix.' Save one manual department_uid + category_slug pair in Catalog Defaults.', 'settings' => []];
    }

    $brandUid = resolveFyndBrandUid('', $token);
    $taxRuleId = resolveFyndTaxRuleIdFast($token);

    if (!$brandUid || $taxRuleId === '') {
        $missing = [];
        if (!$brandUid) $missing[] = 'brand_uid';
        if ($taxRuleId === '') $missing[] = 'tax_rule_id';
        return ['ok' => false, 'message' => 'Could not auto-resolve: ' . implode(', ', $missing), 'settings' => []];
    }

    $settings = [
        'brand_uid' => (string) $brandUid,
        'department_uid' => (string) $pair['department_uid'],
        'category_slug' => $pair['category_slug'],
        'template_tag' => $pair['template_tag'],
        'tax_rule_id' => $taxRuleId
    ];

    foreach ($settings as $key => $value) {
        setCatalogSetting($key, $value);
    }

    return ['ok' => true, 'message' => 'Catalog defaults auto-saved from ' . $pair['source'] . ': category=' . $pair['category_slug'] . ', department=' . $pair['department_uid'] . '.', 'settings' => $settings];
}

function fyndTemplateTagFromTemplate($template) {
    if (!is_array($template)) {
        return '';
    }
    if (!empty($template['tag'])) return $template['tag'];
    if (!empty($template['slug'])) return $template['slug'];
    return '';
}

function fyndTemplateCategoriesFromTemplate($template) {
    return isset($template['categories']) && is_array($template['categories']) ? $template['categories'] : [];
}

function categorySlugLooksRelevant($slug, $categoryName) {
    $slugText = normalizeFyndText(str_replace('-', ' ', $slug));
    $categoryText = normalizeFyndText($categoryName);

    if ($categoryText === '') {
        return false;
    }
    if ($slugText === $categoryText) {
        return true;
    }
    if (strpos($slugText, $categoryText) !== false) {
        return true;
    }

    $words = preg_split('/\s+/', $categoryText);
    foreach ($words as $word) {
        if (strlen($word) >= 4 && strpos($slugText, $word) !== false) {
            return true;
        }
    }

    return false;
}

function pickCategoryFromTemplate($template, $categoryName) {
    $categories = fyndTemplateCategoriesFromTemplate($template);
    if (empty($categories)) {
        return '';
    }

    foreach ($categories as $slug) {
        if (categorySlugLooksRelevant($slug, $categoryName)) {
            return $slug;
        }
    }

    return $categories[0];
}

function resolveFyndTemplateAndCategory($categoryName, $departmentUid, $preferredTemplateTag, $token) {
    static $cache = [];

    if (!$departmentUid) {
        return ['category_slug' => '', 'template_tag' => ''];
    }

    $key = strtolower(trim($categoryName)) . '|' . intval($departmentUid) . '|' . strtolower(trim($preferredTemplateTag));
    if (isset($cache[$key])) {
        return $cache[$key];
    }

    $templates = listFyndProductTemplates($departmentUid, $token);
    $preferred = null;
    $firstUsable = null;
    $matchingTemplate = null;
    $matchingCategory = '';

    foreach ($templates as $template) {
        $tag = fyndTemplateTagFromTemplate($template);
        $categories = fyndTemplateCategoriesFromTemplate($template);
        if ($tag === '' || empty($categories)) {
            continue;
        }

        if ($firstUsable === null) {
            $firstUsable = $template;
        }
        if ($preferredTemplateTag !== '' && normalizeFyndText($tag) === normalizeFyndText($preferredTemplateTag)) {
            $preferred = $template;
        }
        foreach ($categories as $slug) {
            if ($matchingTemplate === null && categorySlugLooksRelevant($slug, $categoryName)) {
                $matchingTemplate = $template;
                $matchingCategory = $slug;
            }
        }
    }

    if ($preferred !== null) {
        $cache[$key] = [
            'category_slug' => pickCategoryFromTemplate($preferred, $categoryName),
            'template_tag' => fyndTemplateTagFromTemplate($preferred)
        ];
        return $cache[$key];
    }

    if ($matchingTemplate !== null) {
        $cache[$key] = [
            'category_slug' => $matchingCategory,
            'template_tag' => fyndTemplateTagFromTemplate($matchingTemplate)
        ];
        return $cache[$key];
    }

    if ($firstUsable !== null) {
        $cache[$key] = [
            'category_slug' => pickCategoryFromTemplate($firstUsable, $categoryName),
            'template_tag' => fyndTemplateTagFromTemplate($firstUsable)
        ];
        return $cache[$key];
    }

    $templateCategory = resolveFyndTemplateCategoryMetadata($categoryName, $departmentUid, $preferredTemplateTag, $token);
    if ($templateCategory['slug'] !== '' && $templateCategory['template_tag'] !== '') {
        $cache[$key] = [
            'category_slug' => $templateCategory['slug'],
            'template_tag' => $templateCategory['template_tag']
        ];
        return $cache[$key];
    }

    $cache[$key] = ['category_slug' => '', 'template_tag' => ''];
    return $cache[$key];
}

function addFyndCategoryTemplateCandidate(&$candidates, &$seen, $categorySlug, $templateTag, $source, $categoryName) {
    $categorySlug = trim((string) $categorySlug);
    $templateTag = trim((string) $templateTag);
    if ($categorySlug === '' || $templateTag === '') {
        return;
    }

    $key = strtolower($categorySlug . '|' . $templateTag);
    if (isset($seen[$key])) {
        return;
    }

    $seen[$key] = true;
    $candidates[] = [
        'category_slug' => $categorySlug,
        'template_tag' => $templateTag,
        'source' => $source,
        'relevant' => categorySlugLooksRelevant($categorySlug, $categoryName) ? 1 : 0
    ];
}

function fyndProductCategoryTemplateFromItem($item) {
    if (!is_array($item)) {
        return ['category_slug' => '', 'template_tag' => '', 'department_uid' => 0];
    }

    $categorySlug = '';
    if (!empty($item['category_slug'])) {
        $categorySlug = $item['category_slug'];
    } elseif (!empty($item['category']['slug'])) {
        $categorySlug = $item['category']['slug'];
    } elseif (!empty($item['category']['category_slug'])) {
        $categorySlug = $item['category']['category_slug'];
    }

    $templateTag = '';
    if (!empty($item['template_tag'])) {
        $templateTag = $item['template_tag'];
    } elseif (!empty($item['template']['tag'])) {
        $templateTag = $item['template']['tag'];
    } elseif (!empty($item['template']['slug'])) {
        $templateTag = $item['template']['slug'];
    }

    return [
        'category_slug' => $categorySlug,
        'template_tag' => $templateTag,
        'department_uid' => fyndCategoryDepartmentUidFromItem($item)
    ];
}

function fyndExistingProductReferences() {
    $references = [];

    try {
        $rows = getDB()->query("SELECT sku, name FROM products WHERE status = 'ingested' ORDER BY updated_at DESC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            $sku = trim(isset($row['sku']) ? $row['sku'] : '');
            $name = trim(isset($row['name']) ? $row['name'] : '');
            if ($sku !== '') {
                $references[] = ['sku' => $sku, 'name' => $name];
            }
        }
    } catch (Exception $e) {
    }

    if (empty($references)) {
        $references[] = ['sku' => 'SKU006', 'name' => 'Hoodie Pullover'];
    }

    return $references;
}

function findFyndProductItemByReference($itemCode, $name, $brandUid, $token) {
    $queries = [];
    $itemCode = trim((string) $itemCode);
    $name = trim((string) $name);
    $slug = buildFyndSlug($name, $itemCode);

    if ($itemCode !== '' && $brandUid > 0) {
        $queries[] = ['query' => ['item_code' => $itemCode, 'brand_uid' => intval($brandUid)], 'label' => 'existing_product_by_item_code_brand_' . $itemCode];
    }
    if ($itemCode !== '') {
        $queries[] = ['query' => ['item_code' => $itemCode], 'label' => 'existing_product_by_item_code_' . $itemCode];
        $queries[] = ['query' => ['q' => $itemCode, 'page_no' => 1, 'page_size' => 10], 'label' => 'existing_product_by_q_sku_' . $itemCode];
    }
    if ($slug !== '') {
        $queries[] = ['query' => ['slug' => $slug], 'label' => 'existing_product_by_slug_' . $itemCode];
        $queries[] = ['query' => ['q' => $slug, 'page_no' => 1, 'page_size' => 10], 'label' => 'existing_product_by_q_slug_' . $itemCode];
    }
    if ($name !== '') {
        $queries[] = ['query' => ['q' => $name, 'page_no' => 1, 'page_size' => 10], 'label' => 'existing_product_by_name_' . $itemCode];
    }

    foreach ($queries as $entry) {
        $response = fyndPlatformRequest(
            'GET',
            '/service/platform/catalog/v1.0/company/' . FYND_COMPANY_ID . '/products/',
            $token,
            $entry['query'],
            null,
            $entry['label']
        );

        $item = firstFyndListItem($response['body']);
        if (!$item && is_array($response['body'])) {
            $item = $response['body'];
        }

        $pair = fyndProductCategoryTemplateFromItem($item);
        if ($pair['category_slug'] !== '' && $pair['template_tag'] !== '') {
            return $item;
        }
    }

    return [];
}

function findFyndExistingProductCategoryTemplate($brandUid, $token) {
    $references = fyndExistingProductReferences();

    foreach ($references as $reference) {
        $item = findFyndProductItemByReference(
            isset($reference['sku']) ? $reference['sku'] : '',
            isset($reference['name']) ? $reference['name'] : '',
            intval($brandUid),
            $token
        );
        $pair = fyndProductCategoryTemplateFromItem($item);
        if ($pair['category_slug'] !== '' && $pair['template_tag'] !== '') {
            return $pair;
        }
    }

    return ['category_slug' => '', 'template_tag' => '', 'department_uid' => 0];
}

function listFyndCategoryTemplateCandidates($departmentUid, $categoryName, $preferredTemplateTag, $token, $brandUid = 0) {
    $candidates = [];
    $seen = [];

    if ($brandUid) {
        $existingPair = findFyndExistingProductCategoryTemplate($brandUid, $token);
        addFyndCategoryTemplateCandidate(
            $candidates,
            $seen,
            $existingPair['category_slug'],
            $existingPair['template_tag'],
            'existing_product',
            $categoryName
        );
    }

    foreach (listFyndProductTemplates($departmentUid, $token) as $template) {
        $tag = fyndTemplateTagFromTemplate($template);
        foreach (fyndTemplateCategoriesFromTemplate($template) as $categorySlug) {
            addFyndCategoryTemplateCandidate($candidates, $seen, $categorySlug, $tag, 'product_templates', $categoryName);
        }
    }

    $response = fyndPlatformRequest(
        'GET',
        '/service/platform/catalog/v1.0/company/' . FYND_COMPANY_ID . '/products/templates/categories/',
        $token,
        ['departments' => (string) intval($departmentUid), 'item_type' => 'standard'],
        null,
        'category_template_candidates_' . intval($departmentUid)
    );

    foreach (fyndListItems($response['body']) as $item) {
        addFyndCategoryTemplateCandidate(
            $candidates,
            $seen,
            fyndTemplateCategorySlugFromItem($item),
            fyndTemplateSlugFromItem($item),
            'template_categories',
            $categoryName
        );
    }

    usort($candidates, function ($a, $b) use ($preferredTemplateTag) {
        $aPreferred = normalizeFyndText($a['template_tag']) === normalizeFyndText($preferredTemplateTag) ? 1 : 0;
        $bPreferred = normalizeFyndText($b['template_tag']) === normalizeFyndText($preferredTemplateTag) ? 1 : 0;
        if ($aPreferred !== $bPreferred) return $bPreferred - $aPreferred;
        if ($a['relevant'] !== $b['relevant']) return $b['relevant'] - $a['relevant'];
        return strcmp($a['category_slug'], $b['category_slug']);
    });

    return $candidates;
}

function listFyndProductAttributes($categorySlug, $token) {
    static $cache = [];

    if ($categorySlug === '') {
        return [];
    }
    if (isset($cache[$categorySlug])) {
        return $cache[$categorySlug];
    }

    $response = fyndPlatformRequest(
        'GET',
        '/service/platform/catalog/v1.0/company/' . FYND_COMPANY_ID . '/product-attributes/',
        $token,
        ['category' => $categorySlug],
        null,
        'product_attributes_' . $categorySlug
    );

    $cache[$categorySlug] = fyndListItems($response['body']);
    return $cache[$categorySlug];
}

function fyndAttributeKey($attribute) {
    if (!is_array($attribute)) {
        return '';
    }
    if (!empty($attribute['raw_key'])) return $attribute['raw_key'];
    if (!empty($attribute['slug'])) return $attribute['slug'];
    return '';
}

function fyndAttributeIsMandatory($attribute) {
    return !empty($attribute['schema']['mandatory']);
}

function fyndPickAllowedValue($attribute, $preferredValue) {
    $allowed = isset($attribute['schema']['allowed_values']) && is_array($attribute['schema']['allowed_values'])
        ? $attribute['schema']['allowed_values']
        : [];

    if (empty($allowed)) {
        return $preferredValue;
    }

    foreach ($allowed as $value) {
        if (normalizeFyndText($value) === normalizeFyndText($preferredValue)) {
            return $value;
        }
    }

    return $allowed[0];
}

function fyndMandatoryAttributeValue($attribute, $product) {
    $label = normalizeFyndText(
        (isset($attribute['name']) ? $attribute['name'] : '') . ' ' .
        (isset($attribute['slug']) ? $attribute['slug'] : '') . ' ' .
        (isset($attribute['raw_key']) ? $attribute['raw_key'] : '')
    );
    $type = isset($attribute['schema']['type']) ? strtolower($attribute['schema']['type']) : 'text';
    $color = '';
    if (isset($product['attributes']['primary_color'])) {
        $color = cleanFyndDescriptionText($product['attributes']['primary_color']);
    } elseif (isset($product['attributes']['color'])) {
        $color = cleanFyndDescriptionText($product['attributes']['color']);
    }

    if (strpos($label, 'description') !== false) {
        return fyndPickAllowedValue($attribute, buildFyndProductDescription($product));
    }
    if (strpos($label, 'color') !== false && $color !== '') {
        return fyndPickAllowedValue($attribute, $color);
    }
    if (strpos($label, 'gender') !== false) {
        return fyndPickAllowedValue($attribute, 'Unisex');
    }
    if (strpos($label, 'material') !== false || strpos($label, 'fabric') !== false) {
        return fyndPickAllowedValue($attribute, 'Cotton');
    }
    if (strpos($label, 'country') !== false || strpos($label, 'origin') !== false) {
        return fyndPickAllowedValue($attribute, isset($product['country_of_origin']) ? $product['country_of_origin'] : 'India');
    }
    if (strpos($label, 'brand') !== false) {
        return fyndPickAllowedValue($attribute, isset($product['legacy_brand_name']) ? $product['legacy_brand_name'] : FYND_TRADER_NAME);
    }

    if (strpos($type, 'int') !== false || strpos($type, 'float') !== false || strpos($type, 'number') !== false) {
        return 1;
    }
    if (strpos($type, 'bool') !== false) {
        return true;
    }

    return fyndPickAllowedValue($attribute, 'NA');
}

function buildFyndMandatoryAttributes($categorySlug, $product, $token) {
    $attributes = [];
    foreach (listFyndProductAttributes($categorySlug, $token) as $attribute) {
        if (!fyndAttributeIsMandatory($attribute)) {
            continue;
        }

        $key = fyndAttributeKey($attribute);
        if ($key === '') {
            continue;
        }

        $attributes[$key] = fyndMandatoryAttributeValue($attribute, $product);
    }

    return $attributes;
}

function configuredFyndTaxRuleId() {
    $defaultTaxRuleId = defined('FYND_DEFAULT_TAX_RULE_ID') ? trim(FYND_DEFAULT_TAX_RULE_ID) : '';
    $candidate = getCatalogSetting('tax_rule_id', $defaultTaxRuleId);
    if ($candidate === '' && $defaultTaxRuleId !== '') {
        $candidate = $defaultTaxRuleId;
    }
    $placeholders = ['', '0', '123', 'your-tax-rule-id', 'tax-rule-id', 'your_tax_rule_id'];

    return in_array(strtolower($candidate), $placeholders) ? '' : $candidate;
}

function fyndTaxRuleIdFromItem($item) {
    if (!is_array($item)) {
        return '';
    }

    $rule = isset($item['rule']) && is_array($item['rule']) ? $item['rule'] : $item;

    if (!empty($rule['_id'])) return $rule['_id'];
    if (!empty($rule['id'])) return $rule['id'];
    if (!empty($rule['uid'])) return $rule['uid'];

    return '';
}

function fyndTaxRuleNameFromItem($item) {
    $rule = isset($item['rule']) && is_array($item['rule']) ? $item['rule'] : $item;
    return isset($rule['name']) ? $rule['name'] : '';
}

function fyndTaxRuleIsDefault($item) {
    $rule = isset($item['rule']) && is_array($item['rule']) ? $item['rule'] : $item;
    return !empty($rule['is_default']);
}

function getFyndTaxRuleVersions($ruleId, $token) {
    return fyndPlatformRequest(
        'GET',
        '/service/platform/catalog/v1.0/company/' . FYND_COMPANY_ID . '/taxes/rules/' . rawurlencode($ruleId) . '/versions',
        $token,
        ['version_status' => 'LIVE', 'page' => 1, 'limit' => 25],
        null,
        'tax_rule_versions'
    );
}

function fyndTaxRuleHasUsableVersion($ruleId, $token) {
    if ($ruleId === '') {
        return false;
    }

    $response = getFyndTaxRuleVersions($ruleId, $token);
    if ($response['code'] < 200 || $response['code'] >= 300 || !is_array($response['body'])) {
        return false;
    }

    if (isset($response['body']['items']) && is_array($response['body']['items']) && count($response['body']['items']) > 0) {
        return true;
    }

    if (isset($response['body']['versions']) && is_array($response['body']['versions']) && count($response['body']['versions']) > 0) {
        return true;
    }

    return false;
}

function fyndTaxRuleMatchesRate($ruleId, $token, $taxPercent) {
    if ($taxPercent <= 0) {
        return false;
    }

    $response = getFyndTaxRuleVersions($ruleId, $token);
    $items = isset($response['body']['items']) && is_array($response['body']['items']) ? $response['body']['items'] : [];

    foreach ($items as $item) {
        $version = isset($item['version']) && is_array($item['version']) ? $item['version'] : $item;
        $components = isset($version['components']) && is_array($version['components']) ? $version['components'] : [];

        foreach ($components as $component) {
            $slabs = isset($component['slabs']) && is_array($component['slabs']) ? $component['slabs'] : [];
            foreach ($slabs as $slab) {
                if (isset($slab['rate']) && abs(floatval($slab['rate']) - floatval($taxPercent)) < 0.001) {
                    return true;
                }
            }
        }
    }

    return false;
}

function listFyndTaxRules($token, $query, $label) {
    $response = fyndPlatformRequest(
        'GET',
        '/service/platform/catalog/v1.0/company/' . FYND_COMPANY_ID . '/taxes/rules',
        $token,
        $query,
        null,
        $label
    );

    return isset($response['body']['items']) && is_array($response['body']['items']) ? $response['body']['items'] : [];
}

function createFyndTaxRule($token, $taxPercent) {
    if (!defined('FYND_AUTO_CREATE_TAX_RULE') || !FYND_AUTO_CREATE_TAX_RULE) {
        return '';
    }

    $rate = $taxPercent > 0 ? floatval($taxPercent) : 5.0;
    $body = [
        'rule' => [
            'name' => 'Auto GST ' . rtrim(rtrim(number_format($rate, 2, '.', ''), '0'), '.') . '%',
            'description' => 'Auto-created by catalog transformer extension.'
        ],
        'versions' => [[
            'scope' => 'COUNTRY',
            'components' => [[
                'name' => FYND_TAX_COMPONENT_NAME,
                'slabs' => [[
                    'value' => 0,
                    'rate' => $rate
                ]]
            ]],
            'applicable_date' => date('Y-m-d'),
            'region_type' => 'country',
            'areas' => [
                'country' => 'India',
                'regions' => []
            ]
        ]]
    ];

    $response = fyndPlatformRequest(
        'POST',
        '/service/platform/catalog/v1.0/company/' . FYND_COMPANY_ID . '/taxes/rules/versions',
        $token,
        [],
        $body,
        'create_tax_rule'
    );

    if ($response['code'] < 200 || $response['code'] >= 300 || !is_array($response['body'])) {
        return '';
    }

    return fyndTaxRuleIdFromItem($response['body']);
}

function resolveFyndTaxRuleId($token, $taxPercent = 0) {
    static $cached = null;

    $configured = configuredFyndTaxRuleId();
    if ($configured !== '') {
        if (fyndTaxRuleHasUsableVersion($configured, $token)) {
            return $configured;
        }

        writeFyndMetadataDebug('invalid_config_tax_rule', 'config.php', 0, 'Configured tax rule is not valid or has no LIVE version.', $configured);
    }

    if ($cached !== null) {
        return $cached;
    }

    $items = listFyndTaxRules($token, ['statuses' => 'ACTIVE', 'version_status' => 'LIVE', 'page' => 1, 'limit' => 50], 'resolve_tax_rule_active_live');
    if (empty($items)) {
        $items = listFyndTaxRules($token, ['statuses' => 'ACTIVE', 'page' => 1, 'limit' => 50], 'resolve_tax_rule_active');
    }
    if (empty($items)) {
        $items = listFyndTaxRules($token, ['page' => 1, 'limit' => 50], 'resolve_tax_rule_all');
    }

    $fallback = '';
    $default = '';
    $matchingRate = '';

    foreach ($items as $item) {
        $id = fyndTaxRuleIdFromItem($item);
        if ($id === '' || !fyndTaxRuleHasUsableVersion($id, $token)) {
            continue;
        }

        if ($fallback === '') $fallback = $id;
        if ($default === '' && fyndTaxRuleIsDefault($item)) $default = $id;
        if ($matchingRate === '' && fyndTaxRuleMatchesRate($id, $token, $taxPercent)) $matchingRate = $id;
    }

    if ($matchingRate !== '') {
        $cached = $matchingRate;
        return $cached;
    }

    if ($default !== '') {
        $cached = $default;
        return $cached;
    }

    if ($fallback !== '') {
        $cached = $fallback;
        return $cached;
    }

    $cached = createFyndTaxRule($token, $taxPercent);
    return $cached;
}

function cleanFyndDescriptionText($value) {
    $value = html_entity_decode((string) $value, ENT_QUOTES, 'UTF-8');
    $value = strip_tags($value);
    $value = preg_replace('/[\x00-\x1F\x7F]+/', ' ', $value);
    $value = preg_replace('/[^A-Za-z0-9 .,]/', ' ', $value);
    $value = preg_replace('/\s+/', ' ', $value);
    return trim($value);
}

function limitFyndText($value, $maxLength) {
    $value = trim((string) $value);
    if (strlen($value) <= $maxLength) {
        return $value;
    }

    return rtrim(substr($value, 0, $maxLength - 1)) . '.';
}

function buildFyndProductDescription($product) {
    $name = cleanFyndDescriptionText(isset($product['name']) ? $product['name'] : 'Product');
    if ($name === '') {
        $name = 'Product';
    }

    return limitFyndText($name, 240);
}

function buildFyndSlug($name, $itemCode) {
    $base = strtolower((string) $name);
    $base = preg_replace('/[^a-z0-9]+/', '-', $base);
    $base = trim($base, '-');
    $suffix = strtolower((string) $itemCode);
    $suffix = preg_replace('/[^a-z0-9]+/', '-', $suffix);
    $suffix = trim($suffix, '-');

    if ($base === '') {
        $base = 'product';
    }
    if ($suffix !== '' && strpos($base, $suffix) === false) {
        $base .= '-' . $suffix;
    }

    return substr($base, 0, 120);
}

function buildFyndProductSizes($product) {
    $sizes = isset($product['sizes']) && is_array($product['sizes']) ? $product['sizes'] : [];
    $currency = isset($product['currency']) && $product['currency'] !== '' ? $product['currency'] : 'INR';
    $itemCode = isset($product['item_code']) ? $product['item_code'] : 'SKU';
    $result = [];

    foreach ($sizes as $size) {
        $sizeName = isset($size['size']) && trim($size['size']) !== '' ? trim($size['size']) : 'One Size';
        $sellerIdentifier = isset($size['seller_identifier']) && trim($size['seller_identifier']) !== '' ? trim($size['seller_identifier']) : $itemCode;
        $effective = isset($size['price_effective']) ? floatval($size['price_effective']) : (isset($size['price']['effective']) ? floatval($size['price']['effective']) : 0);
        $transfer = isset($size['price_transfer']) ? floatval($size['price_transfer']) : (isset($size['price']['transfer']) ? floatval($size['price']['transfer']) : 0);
        $marked = isset($size['price_marked']) ? floatval($size['price_marked']) : (isset($size['price']) && is_numeric($size['price']) ? floatval($size['price']) : (isset($size['price']['marked']) ? floatval($size['price']['marked']) : $effective));
        if ($marked < $effective) {
            $marked = $effective;
        }
        $identifiers = [];
        if (isset($size['identifiers']) && is_array($size['identifiers'])) {
            foreach ($size['identifiers'] as $identifier) {
                if (!is_array($identifier)) {
                    continue;
                }
                $gtinValue = isset($identifier['gtin_value']) ? trim((string) $identifier['gtin_value']) : '';
                if ($gtinValue === '') {
                    continue;
                }
                $gtinType = isset($identifier['gtin_type']) ? strtolower(trim((string) $identifier['gtin_type'])) : '';
                $allowedGtinTypes = ['ean', 'alu', 'upc', 'sku_code', 'isbn', 'vendor_sku'];
                if ($gtinType === 'sku' || $gtinType === '') {
                    $gtinType = 'sku_code';
                }
                if (!in_array($gtinType, $allowedGtinTypes, true)) {
                    $gtinType = 'sku_code';
                }
                $identifiers[] = [
                    'gtin_type' => $gtinType,
                    'gtin_value' => $gtinValue,
                    'primary' => !empty($identifier['primary'])
                ];
            }
        }
        if (empty($identifiers)) {
            $identifiers[] = [
                'gtin_type' => 'sku_code',
                'gtin_value' => $sellerIdentifier,
                'primary' => true
            ];
        }

        $entry = [
            'size' => $sizeName,
            'identifiers' => $identifiers,
            'seller_identifier' => $sellerIdentifier,
            'price' => $marked,
            'price_effective' => $effective,
            'price_marked' => $marked,
            'price_transfer' => $transfer,
            'quantity' => isset($size['quantity']) ? intval($size['quantity']) : 0,
            'track_inventory' => true,
            'item_length' => isset($size['item_length']) ? floatval($size['item_length']) : 25,
            'item_width' => isset($size['item_width']) ? floatval($size['item_width']) : 20,
            'item_height' => isset($size['item_height']) ? floatval($size['item_height']) : 3,
            'item_dimensions_unit_of_measure' => isset($size['item_dimensions_unit_of_measure']) ? $size['item_dimensions_unit_of_measure'] : (isset($size['item_dimension_unit_of_measure']) ? $size['item_dimension_unit_of_measure'] : 'centimeter')
        ];

        if (isset($size['item_weight']) && floatval($size['item_weight']) > 0) {
            $entry['item_weight'] = floatval($size['item_weight']);
            $entry['item_weight_unit_of_measure'] = isset($size['item_weight_unit_of_measure']) ? $size['item_weight_unit_of_measure'] : 'gram';
        }

        $result[] = $entry;
    }

    if (empty($result)) {
        $result[] = [
            'size' => 'One Size',
            'identifiers' => [[
                'gtin_type' => 'sku_code',
                'gtin_value' => $itemCode,
                'primary' => true
            ]],
            'seller_identifier' => $itemCode,
            'price' => 1,
            'price_effective' => 1,
            'price_marked' => 1,
            'price_transfer' => 0,
            'quantity' => 0,
            'track_inventory' => true,
            'item_length' => 25,
            'item_width' => 20,
            'item_height' => 3,
            'item_dimensions_unit_of_measure' => 'centimeter'
        ];
    }

    return $result;
}

function validatePreparedFyndProductV3($product) {
    $errors = [];
    $required = [
        'brand_uid', 'category_slug', 'company_id', 'country_of_origin', 'currency',
        'departments', 'item_code', 'item_type', 'name', 'return_config',
        'sizes', 'slug', 'tax_identifier', 'template_tag', 'trader'
    ];

    foreach ($required as $field) {
        if (!isset($product[$field]) || $product[$field] === '' || $product[$field] === [] || $product[$field] === null) {
            $errors[] = $field . ' is missing';
        }
    }

    if (isset($product['brand_uid']) && intval($product['brand_uid']) <= 0) {
        $errors[] = 'brand_uid must be a positive number';
    }
    if (isset($product['departments']) && (!is_array($product['departments']) || empty($product['departments']) || intval($product['departments'][0]) <= 0)) {
        $errors[] = 'departments must contain a valid department UID';
    }
    if (isset($product['tax_identifier']['tax_rule_id']) && trim($product['tax_identifier']['tax_rule_id']) === '') {
        $errors[] = 'tax_identifier.tax_rule_id is missing';
    }
    if (!isset($product['tax_identifier']['tax_rule_id'])) {
        $errors[] = 'tax_identifier.tax_rule_id is missing';
    }
    if (!isset($product['sizes']) || !is_array($product['sizes']) || empty($product['sizes'])) {
        $errors[] = 'sizes must contain at least one size';
    } else {
        foreach ($product['sizes'] as $index => $size) {
            if (empty($size['seller_identifier'])) {
                $errors[] = 'sizes[' . $index . '].seller_identifier is missing';
            }
            if (empty($size['size'])) {
                $errors[] = 'sizes[' . $index . '].size is missing';
            }
            if (!isset($size['price_effective']) || floatval($size['price_effective']) <= 0) {
                $errors[] = 'sizes[' . $index . '].price_effective must be positive';
            }
            if (!isset($size['price']) || !is_numeric($size['price']) || floatval($size['price']) < floatval($size['price_effective'])) {
                $errors[] = 'sizes[' . $index . '].price must be greater than or equal to price_effective';
            }
            if (empty($size['identifiers']) || !is_array($size['identifiers'])) {
                $errors[] = 'sizes[' . $index . '].identifiers must contain at least one GTIN-style identifier';
            }
        }
    }

    if (isset($product['short_description']) && strlen($product['short_description']) > 50) {
        $errors[] = 'short_description is longer than 50 characters';
    }

    return $errors;
}

function prepareFyndProductV3($product, $token) {
    $brandName = isset($product['legacy_brand_name']) ? $product['legacy_brand_name'] : (isset($product['brand']) ? $product['brand'] : '');
    $categoryName = fyndTargetCategoryName();

    $brandUid = resolveFyndBrandUid($brandName, $token);
    $configuredCategorySlug = configuredFyndCategorySlug();
    $configuredTemplateTag = configuredFyndTemplateTag();
    $preferredTemplateTag = $configuredTemplateTag !== '' ? $configuredTemplateTag : (isset($product['template_tag']) ? $product['template_tag'] : 'supplementary');

    $departmentUid = intval(getCatalogSetting('department_uid', defined('FYND_DEFAULT_DEPARTMENT_UID') ? (string) FYND_DEFAULT_DEPARTMENT_UID : '0'));
    $categorySlug = $configuredCategorySlug;
    if (!fyndFixedMappingMode() && ($categorySlug === '' || !$departmentUid)) {
        $targetCategory = findFyndSupplementaryTemplatePair($token, $preferredTemplateTag);
        if ($categorySlug === '') {
            $categorySlug = $targetCategory['category_slug'];
        }
        if (!$departmentUid) {
            $departmentUid = intval($targetCategory['department_uid']);
        }
    }
    if ($categorySlug === '' || !$departmentUid) {
        $validatedPair = fyndTemplateValidationPair($preferredTemplateTag, isset($product['item_type']) && $product['item_type'] !== '' ? $product['item_type'] : 'standard', $token);
        if ($categorySlug === '' && $validatedPair['category_slug'] !== '') {
            $categorySlug = $validatedPair['category_slug'];
        }
        if (!$departmentUid && !empty($validatedPair['department_uid'])) {
            $departmentUid = intval($validatedPair['department_uid']);
        }
    }
    $validatedPair = fyndTemplateValidationPair($preferredTemplateTag, isset($product['item_type']) && $product['item_type'] !== '' ? $product['item_type'] : 'standard', $token);
    $resolvedCategoryMeta = resolveFyndCategoryMetadataBySlug($categorySlug !== '' ? $categorySlug : $configuredCategorySlug, $token);
    if (!$departmentUid && !empty($resolvedCategoryMeta['department_uid'])) {
        $departmentUid = intval($resolvedCategoryMeta['department_uid']);
    }
    if ($configuredCategorySlug !== '' && !empty($validatedPair['categories']) && !in_array($configuredCategorySlug, $validatedPair['categories'], true)) {
        return [
            'ok' => false,
            'error' => 'Configured category_slug "' . $configuredCategorySlug . '" is not allowed for template_tag "' . $preferredTemplateTag . '". Fynd template validation returned categories: ' . implode(', ', $validatedPair['categories']) . '.',
            'product' => $product
        ];
    }
    $validatedDepartments = [];
    if (!empty($validatedPair['departments'])) {
        foreach ($validatedPair['departments'] as $candidateDepartment) {
            $candidateDepartment = intval($candidateDepartment);
            if ($candidateDepartment > 0) {
                $validatedDepartments[] = $candidateDepartment;
            }
        }
    }
    if ($departmentUid && !empty($validatedDepartments) && !in_array(intval($departmentUid), $validatedDepartments, true)) {
        return [
            'ok' => false,
            'error' => 'Configured department_uid "' . $departmentUid . '" is not allowed for template_tag "' . $preferredTemplateTag . '". Fynd template validation returned departments: ' . implode(', ', $validatedDepartments) . '.',
            'product' => $product
        ];
    }
    if ($departmentUid && !empty($resolvedCategoryMeta['department_uid']) && intval($departmentUid) !== intval($resolvedCategoryMeta['department_uid'])) {
        return [
            'ok' => false,
            'error' => 'Configured department_uid "' . $departmentUid . '" does not match the department for category_slug "' . $categorySlug . '". Fynd category lookup returned department_uid ' . intval($resolvedCategoryMeta['department_uid']) . '.',
            'product' => $product
        ];
    }

    $existingProductPair = fyndFixedMappingMode() ? ['category_slug' => '', 'template_tag' => '', 'department_uid' => 0] : findFyndExistingProductCategoryTemplate($brandUid, $token);
    $isKnownInvalidSupplementaryPair =
        normalizeFyndText($preferredTemplateTag) === 'supplementary' &&
        normalizeFyndText($categorySlug) === 'others3';
    if (
        ($categorySlug === '' || !$departmentUid || $isKnownInvalidSupplementaryPair) &&
        !empty($existingProductPair['category_slug']) &&
        !empty($existingProductPair['template_tag'])
    ) {
        $categorySlug = $existingProductPair['category_slug'];
        $preferredTemplateTag = $existingProductPair['template_tag'];
        if (!$departmentUid && !empty($existingProductPair['department_uid'])) {
            $departmentUid = intval($existingProductPair['department_uid']);
        }
    }

    $product['template_tag'] = $preferredTemplateTag;

    $taxPercent = isset($product['legacy_tax_percent']) ? floatval($product['legacy_tax_percent']) : 0;
    $taxRuleId = resolveFyndTaxRuleIdFast($token, $taxPercent);

    if (!$brandUid || !$departmentUid || $categorySlug === '' || $taxRuleId === '') {
        $missing = [];
        if (!$brandUid) $missing[] = 'brand_uid';
        if (!$departmentUid) $missing[] = 'department_uid';
        if ($categorySlug === '') $missing[] = 'category_slug';
        if ($taxRuleId === '') $missing[] = 'tax_rule_id';

        return [
            'ok' => false,
            'error' => 'Could not resolve required Fynd catalog metadata: ' . implode(', ', $missing) . '. Fixed mapping mode is enabled, so save one valid department_uid + category_slug pair in Catalog Defaults before ingesting. The app also checked Fynd template validation for supplementary. Do not use others3 with supplementary.',
            'product' => $product
        ];
    }

    $sizes = buildFyndProductSizes($product);
    $mandatoryAttributes = buildFyndMandatoryAttributes($categorySlug, $product, $token);
    $description = buildFyndProductDescription($product);
    $shortDescription = limitFyndText(cleanFyndDescriptionText(isset($product['name']) ? $product['name'] : ''), 50);
    $media = [];
    if (isset($product['media']) && is_array($product['media'])) {
        foreach ($product['media'] as $mediaItem) {
            if (!is_array($mediaItem)) {
                continue;
            }
            $url = isset($mediaItem['url']) ? trim((string) $mediaItem['url']) : '';
            if ($url === '') {
                continue;
            }
            $media[] = [
                'type' => isset($mediaItem['type']) && trim((string) $mediaItem['type']) !== '' ? trim((string) $mediaItem['type']) : 'image',
                'url' => $url
            ];
        }
    }
    $payload = [
        'name' => limitFyndText(cleanFyndDescriptionText(isset($product['name']) ? $product['name'] : ''), 120),
        'slug' => buildFyndSlug(isset($product['name']) ? $product['name'] : '', isset($product['item_code']) ? $product['item_code'] : ''),
        'item_code' => trim(isset($product['item_code']) ? $product['item_code'] : ''),
        'item_type' => isset($product['item_type']) && $product['item_type'] !== '' ? $product['item_type'] : 'standard',
        'brand_uid' => $brandUid,
        'category_slug' => $categorySlug,
        'departments' => [$departmentUid],
        'company_id' => intval(FYND_COMPANY_ID),
        'country_of_origin' => isset($product['country_of_origin']) && $product['country_of_origin'] !== '' ? $product['country_of_origin'] : 'India',
        'currency' => isset($product['currency']) && $product['currency'] !== '' ? $product['currency'] : 'INR',
        'description' => $description,
        'is_active' => true,
        'is_set' => false,
        'is_image_less_product' => empty($media),
        'multi_size' => count($sizes) > 1,
        'sizes' => $sizes,
        'return_config' => ['returnable' => false, 'time' => 0, 'unit' => 'days'],
        'tax_identifier' => ['tax_rule_id' => $taxRuleId],
        'template_tag' => isset($product['template_tag']) && $product['template_tag'] !== '' ? $product['template_tag'] : 'supplementary',
        'trader' => [[
            'name' => FYND_TRADER_NAME,
            'type' => FYND_TRADER_TYPE,
            'address' => [FYND_TRADER_ADDRESS]
        ]]
    ];

    if ($shortDescription !== '') {
        $payload['short_description'] = $shortDescription;
    }

    if (!empty($media)) {
        $payload['media'] = $media;
    }

    if (!empty($mandatoryAttributes)) {
        $payload['attributes'] = $mandatoryAttributes;
    }

    if (!empty($product['legacy_hsn_code'])) {
        $payload['hs_code'] = $product['legacy_hsn_code'];
    }

    $payloadErrors = validatePreparedFyndProductV3($payload);
    if (!empty($payloadErrors)) {
        return [
            'ok' => false,
            'error' => 'Prepared Fynd payload is invalid: ' . implode('; ', $payloadErrors),
            'product' => $payload
        ];
    }

    return ['ok' => true, 'error' => '', 'product' => $payload];
}
