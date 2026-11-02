<?php
hp_page_header(__('Herramientas y ajustes'), __('Toda la configuración del servidor en un solo lugar.'));
$tok = $_SESSION["token"];
$groups = [
	__('Servidor') => [
		["/edit/server/", "fa-gear", __('Configuración general'), __('Nombre, zona horaria, idioma, políticas')],
		["/list/server/", "fa-gears", __('Servicios'), __('Iniciar, detener y configurar servicios')],
		["/list/ip/", "fa-network-wired", __('Direcciones IP'), __('IPs del servidor y NAT')],
		["/list/updates/", "fa-arrows-rotate", __('Actualizaciones'), __('Paquetes de Hestia y del sistema')],
		["/edit/server/#backups", "fa-life-ring", __('Copias del servidor'), __('Programación y destinos remotos')],
		["/list/package/", "fa-box-archive", __('Planes de servicio'), __('Límites para las suscripciones')],
	],
	__('Seguridad') => [
		["/list/firewall/", "fa-shield", __('Cortafuegos'), __('Reglas de iptables')],
		["/list/firewall/banlist/", "fa-ban", __('IPs bloqueadas'), __('Fail2ban y listas')],
		["/list/firewall/ipset/", "fa-list", __('Listas de IP'), __('Listas por país o propias')],
		["/list/access-key/", "fa-key", __('Claves de API'), __('Acceso programático a Hestia')],
		["/list/log/auth/", "fa-user-shield", __('Accesos al panel'), __('Historial de inicios de sesión')],
		["/pro/advisor/", "fa-stethoscope", __('Asesor'), __('Recomendaciones de seguridad')],
	],
	__('Herramientas') => [
		["/fm/", "fa-folder-open", __('Administrador de archivos'), __('Explorar y editar archivos')],
		["/phpmyadmin/", "fa-table", "phpMyAdmin", __('Administrar MySQL/MariaDB')],
		["/webmail/", "fa-envelope-open-text", __('Webmail'), "Roundcube"],
		["/list/rrd/", "fa-chart-column", __('Gráficos de servicios'), __('nginx, MySQL, correo, SSH')],
		["/list/stats/", "fa-chart-pie", __('Estadísticas de uso'), __('Disco y tráfico por usuario')],
		["/list/log/", "fa-clock-rotate-left", __('Registro de acciones'), __('Auditoría del panel')],
	],
	"iaachz-panel" => [
		["/pro/apps/#runtimes", "fa-layer-group", __('Node.js y Python'), __('Versiones instaladas')],
		["/pro/docker/", "fa-box", "Docker", __('Contenedores, imágenes y Compose')],
		["/pro/monitor/", "fa-chart-line", __('Monitor'), __('CPU, RAM, disco y red')],
		["#alerts", "fa-bell", __('Alertas'), __('Avisos por umbrales')],
		["#jobs", "fa-list-check", __('Tareas en segundo plano'), __('Instalaciones y despliegues')],
		["#updates", "fa-cloud-arrow-down", __('Actualizaciones del panel'), __('Nuevas versiones desde GitHub')],
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
					<?php if ($title === __('Herramientas')) { ?>
						<button type="button" class="hp-tool" x-on:click="terminal ? (location.href = '/list/terminal/') : enableTerminal()">
							<i class="fas fa-terminal"></i><span><b><?= __e('Terminal SSH en el navegador') ?></b><br><small class="hp-muted" x-text="terminal ? HP.t('Abrir terminal') : 'Activar (como SSH Terminal de Plesk)'"></small></span>
						</button>
					<?php } ?>
				</div>
			</div>
		</div>
	<?php } ?>

	<div class="hp-card" id="updates">
		<div class="hp-card-head">
			<h2 class="hp-card-title"><i class="fas fa-cloud-arrow-down"></i><?= __e('Actualizaciones del panel') ?></h2>
			<button type="button" class="hp-btn hp-btn-sm" :class="upd.busy && 'is-loading'" :disabled="upd.busy" x-on:click="checkUpdates()"><i class="fas fa-rotate"></i><?= __e('Buscar ahora') ?></button>
		</div>
		<div class="hp-card-body hp-form">
			<template x-if="!upd.st"><div class="hp-empty"><i class="fas fa-circle-notch fa-spin"></i></div></template>
			<template x-if="upd.st">
				<div class="hp-form">
					<div class="hp-form-row">
						<div class="hp-field"><label><?= __e('Versión instalada') ?></label><b class="hp-mono" x-text="upd.st.installed.version || HP.t('desconocida (instalada a mano)')"></b></div>
						<div class="hp-field"><label><?= __e('Última en GitHub') ?></label><b class="hp-mono" x-text="upd.st.latest ? upd.st.latest.version : (upd.st.checked ? HP.t('aún no hay versiones publicadas') : '—')"></b></div>
						<div class="hp-field"><label><?= __e('Última comprobación') ?></label><span x-text="upd.st.checked ? new Date(upd.st.checked * 1000).toLocaleString() : HP.t('nunca')"></span></div>
					</div>
					<p class="inline-alert inline-alert-danger" style="margin:0" x-show="!upd.st.git"><i class="fas fa-circle-exclamation"></i> <?= __e('Falta git en el servidor: instálalo con apt install git') ?></p>
					<p class="inline-alert inline-alert-danger" style="margin:0" x-show="upd.st.error" x-text="upd.st.error"></p>
					<template x-if="upd.st.available">
						<div class="inline-alert inline-alert-info" style="margin:0;display:block">
							<div><i class="fas fa-gift"></i> <b x-text="HP.t('Hay una versión nueva: %s', upd.st.latest.version)"></b></div>
							<ul class="hp-small" style="margin:6px 0 0 18px;padding:0" x-show="upd.st.changes.length">
								<template x-for="c in upd.st.changes"><li x-text="c"></li></template>
							</ul>
						</div>
					</template>
					<p class="hp-muted hp-small" style="margin:0" x-show="upd.st.checked && !upd.st.available && !upd.st.error"><i class="fas fa-circle-check" style="color:var(--hp-ok)"></i> <?= __e('El panel está al día.') ?></p>
					<p class="hp-muted hp-small" style="margin:0" x-show="upd.st.last" x-text="upd.st.last ? (upd.st.last.ok ? HP.t('Última actualización: %s → %s', upd.st.last.from || '?', upd.st.last.to) : HP.t('La última actualización a %s falló y se restauró la anterior', upd.st.last.to)) + ' (' + new Date(upd.st.last.at * 1000).toLocaleString() + ')' : ''"></p>
					<div class="hp-form-row">
						<div class="hp-field">
							<label><?= __e('Qué instalar') ?></label>
							<select class="hp-input" x-model="s.update_channel">
								<option value="releases"><?= __e('Versiones publicadas (recomendado)') ?></option>
								<option value="main"><?= __e('Rama main (cada cambio fusionado)') ?></option>
							</select>
						</div>
						<div class="hp-field" style="align-self:flex-end">
							<label class="hp-check" style="margin:0"><input type="checkbox" x-model="s.update_auto"><span><b><?= __e('Actualizar automáticamente') ?></b><small><?= __e('Cada noche (3:41) instala sola la versión nueva; si falla, vuelve a la anterior. Sin marcar, solo avisa en la campana.') ?></small></span></label>
						</div>
					</div>
					<div class="hp-between hp-wrap">
						<button type="button" class="hp-btn" x-on:click="saveUpdates()"><i class="fas fa-check"></i><?= __e('Guardar') ?></button>
						<button type="button" class="hp-btn hp-btn-primary" x-show="upd.st.available" :class="upd.busy && 'is-loading'" :disabled="upd.busy" x-on:click="applyUpdate()"><i class="fas fa-cloud-arrow-down"></i><span x-text="HP.t('Actualizar a %s', upd.st.latest ? upd.st.latest.version : '')"></span></button>
					</div>
				</div>
			</template>
		</div>
	</div>

	<div class="hp-card" id="language">
		<div class="hp-card-head"><h2 class="hp-card-title"><i class="fas fa-language"></i><?= __e('Idioma de la interfaz') ?></h2></div>
		<div class="hp-card-body hp-form">
			<p class="hp-muted" style="margin:0"><?= __e('Idioma de los menús, avisos y mensajes de iaachz-panel. «Automático» usa el idioma del perfil de cada usuario de Hestia.') ?></p>
			<div class="hp-form-row">
				<div class="hp-field">
					<label><?= __e('Idioma') ?></label>
					<select class="hp-input" x-model="s.language">
						<option value="auto"><?= __e('Automático (según el perfil de cada usuario)') ?></option>
						<?php foreach (hp_languages() as $code => $name) { ?>
							<option value="<?= e($code) ?>"><?= e($name) ?></option>
						<?php } ?>
					</select>
				</div>
				<div class="hp-field" style="align-self:flex-end"><button type="button" class="hp-btn hp-btn-primary" x-on:click="saveLanguage()"><i class="fas fa-check"></i><?= __e('Guardar idioma') ?></button></div>
			</div>
		</div>
	</div>

	<div class="hp-grid hp-grid-2 hp-mt">
		<div class="hp-card" id="alerts">
			<div class="hp-card-head"><h2 class="hp-card-title"><i class="fas fa-bell"></i><?= __e('Alertas del monitor') ?></h2></div>
			<div class="hp-card-body hp-form">
				<p class="hp-muted" style="margin:0"><?= __e('Se revisan cada 5 minutos. Los avisos llegan a la campana del panel y, si indicas un correo, también por email. Usa 0 para desactivar un umbral.') ?></p>
				<div class="hp-form-row">
					<div class="hp-field"><label>CPU media 5 min (%)</label><input class="hp-input" type="number" min="0" max="100" x-model.number="s.alert_cpu"></div>
					<div class="hp-field"><label>Memoria (%)</label><input class="hp-input" type="number" min="0" max="100" x-model.number="s.alert_mem"></div>
				</div>
				<div class="hp-form-row">
					<div class="hp-field"><label>Disco (%)</label><input class="hp-input" type="number" min="0" max="100" x-model.number="s.alert_disk"></div>
					<div class="hp-field"><label><?= __e('Carga media 5 min') ?></label><input class="hp-input" type="number" min="0" x-model.number="s.alert_load"></div>
				</div>
				<div class="hp-form-row">
					<div class="hp-field"><label><?= __e('SSL que vence en menos de (días)') ?></label><input class="hp-input" type="number" min="0" x-model.number="s.alert_ssl_days"></div>
					<div class="hp-field"><label><?= __e('Enviar también a') ?></label><input class="hp-input" type="email" x-model="s.alert_email" placeholder="<?= __e('correo del administrador') ?>"></div>
				</div>
				<label class="hp-check"><input type="checkbox" x-model="s.alert_services"><span><b><?= __e('Avisar si un servicio se detiene') ?></b></span></label>
				<label class="hp-check"><input type="checkbox" x-model="s.alert_uptime"><span><b><?= __e('Avisar si un sitio se cae o el servidor pierde internet') ?></b></span></label>
				<label class="hp-check"><input type="checkbox" x-model="s.alert_apps"><span><b><?= __e('Avisar si una aplicación Node.js/Python/Docker se cae') ?></b></span></label>
				<div class="hp-right"><button type="button" class="hp-btn hp-btn-primary" x-on:click="save()"><i class="fas fa-check"></i><?= __e('Guardar alertas') ?></button></div>
			</div>
		</div>

		<div class="hp-card" id="jobs" style="margin-top:0">
			<div class="hp-card-head"><h2 class="hp-card-title"><i class="fas fa-list-check"></i><?= __e('Tareas en segundo plano') ?></h2><button type="button" class="hp-btn hp-btn-sm" x-on:click="loadJobs()"><i class="fas fa-rotate"></i></button></div>
			<div class="hp-card-body hp-flush">
				<template x-if="!jobs"><div class="hp-empty"><i class="fas fa-circle-notch fa-spin"></i></div></template>
				<template x-if="jobs && !jobs.length"><div class="hp-empty"><?= __e('Sin tareas recientes.') ?></div></template>
				<table class="hp-table" x-show="jobs && jobs.length">
					<tbody>
						<template x-for="j in (jobs || [])" :key="j.id">
							<tr>
								<td><span class="hp-badge" :class="{ok: 'hp-badge-ok', error: 'hp-badge-danger', running: 'hp-badge-warn'}[j.status]"><i class="fas fa-circle"></i><span x-text="{ok: HP.t('Completada'), error: HP.t('Error'), running: HP.t('En curso')}[j.status]"></span></span></td>
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
		upd: { st: null, busy: false },
		init() { this.loadJobs(); this.loadUpdates(); },
		async loadUpdates() {
			try { this.upd.st = await HP.api('update-status'); } catch (e) { HP.toast(e.message, 'error'); }
		},
		async checkUpdates() {
			this.upd.busy = true;
			try { this.upd.st = await HP.api('update-check'); } catch (e) { HP.toast(e.message, 'error'); await this.loadUpdates(); }
			this.upd.busy = false;
		},
		async saveUpdates() {
			try {
				this.s = await HP.api('settings-save', { settings: this.s });
				HP.toast(HP.t('Ajustes de actualización guardados'));
				await this.checkUpdates();
			} catch (e) { HP.toast(e.message, 'error'); }
		},
		async applyUpdate() {
			const v = this.upd.st.latest.version;
			if (!(await HP.confirm(HP.t('Se instalará iaachz-panel %s en este servidor. El panel puede no responder unos segundos mientras se instala. ¿Continuar?', v), { ok: HP.t('Actualizar') }))) return;
			this.upd.busy = true;
			try {
				await HP.runJob('update-apply', {}, HP.t('Actualizando iaachz-panel a %s', v));
				HP.toast(HP.t('Panel actualizado: se recargará la página'));
				setTimeout(() => location.reload(), 1200);
			} catch (e) {
				HP.toast(e.message, 'error');
				await this.loadUpdates();
			}
			this.upd.busy = false;
		},
		async loadJobs() {
			try { this.jobs = await HP.api('jobs-list'); } catch (e) { this.jobs = []; }
		},
		async save() {
			try { this.s = await HP.api('settings-save', { settings: this.s }); HP.toast(HP.t('Alertas guardadas')); } catch (e) { HP.toast(e.message, 'error'); }
		},
		async saveLanguage() {
			try { this.s = await HP.api('settings-save', { settings: this.s }); location.reload(); } catch (e) { HP.toast(e.message, 'error'); }
		},
		async enableTerminal() {
			if (!(await HP.confirm(HP.t('Se instalará y activará la terminal web de Hestia (servicio hestia-web-terminal). Solo los usuarios con shell SSH podrán usarla.'), { ok: HP.t('Activar') }))) return;
			try { await HP.runJob('terminal-install', {}, HP.t('Activando terminal web')); location.reload(); } catch (e) { HP.toast(e.message, 'error'); }
		},
	};
}
</script>
