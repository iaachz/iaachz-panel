<?php
// Hestia Pro: entrada directa a phpMyAdmin con un ticket de un solo uso emitido por el panel.
// Lo instala install.sh en /usr/share/phpmyadmin/hp-sso.php (root:hestiamail 640).
declare(strict_types=1);

const HP_TICKETS = "/var/lib/hestia-pro/pma";
const HP_SESSION = "HPSignonSession";

session_name(HP_SESSION);
session_set_cookie_params(["path" => "/phpmyadmin/", "secure" => true, "httponly" => true, "samesite" => "Lax"]);

function hp_sso_fail(string $msg): void {
	http_response_code(403);
	header("Content-Type: text/html; charset=utf-8");
	echo '<!doctype html><meta charset="utf-8"><title>phpMyAdmin</title>'
		. '<body style="font-family:system-ui,sans-serif;background:#f2f5f9;display:grid;place-items:center;height:100vh;margin:0">'
		. '<div style="background:#fff;padding:32px 40px;border-radius:14px;box-shadow:0 10px 30px #0002;max-width:440px;text-align:center">'
		. '<h2 style="margin-top:0">No se pudo abrir phpMyAdmin</h2><p style="color:#64748b">' . htmlspecialchars($msg) . '</p>'
		. '<p style="color:#64748b">Vuelve al panel y pulsa otra vez el botón <b>phpMyAdmin</b> de la base de datos.</p></div>';
	exit();
}

// Salir: cerrar la sesión de acceso directo
if (isset($_GET["logout"])) {
	session_start();
	$_SESSION = [];
	session_destroy();
	setcookie(HP_SESSION, "", ["expires" => 1, "path" => "/phpmyadmin/", "secure" => true, "httponly" => true, "samesite" => "Lax"]);
	header("Location: ./");
	exit();
}

$t = (string) ($_GET["t"] ?? "");
if (!preg_match('/^[a-f0-9]{64}$/', $t)) {
	// phpMyAdmin redirige aquí cuando la sesión caduca: se vuelve al inicio de sesión normal
	session_start();
	$_SESSION = [];
	session_destroy();
	setcookie(HP_SESSION, "", ["expires" => 1, "path" => "/phpmyadmin/", "secure" => true, "httponly" => true, "samesite" => "Lax"]);
	hp_sso_fail("El acceso directo caducó.");
}
$file = HP_TICKETS . "/$t.json";
$ticket = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
@unlink($file); // un solo uso
if (!$ticket) {
	hp_sso_fail("El enlace ya se usó o no existe.");
}
if (($ticket["exp"] ?? 0) < time()) {
	hp_sso_fail("El enlace caducó (es válido 60 segundos).");
}
// El ticket solo vale en el navegador que lo pidió (cookie puesta por el panel en el mismo dominio).
// No se compara la IP: muchos proveedores alternan la IP de salida entre peticiones.
if (!empty($ticket["bind"])) {
	$cookie = (string) ($_COOKIE["hp_pma_bind"] ?? "");
	if ($cookie === "" || !hash_equals((string) $ticket["bind"], hash("sha256", $cookie))) {
		hp_sso_fail("El enlace se abrió en otro navegador o el acceso caducó.");
	}
}
setcookie("hp_pma_bind", "", ["expires" => 1, "path" => rtrim(dirname((string) ($_SERVER["SCRIPT_NAME"] ?? "/phpmyadmin/x")), "/") . "/", "secure" => true, "httponly" => true, "samesite" => "Lax"]);

session_start();
session_regenerate_id(true);
$_SESSION["PMA_single_signon_user"] = $ticket["dbuser"];
$_SESSION["PMA_single_signon_password"] = $ticket["dbpass"];
$_SESSION["PMA_single_signon_host"] = "localhost";
$_SESSION["PMA_single_signon_cfgupdate"] = ["only_db" => $ticket["db"]];
session_write_close();

header("Location: index.php?" . http_build_query(["route" => "/database/structure", "db" => $ticket["db"]]));
