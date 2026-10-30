<?php
include __DIR__ . "/../inc/pro.php";
hp_require_admin();
$settings = hp_run(["settings-get"], $err) ?? [];
$runtimes = hp_run(["runtimes"], $err2) ?? [];
hp_render("Herramientas y ajustes", "tools", "tools", compact("settings", "runtimes"));
