<?php
include __DIR__ . "/../inc/pro.php";

$user = hp_target_user();
$domain = strtolower(trim($_GET["domain"] ?? ""));
$site = null;
foreach (hp_run(["site-list", $user], $err) ?? [] as $s) {
	if ($s["domain"] === $domain) {
		$site = $s;
	}
}
if (!$site) {
	hp_flash("error", "Dominio no encontrado: " . $domain);
	hp_redirect("/pro/sites/");
}
$backends = hp_vjson("v-list-web-templates-backend");
$templates = array_values(array_filter(hp_vjson("v-list-web-templates"), fn($t) => !in_array($t, ["hp-app", "hp-apache", "suspended"], true)));
$apache = hp_run(["apache-get", $user, $domain], $e3) ?? ["enabled" => false, "static" => true, "ext" => "", "apache" => ["installed" => false]];
$runtimes = hp_run(["runtimes"], $e2) ?? [];
hp_render($domain, "sites", "site", ["site" => $site, "s_user" => $user, "backends" => $backends, "templates" => $templates, "runtimes" => $runtimes, "apache" => $apache]);
