<?php
// Internal FastCGI endpoint. nginx never serves this file directly to a browser.
register_shutdown_function(function () {
    if (in_array(http_response_code(), [301, 302, 303, 307, 308], true) && !headers_sent()) {
        header_remove('Location');
        http_response_code(401);
    }
    // Some existing Hestia configurations reject a missing session with 400.
    // auth_request accepts only 2xx/401/403; normalize that denial without granting access.
    if (http_response_code() === 400 && !headers_sent()) { http_response_code(403); }
});
include __DIR__ . '/../inc/pro.php';
if (!empty($_SESSION['look'])) { http_response_code(403); exit; }
$user = (string) ($_SESSION['user'] ?? '');
if (!preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.-]{0,31}$/', $user)) { http_response_code(403); exit; }
// Check current account permissions, including role changes and suspension after login.
$account = hp_vjson('v-list-user', [$user]);
$role = (string)($account[$user]['ROLE'] ?? '');
if (!in_array($role, ['admin','user'], true) || ($account[$user]['SUSPENDED'] ?? '') !== 'no') {
    http_response_code(403); exit;
}
$method = (string) ($_SERVER['HTTP_X_NEXOPANEL_METHOD'] ?? '');
$uri = (string) ($_SERVER['HTTP_X_NEXOPANEL_URI'] ?? '');
if (!in_array($method, ['GET','HEAD','POST','PUT','PATCH','DELETE'], true) || !str_starts_with($uri, '/pro/transfers/service/')) {
    http_response_code(403); exit;
}
$csrf = (string) ($_SESSION['token'] ?? '');
if (!in_array($method, ['GET','HEAD'], true) && !hash_equals($csrf, (string) ($_SERVER['HTTP_X_HESTIA_CSRF'] ?? ''))) {
    http_response_code(403); exit;
}
$payload = ['method'=>$method,'uri'=>$uri,'csrf'=>$csrf,'theme'=>(($_SESSION['userTheme'] ?? $_SESSION['THEME'] ?? '') === 'dark' ? 'dark' : 'light')];
if ($role === 'user') {
    [$rc, $out] = hp_v('v-transfer-engine', [$user, hp_b64($payload)]);
    $engine = json_decode($out, true);
    if ($rc !== 0 || !isset($engine['port'], $engine['assertion'])) { http_response_code(503); exit; }
    header('X-NexoPanel-Port: ' . (int)$engine['port']);
    header('X-NexoPanel-Auth: ' . $engine['assertion']);
    http_response_code(204); exit;
}
$secret = trim((string) @file_get_contents('/etc/nexopanel/lftp-hestia.key'));
if (strlen($secret) < 32) { http_response_code(503); exit; }
$data = ['user'=>$user, 'role'=>'admin', 'method'=>$method, 'uri'=>$uri, 'ts'=>time(), 'csrf'=>$csrf, 'theme'=>(($_SESSION['userTheme'] ?? $_SESSION['THEME'] ?? '') === 'dark' ? 'dark' : 'light')];
$encoded = rtrim(strtr(base64_encode(json_encode($data, JSON_UNESCAPED_SLASHES)), '+/', '-_'), '=');
header('X-NexoPanel-Auth: ' . $encoded . '.' . hash_hmac('sha256', $encoded, $secret));
$settings = json_decode((string)@file_get_contents('/etc/nexopanel/transfers.json'), true);
header('X-NexoPanel-Port: ' . (int)($settings['admin_port'] ?? 8090));
http_response_code(204);
