<?php
include __DIR__ . "/inc/pro.php";

$me = hp_me();
$sites = hp_run(["site-list", hp_admin() ? "all" : $me], $err) ?? [];
$apps = hp_run(["app-list", hp_admin() ? "all" : $me], $err2) ?? [];

if (hp_admin()) {
	$users = hp_vjson("v-list-users");
	$totals = ["users" => count($users), "web" => 0, "db" => 0, "mail" => 0, "dns" => 0, "disk" => 0, "bw" => 0];
	foreach ($users as $u) {
		$totals["web"] += (int) $u["U_WEB_DOMAINS"];
		$totals["db"] += (int) $u["U_DATABASES"];
		$totals["mail"] += (int) $u["U_MAIL_ACCOUNTS"];
		$totals["dns"] += (int) $u["U_DNS_DOMAINS"];
		$totals["disk"] += (int) $u["U_DISK"];
		$totals["bw"] += (int) $u["U_BANDWIDTH"];
	}
} else {
	$u = hp_vjson("v-list-user", [$me])[$me] ?? [];
	$totals = ["web" => (int) ($u["U_WEB_DOMAINS"] ?? 0), "db" => (int) ($u["U_DATABASES"] ?? 0),
		"mail" => (int) ($u["U_MAIL_ACCOUNTS"] ?? 0), "dns" => (int) ($u["U_DNS_DOMAINS"] ?? 0),
		"disk" => (int) ($u["U_DISK"] ?? 0), "bw" => (int) ($u["U_BANDWIDTH"] ?? 0), "user" => $u];
}
$running = count(array_filter($apps, fn($a) => $a["state"] === "running"));

hp_render("Inicio", "home", "home", compact("sites", "apps", "totals", "running"));
