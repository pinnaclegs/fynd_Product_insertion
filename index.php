<?php
require_once 'config.php';
require_once 'fynd_oauth.php';

$db = getDB();
$oauthMessage = '';
$authorizationCode = getFyndAuthorizationCodeFromRequest();

if ($authorizationCode !== '') {
    $validation = validateFyndOAuthCallback();
    if (!$validation['ok']) {
        $oauthMessage = '<div class="card" style="border-color:#dc3545"><p class="error">Fynd authorization callback failed validation.</p><pre style="background:#f8f8f8;padding:10px;font-size:12px;overflow:auto">' . htmlspecialchars($validation['error'], ENT_QUOTES, 'UTF-8') . '</pre></div>';
    } else {
        $token = getFyndAccessToken($authorizationCode, fyndCurrentCompanyId());
        if ($token) {
            $oauthMessage = '<div class="card" style="border-color:#28a745"><p class="success">Fynd authorization connected. Offline token stored for ingestion.</p></div>';
        } else {
            $debugContent = file_exists(__DIR__ . '/token_debug.txt') ? file_get_contents(__DIR__ . '/token_debug.txt') : '';
            $oauthMessage = '<div class="card" style="border-color:#dc3545"><p class="error">Fynd authorization failed.</p><pre style="background:#f8f8f8;padding:10px;font-size:12px;overflow:auto">' . htmlspecialchars($debugContent, ENT_QUOTES, 'UTF-8') . '</pre></div>';
        }
    }
} elseif (!empty($_GET) || !empty($_POST)) {
    $callbackData = [
        'get'  => redactFyndRequestData($_GET),
        'post' => redactFyndRequestData($_POST)
    ];
    $oauthMessage = '<div class="card" style="border-color:#ffc107"><p class="warning">Fynd opened the app, but no OAuth code was found in the callback.</p><p>Callback diagnostics:</p><pre style="background:#f8f8f8;padding:10px;font-size:12px;overflow:auto">' . htmlspecialchars(json_encode($callbackData), ENT_QUOTES, 'UTF-8') . '</pre></div>';
}

$stats = $db->query("SELECT status, COUNT(*) as cnt FROM products GROUP BY status")->fetchAll(PDO::FETCH_KEY_PAIR);
$errors = $db->query("SELECT * FROM validation_errors ORDER BY created_at DESC LIMIT 20")->fetchAll(PDO::FETCH_ASSOC);
$logs = $db->query("SELECT * FROM ingest_log ORDER BY created_at DESC LIMIT 20")->fetchAll(PDO::FETCH_ASSOC);

