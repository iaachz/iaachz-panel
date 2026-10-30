<?php
// hestia-pro: carga la apariencia y utilidades de Hestia Pro en todas las páginas del panel.
// Hestia incluye automáticamente los .php de js/custom_scripts dentro de <head> (sobrevive a actualizaciones).
$hp_asset_v = @filemtime($_SERVER["HESTIA"] . "/web/pro/assets/pro.css") ?: 1;
$hp_theme = !empty($_SESSION["userTheme"]) ? $_SESSION["userTheme"] : ($_SESSION["THEME"] ?? "default");
?>
<link rel="stylesheet" href="/pro/assets/pro.css?v=<?= $hp_asset_v ?>">
<script>
	<?php if ($hp_theme === "dark") { ?>document.documentElement.classList.add("hp-dark");<?php } ?>
	window.HP_TOKEN = <?= json_encode($_SESSION["token"] ?? "") ?>;
</script>
<script src="/pro/assets/pro.js?v=<?= @filemtime($_SERVER["HESTIA"] . "/web/pro/assets/pro.js") ?: 1 ?>"></script>
