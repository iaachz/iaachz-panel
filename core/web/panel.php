<?php
// iaachz-panel: navegación lateral estilo Plesk (reemplaza templates/includes/panel.php).
// El original de cada versión de Hestia queda respaldado en /opt/iaachz-panel/backup/<versión>/.
require_once $_SERVER["HESTIA"] . "/web/pro/inc/i18n.php";
$hp_is_admin = $_SESSION["userContext"] === "admin" && $_SESSION["look"] === "";
$hp_looking = $_SESSION["look"] !== "";
$hp_nav = $GLOBALS["HP_NAV"] ?? "";
$hp_tab_map = [
	"WEB" => "sites", "USER" => "subs", "PACKAGE" => "plans", "MAIL" => "mail", "DB" => "db",
	"DNS" => "dns", "CRON" => "cron", "BACKUP" => "backup", "LOG" => "logs", "STATS" => "stats",
	"RRD" => "monitor", "CPU" => "monitor", "MEMORY" => "monitor", "DISK" => "monitor", "NETWORK" => "monitor",
	"SERVER" => "tools", "IP" => "tools", "FIREWALL" => "tools", "UPDATES" => "tools", "TERMINAL" => "terminal",
	"NOTIFICATIONS" => "tools",
];
if ($hp_nav === "") {
	$hp_nav = $hp_tab_map[$TAB] ?? "";
}
$hp_sys = fn($k) => !empty($_SESSION[$k]);
$hp_has = fn($k) => ($panel[$user][$k] ?? "0") != "0";

$hp_items = [];
$hp_items[] = [__("General"), null];
$hp_items[] = ["home", "/pro/", "fa-house", __("Inicio")];
$hp_items[] = ["sites", "/pro/sites/", "fa-earth-americas", __("Sitios web y dominios")];
if ($hp_is_admin) {
	$hp_items[] = ["subs", "/pro/subscriptions/", "fa-users", __("Suscripciones")];
	$hp_items[] = ["plans", "/list/package/", "fa-box-archive", __("Planes de servicio")];
}
$hp_items[] = [__("Hosting"), null];
$hp_items[] = ["apps", "/pro/apps/", "fa-cubes", __("Aplicaciones")];
$hp_items[] = ["uptime", "/pro/uptime/", "fa-heart-pulse", __("Disponibilidad")];
if ($hp_is_admin) {
	$hp_items[] = ["docker", "/pro/docker/", "fa-box", __("Docker")];
}
if ($hp_sys("MAIL_SYSTEM") && ($hp_is_admin || $hp_has("MAIL_DOMAINS"))) {
	$hp_items[] = ["mail", "/list/mail/", "fa-envelope", __("Correo")];
}
if ($hp_sys("DB_SYSTEM") && ($hp_is_admin || $hp_has("DATABASES"))) {
	$hp_items[] = ["db", "/pro/databases/", "fa-database", __("Bases de datos")];
}
if ($hp_sys("DNS_SYSTEM") && ($hp_is_admin || $hp_has("DNS_DOMAINS"))) {
	$hp_items[] = ["dns", "/list/dns/", "fa-sitemap", __("DNS")];
}
if (($_SESSION["FILE_MANAGER"] ?? "") == "true" && !($_SESSION["userContext"] === "admin" && $_SESSION["look"] === $_SESSION["ROOT_USER"] && $_SESSION["POLICY_SYSTEM_PROTECTED_ADMIN"] == "yes")) {
	$hp_items[] = ["files", "/fm/", "fa-folder-open", __("Archivos")];
}
if ($hp_sys("CRON_SYSTEM") && ($hp_is_admin || $hp_has("CRON_JOBS"))) {
	$hp_items[] = ["cron", "/list/cron/", "fa-clock", __("Tareas programadas")];
}
if ($hp_sys("BACKUP_SYSTEM")) {
	$hp_items[] = ["backup", "/list/backup/", "fa-life-ring", __("Copias de seguridad")];
}
if ($hp_is_admin) {
	$hp_items[] = [__("Servidor"), null];
	$hp_items[] = ["monitor", "/pro/monitor/", "fa-chart-line", __("Monitor")];
	$hp_items[] = ["security", "/pro/security/", "fa-globe", __("Seguridad y países")];
	$hp_items[] = ["advisor", "/pro/advisor/", "fa-shield-halved", __("Asesor")];
	$hp_items[] = ["tools", "/pro/tools/", "fa-sliders", __("Herramientas y ajustes")];
	$hp_items[] = ["logs", "/list/log/", "fa-clock-rotate-left", __("Registros")];
} else {
	$hp_items[] = [__("Cuenta"), null];
	$hp_items[] = ["stats", "/list/stats/", "fa-chart-pie", __("Estadísticas")];
	$hp_items[] = ["logs", "/list/log/", "fa-clock-rotate-left", __("Registros")];
}
if (($_SESSION["WEB_TERMINAL"] ?? "") == "true" && ($_SESSION["login_shell"] ?? "nologin") != "nologin"
	&& !($_SESSION["userContext"] === "admin" && $_SESSION["look"] === $_SESSION["ROOT_USER"] && $_SESSION["POLICY_SYSTEM_PROTECTED_ADMIN"] == "yes")) {
	$hp_items[] = ["terminal", "/list/terminal/", "fa-terminal", __("Terminal SSH")];
}
$hp_user_label = htmlspecialchars($panel[$user]["NAME"] ?? $user);
?>
<div id="token" token="<?= $_SESSION["token"] ?>"></div>

