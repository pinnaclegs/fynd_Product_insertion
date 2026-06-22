<?php
require_once __DIR__ . '/../../fynd_oauth.php';

$authorizationCode = getFyndAuthorizationCodeFromRequest();
$message = '';
$isSuccess = false;

if ($authorizationCode === '') {
    $callbackData = [
        'get'  => redactFyndRequestData($_GET),
        'post' => redactFyndRequestData($_POST)
    ];
    $message = 'No authorization code was found in the Fynd callback.' . "\n\n" . json_encode($callbackData);
} else {
    $validation = validateFyndOAuthCallback();
    if (!$validation['ok']) {
        $message = $validation['error'];
    } else {
        $token = getFyndAccessToken($authorizationCode, fyndCurrentCompanyId());
        if ($token) {
            $isSuccess = true;
            $message = 'Fynd authorization connected. Offline token stored for ingestion.';
        } else {
            $message = file_exists(__DIR__ . '/../../token_debug.txt')
                ? file_get_contents(__DIR__ . '/../../token_debug.txt')
                : 'Token exchange failed and no debug file was found.';
        }
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Fynd OAuth Callback</title>
    <style>
        body { font-family: Arial, sans-serif; max-width: 820px; margin: 40px auto; padding: 0 20px; background: #f5f5f5; }
        .card { background: white; border: 1px solid #ddd; border-radius: 8px; padding: 20px; }
        .success { color: #28a745; }
        .error { color: #dc3545; }
        pre { background: #f8f8f8; padding: 12px; white-space: pre-wrap; word-break: break-word; }
        a { color: #007bff; }
    </style>
</head>
<body>
    <div class="card" style="border-color:<?= $isSuccess ? '#28a745' : '#dc3545' ?>">
        <h1><?= $isSuccess ? 'Authorization Connected' : 'Authorization Failed' ?></h1>
        <p class="<?= $isSuccess ? 'success' : 'error' ?>"><?= $isSuccess ? htmlspecialchars($message, ENT_QUOTES, 'UTF-8') : 'Fynd authorization could not be completed.' ?></p>
        <?php if (!$isSuccess): ?>
            <pre><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></pre>
        <?php endif; ?>
        <p><a href="../../ingestor.php">Go to Ingestor</a></p>
    </div>
</body>
</html>
