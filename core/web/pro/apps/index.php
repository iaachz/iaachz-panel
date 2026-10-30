<?php
include __DIR__ . "/../inc/pro.php";

$apps = hp_run(["app-list", hp_admin() ? "all" : hp_me()], $err) ?? [];
$sites = hp_run(["site-list", hp_admin() ? "all" : hp_me()], $err2) ?? [];
$free = array_values(array_filter($sites, fn($s) => $s["hosting"] !== "none" && empty($s["app"])));
hp_render("Aplicaciones", "apps", "apps", compact("apps", "free"));
