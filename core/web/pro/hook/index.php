<?php
// Hestia Pro: webhook de despliegue Git (GitHub, GitLab, Gitea, Bitbucket…).
// No usa sesión: lo autoriza el secreto propio de cada dominio, que valida `v-hp git-webhook`.
header("Content-Type: application/json; charset=utf-8");

$u = (string) ($_GET["u"] ?? "");
$d = strtolower((string) ($_GET["d"] ?? ""));
$k = (string) ($_GET["k"] ?? "");
if (!preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.-]{0,31}$/', $u) || !preg_match('/^[a-z0-9.-]{1,253}$/', $d) || !preg_match('/^[A-Za-z0-9_-]{16,64}$/', $k)) {
	http_response_code(400);
	exit(json_encode(["error" => "parámetros no válidos"]));
}
// Responder rápido a pings de configuración (GitHub "ping")
if (($_SERVER["HTTP_X_GITHUB_EVENT"] ?? "") === "ping") {
	exit(json_encode(["ok" => true, "pong" => true]));
}
exec("/usr/bin/sudo /usr/local/hestia/bin/v-hp git-webhook " . escapeshellarg($u) . " " . escapeshellarg($d) . " " . escapeshellarg($k), $out, $rc);
if ($rc !== 0) {
	http_response_code(403);
	exit(json_encode(["error" => "no autorizado"]));
}
echo implode("", $out);
