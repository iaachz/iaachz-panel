<?php
// iaachz-panel: diccionario para JavaScript (window.HP_I18N). Sin sesión: las traducciones no son secretas.
// Se pide como /pro/i18n/?l=en&v=<mtime> para poder cachearlo.
require __DIR__ . "/../inc/i18n.php";
$l = hp_lang_normalize((string) ($_GET["l"] ?? ""));
header("Content-Type: application/javascript; charset=utf-8");
header("Cache-Control: public, max-age=86400");
echo "window.HP_LANG=" . json_encode($l ?: HP_LANG_DEFAULT) . ";window.HP_I18N=" . json_encode($l ? hp_dict($l) : new stdClass(), JSON_UNESCAPED_UNICODE) . ";";
