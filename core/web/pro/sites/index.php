<?php
include __DIR__ . "/../inc/pro.php";

$sites = hp_run(["site-list", hp_admin() ? "all" : hp_me()], $err) ?? [];
if ($err) {
	hp_flash("error", $err);
}
usort($sites, fn($a, $b) => [$a["user"], $a["domain"]] <=> [$b["user"], $b["domain"]]);
hp_render("Sitios web y dominios", "sites", "sites", compact("sites"));
