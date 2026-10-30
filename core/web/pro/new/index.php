<?php
include __DIR__ . "/../inc/pro.php";

$mode = ($_GET["mode"] ?? "") === "subscription" && hp_admin() ? "subscription" : "domain";
$users = hp_admin() ? array_keys(hp_vjson("v-list-users")) : [hp_me()];
$packages = hp_admin() ? array_keys(hp_vjson("v-list-user-packages")) : [];
$backends = array_values(array_filter(hp_vjson("v-list-web-templates-backend"), fn($b) => str_starts_with($b, "PHP-")));
rsort($backends, SORT_NATURAL);
$runtimes = hp_run(["runtimes"], $err) ?? [];
$wiz = [
	"mode" => $mode,
	"isAdmin" => hp_admin(),
	"users" => $users,
	"me" => hp_me(),
	"packages" => $packages,
	"backends" => $backends,
	"runtimes" => $runtimes,
	"hosting" => in_array($_GET["hosting"] ?? "", ["php", "wordpress", "node", "python", "docker", "proxy", "static", "none"]) ? $_GET["hosting"] : "php",
	"user" => hp_target_user(),
];
hp_render($mode === "subscription" ? __('Nueva suscripción') : __('Agregar dominio'), "sites", "new", ["wiz" => $wiz]);
