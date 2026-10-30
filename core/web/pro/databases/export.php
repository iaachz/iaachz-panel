<?php
// iaachz-panel: descarga un volcado .sql.gz (usa v-dump-database de Hestia, que borra la copia en 1 hora).
include __DIR__ . "/../inc/pro.php";

verify_csrf($_GET);
$user = hp_target_user((string) ($_GET["user"] ?? ""));
$database = (string) ($_GET["database"] ?? "");
$dbs = hp_vjson("v-list-databases", [$user]);
if (!isset($dbs[$database])) {
	hp_flash("error", __('La base de datos no existe o no te pertenece'));
	hp_redirect("/pro/databases/");
}
[$rc, $out] = hp_v("v-dump-database", [$user, $database, "file", "gzip"]);
$file = trim(strtok($out, "\n"));
if ($rc !== 0 || !str_starts_with($file, "/backup/")) {
	hp_flash("error", __('No se pudo generar el volcado: ') . $out);
	hp_redirect("/pro/databases/");
}
header("Content-Type: application/gzip");
header('Content-Disposition: attachment; filename="' . $database . "-" . date("Ymd-His") . '.sql.gz"');
header("X-Accel-Redirect: " . $file);
