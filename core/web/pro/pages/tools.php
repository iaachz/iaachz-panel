<?php
hp_page_header("Herramientas y ajustes", "Toda la configuración del servidor en un solo lugar.");
$tok = $_SESSION["token"];
$groups = [
	"Servidor" => [
		["/edit/server/", "fa-gear", "Configuración general", "Nombre, zona horaria, idioma, políticas"],
		["/list/server/", "fa-gears", "Servicios", "Iniciar, detener y configurar servicios"],
		["/list/ip/", "fa-network-wired", "Direcciones IP", "IPs del servidor y NAT"],
		["/list/updates/", "fa-arrows-rotate", "Actualizaciones", "Paquetes de Hestia y del sistema"],
		["/edit/server/#backups", "fa-life-ring", "Copias del servidor", "Programación y destinos remotos"],
		["/list/package/", "fa-box-archive", "Planes de servicio", "Límites para las suscripciones"],
	],
	"Seguridad" => [
		["/list/firewall/", "fa-shield", "Cortafuegos", "Reglas de iptables"],
		["/list/firewall/banlist/", "fa-ban", "IPs bloqueadas", "Fail2ban y listas"],
		["/list/firewall/ipset/", "fa-list", "Listas de IP", "Listas por país o propias"],
		["/list/access-key/", "fa-key", "Claves de API", "Acceso programático a Hestia"],
		["/list/log/auth/", "fa-user-shield", "Accesos al panel", "Historial de inicios de sesión"],
		["/pro/advisor/", "fa-stethoscope", "Asesor", "Recomendaciones de seguridad"],
	],
	"Herramientas" => [
		["/fm/", "fa-folder-open", "Administrador de archivos", "Explorar y editar archivos"],
		["/phpmyadmin/", "fa-table", "phpMyAdmin", "Administrar MySQL/MariaDB"],
		["/webmail/", "fa-envelope-open-text", "Webmail", "Roundcube"],
		["/list/rrd/", "fa-chart-column", "Gráficos de servicios", "nginx, MySQL, correo, SSH"],
		["/list/stats/", "fa-chart-pie", "Estadísticas de uso", "Disco y tráfico por usuario"],
		["/list/log/", "fa-clock-rotate-left", "Registro de acciones", "Auditoría del panel"],
	],
	"iaachz-panel" => [
		["/pro/apps/#runtimes", "fa-layer-group", "Node.js y Python", "Versiones instaladas"],
		["/pro/docker/", "fa-box", "Docker", "Contenedores, imágenes y Compose"],
		["/pro/monitor/", "fa-chart-line", "Monitor", "CPU, RAM, disco y red"],
		["#alerts", "fa-bell", "Alertas", "Avisos por umbrales"],
		["#jobs", "fa-list-check", "Tareas en segundo plano", "Instalaciones y despliegues"],
	],
];
?>
<div x-data="hpTools(<?= e(json_encode(["settings" => $settings, "terminal" => ($_SESSION["WEB_TERMINAL"] ?? "") === "true", "terminalInstalled" => $runtimes["terminal"] ?? false])) ?>)">
	<?php foreach ($groups as $title => $items) { ?>
		<div class="hp-card">
			<div class="hp-card-head"><h2 class="hp-card-title"><?= e($title) ?></h2></div>
			<div class="hp-card-body">
				<div class="hp-tools" style="grid-template-columns:repeat(auto-fill,minmax(230px,1fr))">
					<?php foreach ($items as [$href, $icon, $name, $desc]) { ?>
						<a class="hp-tool" href="<?= e($href) ?>" <?= str_starts_with($href, "/phpmyadmin") || str_starts_with($href, "/webmail") ? 'target="_blank" rel="noopener"' : "" ?>>
							<i class="fas <?= e($icon) ?>"></i><span><b><?= e($name) ?></b><br><small class="hp-muted"><?= e($desc) ?></small></span>
						</a>
					<?php } ?>
					<?php if ($title === "Herramientas") { ?>
						<button type="button" class="hp-tool" x-on:click="terminal ? (location.href = '/list/terminal/') : enableTerminal()">
							<i class="fas fa-terminal"></i><span><b>Terminal SSH en el navegador</b><br><small class="hp-muted" x-text="terminal ? 'Abrir terminal' : 'Activar (como SSH Terminal de Plesk)'"></small></span>
						</button>
					<?php } ?>
				</div>
			</div>
		</div>
	<?php } ?>

	<div class="hp-grid hp-grid-2 hp-mt">
		<div class="hp-card" id="alerts">
			<div class="hp-card-head"><h2 class="hp-card-title"><i class="fas fa-bell"></i>Alertas del monitor</h2></div>
			<div class="hp-card-body hp-form">
				<p class="hp-muted" style="margin:0">Se revisan cada 5 minutos. Los avisos llegan a la campana del panel y, si indicas un correo, también por email. Usa 0 para desactivar un umbral.</p>
				<div class="hp-form-row">
					<div class="hp-field"><label>CPU media 5 min (%)</label><input class="hp-input" type="number" min="0" max="100" x-model.number="s.alert_cpu"></div>
					<div class="hp-field"><label>Memoria (%)</label><input class="hp-input" type="number" min="0" max="100" x-model.number="s.alert_mem"></div>
				</div>
				<div class="hp-form-row">
					<div class="hp-field"><label>Disco (%)</label><input class="hp-input" type="number" min="0" max="100" x-model.number="s.alert_disk"></div>
					<div class="hp-field"><label>Carga media 5 min</label><input class="hp-input" type="number" min="0" x-model.number="s.alert_load"></div>
				</div>
				<div class="hp-form-row">
					<div class="hp-field"><label>SSL que vence en menos de (días)</label><input class="hp-input" type="number" min="0" x-model.number="s.alert_ssl_days"></div>
					<div class="hp-field"><label>Enviar también a</label><input class="hp-input" type="email" x-model="s.alert_email" placeholder="correo del administrador"></div>
				</div>
				<label class="hp-check"><input type="checkbox" x-model="s.alert_services"><span><b>Avisar si un servicio se detiene</b></span></label>
				<label class="hp-check"><input type="checkbox" x-model="s.alert_apps"><span><b>Avisar si una aplicación Node.js/Python/Docker se cae</b></span></label>
				<div class="hp-right"><button type="button" class="hp-btn hp-btn-primary" x-on:click="save()"><i class="fas fa-check"></i>Guardar alertas</button></div>
			</div>
		</div>

		<div class="hp-card" id="jobs" style="margin-top:0">
			<div class="hp-card-head"><h2 class="hp-card-title"><i class="fas fa-list-check"></i>Tareas en segundo plano</h2><button type="button" class="hp-btn hp-btn-sm" x-on:click="loadJobs()"><i class="fas fa-rotate"></i></button></div>
			<div class="hp-card-body hp-flush">
				<template x-if="!jobs"><div class="hp-empty"><i class="fas fa-circle-notch fa-spin"></i></div></template>
				<template x-if="jobs && !jobs.length"><div class="hp-empty">Sin tareas recientes.</div></template>
				<table class="hp-table" x-show="jobs && jobs.length">
					<tbody>
						<template x-for="j in (jobs || [])" :key="j.id">
							<tr>
								<td><span class="hp-badge" :class="{ok: 'hp-badge-ok', error: 'hp-badge-danger', running: 'hp-badge-warn'}[j.status]"><i class="fas fa-circle"></i><span x-text="{ok: 'Completada', error: 'Error', running: 'En curso'}[j.status]"></span></span></td>
								<td><b x-text="j.title"></b><div class="hp-muted hp-small" x-text="new Date(j.started * 1000).toLocaleString() + ' · ' + j.owner"></div></td>
								<td class="hp-right"><button type="button" class="hp-btn hp-btn-xs" x-on:click="HP.job(j.id, j.title).catch(() => {})"><i class="fas fa-eye"></i></button></td>
							</tr>
						</template>
					</tbody>
				</table>
			</div>
		</div>
	</div>
</div>
<script>
function hpTools(c) {
	return {
		s: c.settings,
		terminal: c.terminal,
		jobs: null,
		init() { this.loadJobs(); },
		async loadJobs() {
			try { this.jobs = await HP.api('jobs-list'); } catch (e) { this.jobs = []; }
		},
		async save() {
			try { this.s = await HP.api('settings-save', { settings: this.s }); HP.toast('Alertas guardadas'); } catch (e) { HP.toast(e.message, 'error'); }
		},
		async enableTerminal() {
			if (!(await HP.confirm('Se instalará y activará la terminal web de Hestia (servicio hestia-web-terminal). Solo los usuarios con shell SSH podrán usarla.', { ok: 'Activar' }))) return;
			try { await HP.runJob('terminal-install', {}, 'Activando terminal web'); location.reload(); } catch (e) { HP.toast(e.message, 'error'); }
		},
	};
}
</script>