<aside class="hp-sidebar" aria-label="<?= __e("Navegación principal") ?>">
	<a class="hp-brand" href="/pro/">
		<img src="/images/logo.svg" alt="" width="30" height="30">
		<span><?= htmlentities($_SESSION["APP_NAME"] === "Hestia Control Panel" ? "Hestia" : $_SESSION["APP_NAME"]) ?> <b>Pro</b></span>
	</a>
	<nav class="hp-nav">
		<?php foreach ($hp_items as $it) {
			if ($it[1] === null) { ?>
				<div class="hp-nav-group"><?= $it[0] ?></div>
			<?php } else { ?>
				<a class="hp-nav-link<?= $hp_nav === $it[0] ? " active" : "" ?>" href="<?= $it[1] ?>">
					<i class="fas <?= $it[2] ?>"></i><span><?= $it[3] ?></span>
				</a>
			<?php }
		} ?>
	</nav>
	<div class="hp-sidebar-foot">
		Hestia v<?= htmlentities($_SESSION["VERSION"]) ?> · Pro
	</div>
</aside>
<div class="hp-backdrop" onclick="document.body.classList.remove('hp-nav-open')"></div>

<header class="app-header hp-topbar">
	<button type="button" class="hp-burger" onclick="document.body.classList.toggle('hp-nav-open')" title="<?= __e("Menú") ?>">
		<i class="fas fa-bars"></i>
	</button>
	<form class="hp-search" action="/search/" method="get">
		<input type="hidden" name="token" value="<?= $_SESSION["token"] ?>">
		<i class="fas fa-magnifying-glass"></i>
		<input type="search" name="q" class="js-search-input" placeholder="<?= __e("Buscar dominios, correos, bases de datos…") ?>" value="<?= htmlspecialchars($_GET["q"] ?? "") ?>">
	</form>
	<div class="hp-topbar-right">
		<?php if ($hp_looking) { ?>
			<a class="hp-look-banner" href="/logout/?token=<?= $_SESSION["token"] ?>" title="<?= __e("Volver a tu cuenta de administrador") ?>">
				<i class="fas fa-binoculars"></i> <?= __e("Viendo como") ?> <b><?= htmlspecialchars($user) ?></b> <span><?= __e("Volver") ?></span>
			</a>
		<?php } ?>

		<div class="hp-menu" x-data="{ open: false }">
			<button type="button" class="hp-btn hp-btn-primary hp-btn-sm" x-on:click="open = !open">
				<i class="fas fa-plus"></i><span class="hp-hide-sm"><?= __e("Agregar") ?></span>
			</button>
			<div class="hp-menu-panel" x-cloak x-show="open" x-on:click.outside="open = false">
				<a href="/pro/new/"><i class="fas fa-earth-americas"></i><?= __e("Dominio / sitio web") ?></a>
				<?php if ($hp_is_admin) { ?>
					<a href="/pro/new/?mode=subscription"><i class="fas fa-user-plus"></i><?= __e("Suscripción (cliente)") ?></a>
				<?php } ?>
				<a href="/add/subdomain/"><i class="fas fa-diagram-project"></i><?= __e("Subdominio") ?></a>
				<a href="/pro/apps/?new=1"><i class="fas fa-cubes"></i><?= __e("Aplicación Node.js / Python") ?></a>
				<?php if ($hp_sys("DB_SYSTEM")) { ?><a href="/add/db/"><i class="fas fa-database"></i><?= __e("Base de datos") ?></a><?php } ?>
				<?php if ($hp_sys("MAIL_SYSTEM")) { ?><a href="/add/mail/"><i class="fas fa-envelope"></i><?= __e("Dominio de correo") ?></a><?php } ?>
				<?php if ($hp_sys("DNS_SYSTEM")) { ?><a href="/add/dns/"><i class="fas fa-sitemap"></i><?= __e("Zona DNS") ?></a><?php } ?>
				<a href="/add/cron/"><i class="fas fa-clock"></i><?= __e("Tarea programada") ?></a>
			</div>
		</div>

		<?php
		$impersonatingAdmin = $_SESSION["userContext"] === "admin" && ($_SESSION["look"] !== "" && $user == $_SESSION["ROOT_USER"]) && $_SESSION["POLICY_SYSTEM_PROTECTED_ADMIN"] === "yes";
		if (!$impersonatingAdmin) { ?>
			<div x-data="notifications" class="top-bar-notifications hp-notif">
				<button x-on:click="toggle()" x-bind:class="open && 'active'" class="hp-icon-btn" type="button" title="<?= __e("Notificaciones") ?>">
					<i x-bind:class="{
						'animate__animated animate__swing icon-orange': (!initialized && <?= ($panel[$user]["NOTIFICATIONS"] ?? "") == "yes" ? "true" : "false" ?>) || notifications.length != 0,
						'fas fa-bell': true
					}"></i>
				</button>
				<div x-cloak x-show="open" x-on:click.outside="open = false" class="top-bar-notifications-panel">
					<template x-if="!initialized">
						<div class="top-bar-notifications-empty"><i class="fas fa-circle-notch fa-spin icon-dim"></i><p><?= __e("Cargando…") ?></p></div>
					</template>
					<template x-if="initialized && notifications.length == 0">
						<div class="top-bar-notifications-empty"><i class="fas fa-bell-slash icon-dim"></i><p><?= __e("Sin notificaciones") ?></p></div>
					</template>
					<template x-if="initialized && notifications.length > 0">
						<ul>
							<template x-for="notification in notifications" :key="notification.ID">
								<li x-bind:id="`notification-${notification.ID}`" x-bind:class="notification.ACK && 'unseen'" class="top-bar-notification-item" x-data="{ open: true }" x-show="open" x-collapse>
									<div class="top-bar-notification-inner">
										<div class="top-bar-notification-header">
											<p x-text="notification.TOPIC" class="top-bar-notification-title"></p>
											<button x-on:click="open = false; setTimeout(() => remove(notification.ID), 300);" type="button" class="top-bar-notification-delete" title="<?= __e("Borrar") ?>">
												<i class="fas fa-xmark"></i>
											</button>
										</div>
										<div class="top-bar-notification-content" x-html="notification.NOTICE"></div>
										<p class="top-bar-notification-timestamp">
											<time :datetime="`${notification.TIMESTAMP_ISO}`" x-bind:title="`${notification.TIMESTAMP_TITLE}`" x-text="`${notification.TIMESTAMP_TEXT}`"></time>
										</p>
									</div>
								</li>
							</template>
						</ul>
					</template>
					<template x-if="initialized && notifications.length > 2">
						<button x-on:click="removeAll()" type="button" class="top-bar-notifications-delete-all">
							<i class="fas fa-check"></i> <?= __e("Borrar todas") ?>
						</button>
					</template>
				</div>
			</div>
		<?php } ?>

		<div class="hp-menu" x-data="{ open: false }">
			<button type="button" class="hp-user-btn" x-on:click="open = !open">
				<span class="hp-avatar"><?= strtoupper(mb_substr($user, 0, 1)) ?></span>
				<span class="hp-user-name hp-hide-sm"><?= htmlspecialchars($user) ?><small><?= $_SESSION["userContext"] === "admin" && !$hp_looking ? __("Administrador") : __("Cliente") ?></small></span>
				<i class="fas fa-chevron-down hp-hide-sm"></i>
			</button>
			<div class="hp-menu-panel hp-menu-right" x-cloak x-show="open" x-on:click.outside="open = false">
				<div class="hp-menu-head"><b><?= $hp_user_label ?></b><small><?= htmlspecialchars($panel[$user]["CONTACT"] ?? "") ?></small></div>
				<?php if (!($_SESSION["userContext"] === "admin" && $hp_looking && $user == $_SESSION["ROOT_USER"] && $_SESSION["POLICY_SYSTEM_PROTECTED_ADMIN"] === "yes") && ($panel[$user]["SUSPENDED"] ?? "no") === "no") { ?>
					<a href="/edit/user/?user=<?= urlencode($user) ?>&token=<?= $_SESSION["token"] ?>"><i class="fas fa-id-card"></i><?= __e("Mi perfil y contraseña") ?></a>
				<?php } ?>
				<a href="/list/stats/"><i class="fas fa-chart-pie"></i><?= __e("Estadísticas de uso") ?></a>
				<a href="/list/log/"><i class="fas fa-clock-rotate-left"></i><?= __e("Registro de actividad") ?></a>
				<?php if ($_SESSION["HIDE_DOCS"] !== "yes") { ?>
					<a href="https://hestiacp.com/docs/" target="_blank" rel="noopener"><i class="fas fa-circle-question"></i><?= __e("Ayuda") ?></a>
				<?php } ?>
				<a class="hp-menu-danger" href="/logout/?token=<?= $_SESSION["token"] ?>"><i class="fas fa-right-from-bracket"></i><?= $hp_looking ? __("Salir de %s", htmlspecialchars($user)) : __("Cerrar sesión") ?></a>
			</div>
		</div>
	</div>
</header>

<main class="app-content">
