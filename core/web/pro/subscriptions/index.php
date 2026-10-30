<?php
include __DIR__ . "/../inc/pro.php";
hp_require_admin();

$users = hp_vjson("v-list-users");
$sites = hp_run(["site-list", "all"], $err) ?? [];
$apps = hp_run(["app-list", "all"], $err2) ?? [];
$byUser = [];
foreach ($sites as $s) {
	$byUser[$s["user"]][] = $s;
}
$appsByUser = [];
foreach ($apps as $a) {
	$appsByUser[$a["user"]] = ($appsByUser[$a["user"]] ?? 0) + 1;
}
hp_render(__('Suscripciones'), "subs", "subscriptions", compact("users", "byUser", "appsByUser"));
