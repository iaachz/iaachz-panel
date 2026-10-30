<?php
include __DIR__ . "/../inc/pro.php";

$users = hp_admin() ? array_keys(hp_vjson("v-list-users")) : [hp_me()];
$dbs = [];
foreach ($users as $u) {
	foreach (hp_vjson("v-list-databases", [$u]) as $name => $d) {
		$dbs[] = array_merge($d, ["NAME" => $name, "OWNER" => $u]);
	}
}
usort($dbs, fn($a, $b) => [$a["OWNER"], $a["NAME"]] <=> [$b["OWNER"], $b["NAME"]]);
hp_render(__('Bases de datos'), "db", "databases", compact("dbs"));
