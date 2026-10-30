<?php
include __DIR__ . "/../inc/pro.php";
hp_require_admin();

$status = hp_run(["docker-status"], $err) ?? ["installed" => false];
hp_render("Docker", "docker", "docker", compact("status"));
