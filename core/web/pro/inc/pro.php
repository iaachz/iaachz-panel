<?php
// iaachz-panel: utilidades comunes de las páginas /pro (sesión, permisos, llamadas a v-hp).
use function Hestiacp\quoteshellarg\quoteshellarg;

include $_SERVER["DOCUMENT_ROOT"] . "/inc/main.php";

const HP_VERSION = "1.0.0";

// Administrador real (no impersonando a otro usuario).
function hp_admin(): bool {
	return ($_SESSION["userContext"] ?? "") === "admin" && empty($_SESSION["look"]);
}

// Usuario cuyo contexto se está viendo (el propio o el impersonado).
function hp_me(): string {
	return !empty($_SESSION["look"]) ? $_SESSION["look"] : $_SESSION["user"];
}

// Usuario objetivo de una acción: el admin puede indicar ?user=, el resto solo actúa sobre sí mismo.
function hp_target_user(?string $requested = null): string {
	$requested = $requested ?? ($_REQUEST["user"] ?? "");
	if ($requested !== "" && hp_admin() && preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.-]{0,31}$/', $requested)) {
		return $requested;
	}
	return hp_me();
}

function hp_require_admin(): void {
	if (!hp_admin()) {
		header("Location: /pro/");
		exit();
	}
}

// Ajustes por servidor (v-hp settings-get): el código es idéntico en todos los servidores.
function hp_setting(string $key, $default = "") {
	static $all = null;
	if ($all === null) {
		$all = hp_run(["settings-get"]) ?: [];
	}
	return $all[$key] ?? $default;
}

function hp_setting_list(string $key): array {
	return array_values(array_filter(array_map("trim", explode(",", (string) hp_setting($key, "")))));
}

// Nombre amistoso de una IP VPN según el ajuste target_labels ("ip=nombre,ip=nombre").
function hp_target_label(string $ip): string {
	foreach (hp_setting_list("target_labels") as $pair) {
		[$k, $v] = array_pad(explode("=", $pair, 2), 2, "");
		if (trim($k) === $ip) {
			return trim($v);
		}
	}
	return "";
}

// Ejecuta `v-hp ARGS` y devuelve el JSON decodificado. Si falla, deja el error en $err y devuelve null.
function hp_run(array $args, ?string &$err = null) {
	$cmd = HESTIA_CMD . "v-hp " . implode(" ", array_map(fn($a) => quoteshellarg((string) $a), $args));
	exec($cmd, $output, $rc);
	$raw = implode("\n", $output);
	$data = json_decode($raw, true);
	if ($rc !== 0 || (is_array($data) && isset($data["error"]))) {
		$err = is_array($data) && isset($data["error"]) ? $data["error"] : ($raw ?: "Error $rc");
		return null;
	}
	$err = null;
	return $data;
}

// Ejecuta un comando v-* nativo de Hestia. Devuelve [código, salida].
function hp_v(string $cmd, array $args = []): array {
	exec(HESTIA_CMD . $cmd . " " . implode(" ", array_map(fn($a) => quoteshellarg((string) $a), $args)), $output, $rc);
	return [$rc, implode("\n", $output)];
}

function hp_vjson(string $cmd, array $args = []) {
	[$rc, $out] = hp_v($cmd, array_merge($args, ["json"]));
	return $rc === 0 ? (json_decode($out, true) ?: []) : [];
}

function hp_b64(array $data): string {
	return base64_encode(json_encode($data, JSON_UNESCAPED_UNICODE));
}

function e($s): string {
	return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8");
}

function hp_bytes($n, int $dec = 1): string {
	$n = (float) $n;
	$u = ["B", "KB", "MB", "GB", "TB", "PB"];
	$i = 0;
	while ($n >= 1024 && $i < count($u) - 1) {
		$n /= 1024;
		$i++;
	}
	return number_format($n, $i ? $dec : 0, ",", ".") . " " . $u[$i];
}

