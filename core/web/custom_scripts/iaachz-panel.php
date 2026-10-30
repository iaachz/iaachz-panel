<?php
// iaachz-panel: carga la apariencia y utilidades de iaachz-panel en todas las páginas del panel.
// Hestia incluye automáticamente los .php de js/custom_scripts dentro de <head> (sobrevive a actualizaciones).
require_once $_SERVER["HESTIA"] . "/web/pro/inc/i18n.php";
$hp_l = hp_lang();
$hp_asset_v = @filemtime($_SERVER["HESTIA"] . "/web/pro/assets/pro.css") ?: 1;
$hp_theme = !empty($_SESSION["userTheme"]) ? $_SESSION["userTheme"] : ($_SESSION["THEME"] ?? "default");
?>
<link rel="stylesheet" href="/pro/assets/pro.css?v=<?= $hp_asset_v ?>">
<script>
	<?php if ($hp_theme === "dark") { ?>document.documentElement.classList.add("hp-dark");<?php } ?>
	window.HP_TOKEN = <?= json_encode($_SESSION["token"] ?? "") ?>;
</script>
<?php if ($hp_l !== HP_LANG_DEFAULT) { ?>
<script src="/pro/i18n/?l=<?= urlencode($hp_l) ?>&v=<?= @filemtime(HP_LANG_DIR . "/" . $hp_l . ".json") ?: 1 ?>"></script>
<?php } ?>
<script>document.documentElement.lang = <?= json_encode($hp_l) ?>;</script>
<script src="/pro/assets/pro.js?v=<?= @filemtime($_SERVER["HESTIA"] . "/web/pro/assets/pro.js") ?: 1 ?>"></script>
