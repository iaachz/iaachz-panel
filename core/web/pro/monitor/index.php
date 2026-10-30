<?php
include __DIR__ . "/../inc/pro.php";
hp_require_admin();
$rrd = hp_vjson("v-list-sys-rrd");
hp_render("Monitor", "monitor", "monitor", compact("rrd"));