// Hestia guarda los usos de disco/ancho de banda en MB.
function hp_mb($mb): string {
	return $mb === "unlimited" ? "∞" : hp_bytes(((float) $mb) * 1024 * 1024);
}

function hp_csrf_field(): string {
	return '<input type="hidden" name="token" value="' . e($_SESSION["token"]) . '">';
}

function hp_flash(string $type, string $msg): void {
	$_SESSION[$type === "ok" ? "ok_msg" : "error_msg"] = $msg;
}

function hp_redirect(string $url): void {
	header("Location: " . $url);
	exit();
}

// Etiqueta y "chip" de color por tipo de hosting (Hestia solo trae iconos sólidos, no de marcas).
function hp_hosting_meta(string $type): array {
	return [
		"php" => ["PHP", "PHP"],
		"static" => ["Estático", '<i class="fas fa-file-code"></i>'],
		"node" => ["Node.js", "JS"],
		"python" => ["Python", "PY"],
		"docker" => ["Docker", '<i class="fas fa-box"></i>'],
		"proxy" => ["Proxy", '<i class="fas fa-right-left"></i>'],
		"wordpress" => ["WordPress", "WP"],
		"none" => ["Solo DNS/correo", '<i class="fas fa-at"></i>'],
	][$type] ?? [$type, '<i class="fas fa-globe"></i>'];
}

function hp_type_chip(string $type, string $size = ""): string {
	[$label, $inner] = hp_hosting_meta($type);
	return '<span class="hp-chip hp-chip-' . e($type) . ($size ? " hp-chip-" . e($size) : "") . '" title="' . e($label) . '">' . $inner . "</span>";
}

function hp_state_badge(?string $state): string {
	$map = [
		"running" => ["En marcha", "ok"],
		"starting" => ["Iniciando", "warn"],
		"stopped" => ["Detenida", "muted"],
		"disabled" => ["Deshabilitada", "muted"],
		"failed" => ["Con error", "danger"],
		"missing" => ["Sin contenedor", "danger"],
	];
	[$label, $cls] = $map[$state ?? ""] ?? [$state ?: "—", "muted"];
	return '<span class="hp-badge hp-badge-' . $cls . '"><i class="fas fa-circle"></i>' . e($label) . "</span>";
}

// Renderiza una página /pro con el marco de Hestia (cabecera, panel lateral y pie).
function hp_render(string $title, string $nav, string $page, array $vars = []): void {
	global $TAB;
	$TAB = $title;
	$GLOBALS["HP_NAV"] = $nav;
	$GLOBALS["HP_TITLE"] = $title;
	extract($GLOBALS, EXTR_SKIP);
	extract($vars, EXTR_OVERWRITE);
	include $_SERVER["HESTIA"] . "/web/templates/header.php";
	$panel = top_panel(empty($_SESSION["look"]) ? $_SESSION["user"] : $_SESSION["look"], $TAB);
	echo '<div class="hp-page">';
	include __DIR__ . "/../pages/" . $page . ".php";
	echo "</div>";
	include $_SERVER["HESTIA"] . "/web/templates/footer.php";
}

// Cabecera de página estilo Plesk: título, subtítulo, migas y botones.
function hp_page_header(string $title, string $subtitle = "", array $crumbs = [], string $actions = ""): void {
	echo '<div class="hp-page-head">';
	if ($crumbs) {
		echo '<nav class="hp-crumbs">';
		foreach ($crumbs as $label => $href) {
			echo '<a href="' . e($href) . '">' . e($label) . '</a><i class="fas fa-chevron-right"></i>';
		}
		echo "</nav>";
	}
	echo '<div class="hp-page-head-row"><div><h1 class="hp-title">' . e($title) . "</h1>";
	if ($subtitle !== "") {
		echo '<p class="hp-subtitle">' . $subtitle . "</p>";
	}
	echo '</div><div class="hp-page-actions">' . $actions . "</div></div>";
	show_alert_message($_SESSION);
	echo "</div>";
}
