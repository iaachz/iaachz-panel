<?php
// Hestia Pro: abre phpMyAdmin ya autenticado y limitado a una base de datos (ticket de un solo uso).
// El ticket se ata al navegador con una cookie (no a la IP: muchos proveedores reparten la salida
// a internet entre varias IP y el panel y phpMyAdmin verían direcciones distintas).
include __DIR__ . "/../inc/pro.php";

verify_csrf($_GET);
$user = hp_target_user((string) ($_GET["user"] ?? ""));
$database = (string) ($_GET["database"] ?? "");
$alias = !empty($_SESSION["DB_PMA_ALIAS"]) ? $_SESSION["DB_PMA_ALIAS"] : "phpmyadmin";

// phpMyAdmin se sirve en los dominios web (puerto 443). Si el panel se abrió por IP, se usa el nombre del servidor.
// Ajuste pma_same_origin: en servidores privados phpMyAdmin comparte origen y puerto con el panel.
if (hp_setting("pma_same_origin", false)) {
	$host = "";
	$sameHost = true;
} else {
	[$host] = explode(":", $_SERVER["HTTP_HOST"] . ":");
	$sameHost = !filter_var(trim($host, "[]"), FILTER_VALIDATE_IP);
	if (!$sameHost) {
		$host = gethostname();
	}
}

// Las cookies no distinguen puertos: la que pone el panel (:8083) llega a phpMyAdmin (:443) del mismo nombre.
$bind = "-";
if ($sameHost) {
	$nonce = bin2hex(random_bytes(32));
	setcookie("hp_pma_bind", $nonce, [
		"expires" => time() + 120,
		"path" => "/" . $alias . "/",
		"secure" => true,
		"httponly" => true,
		"samesite" => "Lax",
	]);
	$bind = hash("sha256", $nonce);
}

$r = hp_run(["pma-ticket", $user, $database, $bind], $err);
if (!$r) {
	hp_flash("error", "phpMyAdmin: " . $err);
	hp_redirect("/pro/databases/");
}
hp_redirect(($host === "" ? "" : "https://" . $host) . "/" . rawurlencode($alias) . "/hp-sso.php?t=" . $r["ticket"]);
