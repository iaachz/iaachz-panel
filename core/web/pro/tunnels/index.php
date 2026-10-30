<?php
use function Hestiacp\quoteshellarg\quoteshellarg;
ob_start();
$TAB = 'WEB';
include $_SERVER['DOCUMENT_ROOT'] . '/pro/inc/pro.php';
if (($_SESSION['userContext'] ?? '') !== 'admin' || !empty($_SESSION['look'])) {
    http_response_code(403); exit(__('Solo los administradores pueden gestionar túneles VPN.'));
}
function tunnel_cmd($action, $arg = '') {
    $cmd = HESTIA_CMD . 'v-tunnel ' . quoteshellarg($action) . ' ' . quoteshellarg($_SESSION['user']);
    if ($arg !== '') $cmd .= ' ' . quoteshellarg($arg);
    exec($cmd, $lines, $rc);
    $json = json_decode(implode("\n", $lines), true);
    return is_array($json) ? $json : ['ok'=>false, 'error'=>__('No se pudo contactar con el gestor de túneles.')];
}
$form = ['domain'=>'', 'parent_domain'=>'', 'subdomain'=>'', 'target'=>hp_setting('tunnel_default_target',''), 'port'=>hp_setting('tunnel_default_port','8080'), 'profile'=>'standard', 'https'=>true, 'auto_dns'=>true];
$inventory = tunnel_cmd('list');
$inventory = $inventory['ok'] ? $inventory['result'] : ['items'=>[], 'targets'=>[], 'zones'=>[]];
if (isset($_GET['domain'])) {
    foreach ($inventory['items'] as $item) if ($item['domain'] === $_GET['domain']) $form = array_merge($form,$item);
}
$editing = isset($form['created_at']);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf($_POST);
    if ($read_only === true) { http_response_code(403); exit(__('Esta sesión es de solo lectura.')); }
    $action = $_POST['action'] ?? '';
    if (in_array($action, ['save','probe'], true)) {
        $form = ['domain'=>trim($_POST['domain'] ?? ''), 'parent_domain'=>trim($_POST['parent_domain'] ?? ''), 'subdomain'=>trim($_POST['subdomain'] ?? ''), 'target'=>$_POST['target'] ?? '', 'port'=>$_POST['port'] ?? '', 'profile'=>$_POST['profile'] ?? 'standard', 'https'=>isset($_POST['https']), 'auto_dns'=>isset($_POST['auto_dns']) && !empty($_POST['parent_domain'])];
        $arg = base64_encode(json_encode($form));
    } else $arg = $_POST['domain'] ?? '';
    if (!in_array($action,['save','probe','delete','retry'],true)) { http_response_code(400); exit(__('Acción no válida.')); }
    $answer = tunnel_cmd($action,$arg);
    if (!$answer['ok']) $_SESSION['error_msg'] = tohtml($answer['error']);
    elseif ($action === 'save') {
        $_SESSION['ok_msg'] = __('Túnel guardado. El certificado y la conexión se comprobarán en segundo plano. Puedes revisar el estado abajo.');
        header('Location: /pro/tunnels/'); exit;
    } elseif ($action === 'probe') {
        $full=$form['parent_domain']!=='' ? ($form['subdomain']!=='' ? $form['subdomain'].'.' : '').$form['parent_domain'] : $form['domain'];
        $_SESSION['ok_msg'] = tohtml($answer['result']['message'].' · '.$full);
    }
    else { $_SESSION['ok_msg'] = $action === 'delete' ? $answer['result']['message'] : __('Comprobación solicitada. Actualiza la página en unos segundos.'); header('Location: /pro/tunnels/'); exit; }
}
hp_render(__('Túneles VPN'),'sites','tunnels',compact('form','inventory','editing','read_only'));
unset($_SESSION['ok_msg'],$_SESSION['error_msg']);
