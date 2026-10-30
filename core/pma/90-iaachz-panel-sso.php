<?php
// iaachz-panel: con un ticket o una sesión de acceso directo, phpMyAdmin usa autenticación "signon";
// sin ellos sigue con su inicio de sesión normal. Servidor 1 = el definido en 01-localhost.php.
if (isset($_GET["t"]) || isset($_COOKIE["HPSignonSession"])) {
	$cfg["Servers"][1]["auth_type"] = "signon";
	$cfg["Servers"][1]["SignonSession"] = "HPSignonSession";
	$cfg["Servers"][1]["SignonCookieParams"] = ["lifetime" => 0, "path" => "/phpmyadmin/", "domain" => "", "secure" => true, "httponly" => true, "samesite" => "Lax"];
	$cfg["Servers"][1]["SignonURL"] = "hp-sso.php";
	$cfg["Servers"][1]["LogoutURL"] = "hp-sso.php?logout=1";
}