$pending  = isset($stats['pending']) ? $stats['pending'] : 0;
$ingested = isset($stats['ingested']) ? $stats['ingested'] : 0;
$failed   = isset($stats['failed']) ? $stats['failed'] : 0;
$total    = $pending + $ingested + $failed;
?>
<!DOCTYPE html>
<html>
<head>
    <title>Fynd Catalog Extension</title>
    <style>
        body { font-family: Arial, sans-serif; max-width: 900px; margin: 40px auto; padding: 0 20px; background: #f5f5f5; }
        h1 { color: #333; } h2 { color: #555; }
        .card { background: white; border: 1px solid #ddd; border-radius: 8px; padding: 20px; margin: 20px 0; }
        .stats { display: flex; gap: 20px; margin: 20px 0; flex-wrap: wrap; }
        .stat { background: white; border: 1px solid #ddd; border-radius: 8px; padding: 20px 28px; text-align: center; flex: 1; }
        .stat-num { font-size: 36px; font-weight: bold; }
        .success { color: #28a745; } .error { color: #dc3545; } .warning { color: #ffc107; }
        .btn { display: inline-block; background: #007bff; color: white; padding: 12px 28px; border-radius: 6px; text-decoration: none; font-size: 16px; margin-right: 10px; margin-top: 10px; }
        .btn:hover { background: #0056b3; }
        .btn-green { background: #28a745; }
        .btn-green:hover { background: #1e7e34; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 8px 12px; border: 1px solid #eee; font-size: 13px; text-align: left; }
        th { background: #f8f9fa; }
        .badge { padding: 2px 8px; border-radius: 4px; font-size: 11px; }
        .badge-red { background: #f8d7da; color: #721c24; }
        .badge-orange { background: #fff3cd; color: #856404; }
        .badge-green { background: #d4edda; color: #155724; }
        .steps { display: flex; gap: 10px; margin: 20px 0; }
        .step { flex: 1; background: white; border: 2px solid #007bff; border-radius: 8px; padding: 16px; text-align: center; }
        .step-num { font-size: 24px; font-weight: bold; color: #007bff; }
    </style>
</head>
<body>
<h1>🛍️ Fynd Catalog Extension Dashboard</h1>
<p>Private extension for ingesting legacy product catalog into Fynd platform.</p>
<p><strong>Company ID:</strong> <?= FYND_COMPANY_ID ?> &nbsp;|&nbsp; <strong>Environment:</strong> <?= IS_LOCAL ? '🟡 Local (XAMPP)' : '🟢 Live (rishikeshjagdale.com)' ?></p>
<p><strong>Install URL:</strong> <?= htmlspecialchars(FYND_INSTALL_URL, ENT_QUOTES, 'UTF-8') ?> &nbsp;|&nbsp; <strong>Auth Callback:</strong> <?= htmlspecialchars(FYND_CALLBACK_URL, ENT_QUOTES, 'UTF-8') ?></p>

<?= $oauthMessage ?>

<div class="steps">
    <div class="step"><div class="step-num">1</div><strong>Transform</strong><br>Upload & validate CSV</div>
    <div class="step"><div class="step-num">2</div><strong>Review</strong><br>Check errors & warnings</div>
    <div class="step"><div class="step-num">3</div><strong>Ingest</strong><br>Push to Fynd API</div>
</div>

<div class="stats">
    <div class="stat"><div class="stat-num"><?= $total ?></div><div>Total Products</div></div>
    <div class="stat"><div class="stat-num warning"><?= $pending ?></div><div>Pending</div></div>
    <div class="stat"><div class="stat-num success"><?= $ingested ?></div><div>Ingested</div></div>
    <div class="stat"><div class="stat-num error"><?= $failed ?></div><div>Failed</div></div>
</div>

<a href="transformer.php" class="btn">→ Step 1: Transform CSV</a>
<a href="ingestor.php" class="btn btn-green">→ Step 2: Ingest to Fynd</a>

<?php if (!empty($errors)): ?>
<div class="card">
    <h2>Recent Validation Issues</h2>
    <table>
        <tr><th>Row</th><th>SKU</th><th>Type</th><th>Message</th></tr>
        <?php foreach ($errors as $e): ?>
        <tr>
            <td><?= $e['row_number'] ?></td>
            <td><?= htmlspecialchars($e['sku']) ?></td>
            <td>
                <span class="badge <?= strpos($e['error_type'], 'WARNING') !== false ? 'badge-orange' : 'badge-red' ?>">
                    <?= htmlspecialchars($e['error_type']) ?>
                </span>
            </td>
            <td><?= htmlspecialchars($e['error_message']) ?></td>
        </tr>
        <?php endforeach; ?>
    </table>
</div>
<?php endif; ?>

<?php if (!empty($logs)): ?>
<div class="card">
    <h2>Recent Ingestion Log</h2>
    <table>
        <tr><th>Time</th><th>SKU</th><th>Action</th><th>Message</th></tr>
        <?php foreach ($logs as $l): ?>
        <tr>
            <td><?= $l['created_at'] ?></td>
            <td><?= htmlspecialchars($l['sku']) ?></td>
            <td><span class="badge <?= $l['action'] === 'ingested' ? 'badge-green' : 'badge-red' ?>"><?= $l['action'] ?></span></td>
            <td style="font-size:12px"><?= htmlspecialchars(substr($l['message'], 0, 80)) ?></td>
        </tr>
        <?php endforeach; ?>
    </table>
</div>
<?php endif; ?>
</body>
</html>
