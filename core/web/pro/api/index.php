<?php
// Hestia Pro: API JSON para las pantallas (POST con token CSRF de la sesión).
// Permisos: el administrador actúa sobre cualquier usuario (campo "user"); el resto, solo sobre sí mismo.
include __DIR__ . "/../inc/pro.php";

header("Content-Type: application/json; charset=utf-8");
header("Cache-Control: no-store");

function hp_ok($data): void {
	echo json_encode($data ?? new stdClass(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
	exit();
}

function hp_bad(string $msg, int $code = 400): void {
	http_response_code($code);
	echo json_encode(["error" => $msg], JSON_UNESCAPED_UNICODE);
	exit();
}

function hp_need_admin(): void {
	if (!hp_admin()) {
		hp_bad("Solo el administrador puede hacer esto", 403);
	}
}

// Misma política que Hestia: los clientes solo cambian plantillas si POLICY_USER_EDIT_WEB_TEMPLATES lo permite.
function hp_need_template_policy(): void {
	if (!hp_admin() && ($_SESSION["POLICY_USER_EDIT_WEB_TEMPLATES"] ?? "no") !== "yes") {
		hp_bad("El administrador no permite cambiar plantillas en este servidor", 403);
	}
}

function hp_call(array $args) {
	$r = hp_run($args, $err);
	if ($err !== null) {
		hp_bad($err);
	}
	return $r;
}

function hp_vcall(string $cmd, array $args): void {
	[$rc, $out] = hp_v($cmd, $args);
	if ($rc !== 0) {
		hp_bad(trim($out) ?: "$cmd falló (código $rc)");
	}
}

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
	hp_bad("Método no permitido", 405);
}
$in = json_decode(file_get_contents("php://input"), true) ?: [];
if (empty($in["token"]) || !hash_equals($_SESSION["token"] ?? "", (string) $in["token"])) {
	hp_bad("Sesión caducada: recarga la página", 403);
}

$a = (string) ($in["action"] ?? "");
$admin = hp_admin();
$me = hp_me();
$user = hp_target_user((string) ($in["user"] ?? ""));
$domain = strtolower(trim((string) ($in["domain"] ?? "")));
$jobOwner = $admin ? "" : $me;

switch ($a) {
	// ---------------------------------------------------------------- servidor
	case "metrics-live":
		hp_need_admin();
		hp_ok(hp_call(["metrics-live"]));
	case "metrics-history":
		hp_need_admin();
		hp_ok(hp_call(["metrics-history", in_array($in["range"] ?? "", ["1h", "6h", "24h", "7d", "30d", "365d"]) ? $in["range"] : "24h"]));
	case "traffic-top":
		hp_need_admin();
		hp_ok(hp_call(["traffic-top", in_array($in["range"] ?? "", ["1h", "6h", "24h", "7d", "30d", "365d"]) ? $in["range"] : "24h"]));
	case "traffic-domain":
		hp_ok(hp_call(["traffic-domain", $user, $domain, in_array($in["range"] ?? "", ["1h", "6h", "24h", "7d", "30d", "365d"]) ? $in["range"] : "24h"]));
	case "weblog":
		// registros de acceso / errores con filtros; el admin puede ver cualquier dominio o todos ("*")
		$d = $domain === "*" && $admin ? "*" : $domain;
		$f = [];
		foreach (["status", "ip", "q", "range", "bots", "cc"] as $k) {
			$f[$k] = mb_substr((string) ($in[$k] ?? ""), 0, 300);
		}
		$f["limit"] = (int) ($in["limit"] ?? 300);
		hp_ok(hp_call(["weblog", $admin ? "admin" : $me, $d, hp_b64($f), $admin ? "yes" : "no",
			($in["kind"] ?? "") === "error" ? "error" : "access"]));
	// ---------------------------------------------------------------- acceso por país e IP
	case "geo-view":
		hp_need_admin();
		$r = hp_call(["geo-view"]);
		$r["client"] = ["ip" => $_SERVER["REMOTE_ADDR"] ?? ""];
		hp_ok($r);
	case "geo-map":
		hp_need_admin();
		hp_ok(hp_call(["geo-map", in_array($in["range"] ?? "", ["1h", "6h", "24h", "7d", "30d"]) ? $in["range"] : "24h"]));
	case "geo-install":
	case "geo-update":
		hp_need_admin();
		hp_ok(hp_call([$a]));
	case "geo-site-set":
		hp_need_admin();
		hp_ok(hp_call(["geo-site-set", $user, $domain, hp_b64([
			"mode" => (string) ($in["mode"] ?? "off"),
			"countries" => array_values((array) ($in["countries"] ?? [])),
			"allow_ips" => (string) ($in["allow_ips"] ?? ""),
			"deny_ips" => (string) ($in["deny_ips"] ?? ""),
		])]));
	case "geo-check":
	case "geo-firewall-set":
		// la IP desde la que se usa el panel nunca puede quedar fuera
		hp_need_admin();
		$draft = hp_b64(["admin" => (array) ($in["admin"] ?? []), "server" => (array) ($in["server"] ?? [])]);
		$client = $_SERVER["REMOTE_ADDR"] ?? "-";
		hp_ok($a === "geo-check" ? hp_call(["geo-check", $client, $draft]) : hp_call(["geo-firewall-set", $draft, $client]));
	case "geo-confirm":
		hp_need_admin();
		hp_ok(hp_call(["geo-confirm"]));
	case "geo-block":
		hp_need_admin();
		hp_ok(hp_call(["geo-block", hp_b64([
			"kind" => ($in["kind"] ?? "") === "country" ? "country" : "ip",
			"value" => (string) ($in["value"] ?? ""),
			"scope" => ($in["scope"] ?? "") === "site" ? "site" : "server",
			"domain" => $domain,
			"user" => $user,
		]), $_SERVER["REMOTE_ADDR"] ?? "-"]));
	case "metrics-live-history":
		hp_need_admin();
		hp_ok(hp_call(["metrics-live-history"]));
	case "services":
		hp_need_admin();
		hp_ok(hp_call(["services"]));
	case "service-restart":
		hp_need_admin();
		$name = (string) ($in["name"] ?? "");
		if (!preg_match('/^[a-z0-9@._-]{1,64}$/', $name)) {
			hp_bad("Servicio no válido");
		}
		hp_vcall("v-restart-service", [$name]);
		hp_ok(["ok" => true]);
	case "advisor":
		hp_need_admin();
		hp_ok(hp_call(["advisor"]));
	case "settings-get":
		hp_need_admin();
		hp_ok(hp_call(["settings-get"]));
	case "settings-save":
		hp_need_admin();
		hp_ok(hp_call(["settings-set", hp_b64((array) ($in["settings"] ?? []))]));
	case "runtimes":
		hp_ok(hp_call(["runtimes"]));
	case "runtime-install":
	case "runtime-remove":
		hp_need_admin();
		hp_ok(hp_call([$a, (string) ($in["kind"] ?? ""), (string) ($in["version"] ?? "")]));
	case "docker-install":
	case "terminal-install":
		hp_need_admin();
		hp_ok(hp_call([$a]));
	case "jobs-list":
		hp_ok(hp_call(["jobs-list", $jobOwner]));
	case "job-status":
		hp_ok(hp_call(["job-status", (string) ($in["id"] ?? ""), $jobOwner]));

	// ---------------------------------------------------------------- sitios
	case "site-list":
		hp_ok(hp_call(["site-list", $admin ? "all" : $me]));
	case "dns-check":
		hp_ok(hp_call(["dns-check", $domain]));
	case "site-create":
		$p = (array) ($in["site"] ?? []);
		if (!$admin) {
			unset($p["new_user"]);
			$p["user"] = $me;
			if (in_array($p["hosting"] ?? "", ["docker"])) {
				hp_bad("Docker solo lo puede configurar el administrador", 403);
			}
		} elseif (empty($p["new_user"])) {
			$p["user"] = hp_target_user((string) ($p["user"] ?? ""));
		}
		$owner = !empty($p["new_user"]) ? "admin" : $p["user"];
		hp_ok(hp_call(["site-create", hp_b64($p), $admin ? "admin" : $owner]));
	case "ssl-issue":
		hp_ok(hp_call(["ssl-issue", $user, $domain]));
	case "ssl-off":
		hp_vcall("v-delete-letsencrypt-domain", [$user, $domain, "yes"]);
		hp_ok(["ok" => true]);
	case "force-ssl":
		if (!empty($in["on"])) {
			hp_vcall("v-add-web-domain-ssl-force", [$user, $domain, "yes"]);
		} else {
			hp_vcall("v-delete-web-domain-ssl-force", [$user, $domain, "yes"]);
		}
		hp_ok(["ok" => true]);
	case "hsts":
		hp_vcall(!empty($in["on"]) ? "v-add-web-domain-ssl-hsts" : "v-delete-web-domain-ssl-hsts", [$user, $domain, "yes"]);
		hp_ok(["ok" => true]);
	case "php-version":
		hp_need_template_policy();
		$tpl = (string) ($in["backend"] ?? "");
		$allowed = hp_vjson("v-list-web-templates-backend");
		if (!in_array($tpl, $allowed, true)) {
			hp_bad("Versión de PHP no disponible");
		}
		hp_vcall("v-change-web-domain-backend-tpl", [$user, $domain, $tpl, "yes"]);
		hp_run(["apache-sync"], $ignored); // el vhost de Apache apunta al socket de la nueva versión
		hp_ok(["ok" => true]);
	case "web-tpl":
		hp_need_template_policy();
		$tpl = (string) ($in["tpl"] ?? "");
		if (!in_array($tpl, hp_vjson("v-list-web-templates"), true) || in_array($tpl, ["hp-app", "hp-apache"], true)) {
			hp_bad("Plantilla no válida");
		}
		hp_vcall("v-change-web-domain-tpl", [$user, $domain, $tpl, "yes"]);
		hp_ok(["ok" => true]);
	case "domain-suspend":
		hp_vcall(!empty($in["on"]) ? "v-suspend-web-domain" : "v-unsuspend-web-domain", [$user, $domain, "yes"]);
		hp_ok(["ok" => true]);
	case "domain-delete":
		// Como en Plesk: borra el sitio y, si se pide, también su zona DNS y su correo.
		hp_run(["app-cleanup", $user, $domain], $ignored);
		$sites = hp_call(["site-list", $user]);
		$site = null;
		foreach ($sites as $s) {
			if ($s["domain"] === $domain) {
				$site = $s;
			}
		}
		if (!$site) {
			hp_bad("Dominio no encontrado");
		}
		if (($site["hosting"] ?? "") !== "none") {
			hp_vcall("v-delete-web-domain", [$user, $domain, "yes"]);
		}
		if (!empty($in["dns"]) && !empty($site["dns"])) {
			hp_vcall("v-delete-dns-domain", [$user, $domain]);
		}
		if (!empty($in["mail"]) && !empty($site["mail"])) {
			hp_vcall("v-delete-mail-domain", [$user, $domain]);
		}
		hp_ok(["ok" => true]);
	case "wp-info":
		hp_ok(hp_call(["wp-info", $user, $domain]));
	case "wp-update":
		hp_ok(hp_call(["wp-update", $user, $domain]));
	case "packages":
		hp_need_admin();
		hp_ok(hp_vjson("v-list-user-packages"));

	// ---------------------------------------------------------------- bases de datos
	case "db-import":
		$src = (string) ($in["source"] ?? "");
		if (str_starts_with($src, "upload:")) {
			$id = substr($src, 7);
			$meta = preg_match('/^[a-f0-9]{32}$/', $id) ? json_decode((string) @file_get_contents("/var/lib/hestia-pro/uploads/$id.json"), true) : null;
			if (!$meta || $meta["owner"] !== $_SESSION["user"] || $meta["received"] !== $meta["size"]) {
				hp_bad("La subida no está completa o no es tuya");
			}
		} elseif ($src === "" || str_contains($src, chr(0))) {
			hp_bad("Indica el archivo del volcado");
		}
		hp_ok(hp_call(["db-import", $user, (string) ($in["database"] ?? ""), $src, !empty($in["wipe"]) ? "yes" : "no"]));
	case "db-tables":
		hp_ok(hp_call(["db-tables", $user, (string) ($in["database"] ?? "")]));

	// ---------------------------------------------------------------- nginx + Apache (por dominio)
	case "apache-status":
		hp_ok(hp_call(["apache-status"]));
	case "apache-get":
		hp_ok(hp_call(["apache-get", $user, $domain]));
	case "apache-enable":
		$ext = preg_replace('/[^a-z0-9,]/', "", strtolower((string) ($in["ext"] ?? "")));
		hp_ok(hp_call(["apache-enable", $user, $domain, !isset($in["static"]) || $in["static"] ? "yes" : "no", $ext]));
	case "apache-disable":
		hp_ok(hp_call(["apache-disable", $user, $domain]));
	case "apache-install":
		hp_need_admin();
		hp_ok(hp_call(["apache-install"]));

	// ---------------------------------------------------------------- aplicaciones
	case "app-list":
		hp_ok(hp_call(["app-list", $admin ? "all" : $me]));
	case "app-get":
		hp_ok(hp_call(["app-get", $user, $domain]));
	case "app-save":
		$app = (array) ($in["app"] ?? []);
		if (!$admin && ($app["type"] ?? "") === "docker") {
			hp_bad("Docker solo lo puede configurar el administrador", 403);
		}
		if (!$admin && ($app["type"] ?? "") === "proxy") {
			$host = strtolower((string) parse_url((string) ($app["target"] ?? ""), PHP_URL_HOST));
			$ip = filter_var(trim($host, "[]"), FILTER_VALIDATE_IP) ? trim($host, "[]") : gethostbyname($host);
			if ($host === "localhost" || !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
				hp_bad("El proxy solo puede apuntar a direcciones públicas. Para servicios internos pide ayuda al administrador.", 403);
			}
		}
		hp_ok(hp_call(["app-save", $user, $domain, hp_b64($app)]));
	case "app-action":
		$what = (string) ($in["what"] ?? "");
		if (!in_array($what, ["start", "stop", "restart", "enable", "disable"], true)) {
			hp_bad("Acción no válida");
		}
		hp_ok(hp_call(["app-action", $user, $domain, $what]));
	case "app-delete":
		hp_ok(hp_call(["app-delete", $user, $domain]));
	case "app-logs":
		hp_ok(hp_call(["app-logs", $user, $domain, (string) (int) ($in["lines"] ?? 200)]));
	case "app-deps":
		hp_ok(hp_call(["app-deps", $user, $domain]));
	case "app-scripts":
		hp_ok(hp_call(["app-scripts", $user, $domain]));
	case "app-run-script":
		hp_ok(hp_call(["app-run-script", $user, $domain, (string) ($in["script"] ?? "")]));

	// ---------------------------------------------------------------- git
	case "git-get":
		hp_ok(hp_call(["git-get", $user, $domain]));
	case "git-save":
		hp_ok(hp_call(["git-save", $user, $domain, hp_b64((array) ($in["git"] ?? []))]));
	case "git-delete":
		hp_ok(hp_call(["git-delete", $user, $domain]));
	case "git-deploy":
		hp_ok(hp_call(["git-deploy", $user, $domain]));
	case "git-key":
		hp_ok(hp_call(["git-key", $user]));

	// ---------------------------------------------------------------- docker (solo admin)
	case "docker-status":
	case "docker-ps":
	case "docker-images":
	case "docker-info":
	case "compose-list":
		hp_need_admin();
		hp_ok(hp_call([$a]));
	case "docker-action":
		hp_need_admin();
		hp_ok(hp_call(["docker-action", (string) ($in["id"] ?? ""), (string) ($in["what"] ?? "")]));
	case "docker-logs":
		hp_need_admin();
		hp_ok(hp_call(["docker-logs", (string) ($in["id"] ?? ""), (string) (int) ($in["lines"] ?? 300)]));
	case "docker-inspect":
	case "docker-rmi":
	case "docker-pull":
		hp_need_admin();
		hp_ok(hp_call([$a, (string) ($in["id"] ?? $in["image"] ?? "")]));
	case "docker-run":
		hp_need_admin();
		hp_ok(hp_call(["docker-run", hp_b64((array) ($in["container"] ?? []))]));
	case "compose-get":
	case "compose-delete":
		hp_need_admin();
		hp_ok(hp_call([$a, (string) ($in["name"] ?? "")]));
	case "compose-save":
		hp_need_admin();
		hp_ok(hp_call(["compose-save", (string) ($in["name"] ?? ""), hp_b64(["yaml" => (string) ($in["yaml"] ?? ""), "env" => (string) ($in["env"] ?? "")])]));
	case "compose-action":
		hp_need_admin();
		hp_ok(hp_call(["compose-action", (string) ($in["name"] ?? ""), (string) ($in["what"] ?? "")]));

	default:
		hp_bad("Acción desconocida: " . $a, 404);
}
