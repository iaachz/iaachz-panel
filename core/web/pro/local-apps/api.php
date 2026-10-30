<?php
include __DIR__ . '/../inc/pro.php';
header('Content-Type: application/json; charset=utf-8');
if (!hp_admin()) { http_response_code(403); echo json_encode(['error'=>__('Solo administradores')]); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit; }
$input = json_decode(file_get_contents('php://input'), true) ?: [];
if (empty($input['token']) || !hash_equals($_SESSION['token'] ?? '', (string)$input['token'])) {
    http_response_code(403); echo json_encode(['error'=>__('Sesión caducada')]); exit;
}
$action = (string)($input['action'] ?? '');
if (!in_array($action, ['list','create','start','stop','restart','logs','delete'], true)) { http_response_code(400); exit; }
[$rc, $out] = hp_v('v-local-apps', [hp_me(), $action, hp_b64($input['params'] ?? [])]);
$result = json_decode($out, true);
if ($rc !== 0 || !is_array($result)) { http_response_code(400); echo json_encode(['error'=>$result['error'] ?? __('No se pudo completar la acción')]); exit; }
echo json_encode($result, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
