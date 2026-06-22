<?php
require_once __DIR__ . '/../../fynd_oauth.php';

$companyId = fyndCurrentCompanyId();
$clusterUrl = isset($_GET['cluster_url']) ? $_GET['cluster_url'] : '';

if (!empty($_GET['client_id']) && $_GET['client_id'] !== EXTENSION_API_KEY) {
    appFail('Install request client_id does not match this extension API key.');
}

$authorizeUrl = fyndAuthorizationUrl($companyId, $clusterUrl);

header('Location: ' . $authorizeUrl, true, 302);
echo '<p>Redirecting to Fynd authorization...</p>';
echo '<p><a href="' . htmlspecialchars($authorizeUrl, ENT_QUOTES, 'UTF-8') . '">Continue</a></p>';
