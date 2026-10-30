<?php
hp_page_header(__('Disponibilidad'), __('Cuánto tiempo lleva en línea cada sitio, si se perdió el puerto o la conexión a internet, y el registro de incidentes.'));
?>
<div x-data="hpUptime(<?= e(json_encode(["admin" => hp_admin()])) ?>)">
	<template x-if="!data"><div class="hp-card"><div class="hp-empty"><i class="fas fa-circle-notch fa-spin"></i> <?= __e('Leyendo estado…') ?></div></div></template>
	<template x-if="data">
		<div>
			<div class="hp-grid hp-grid-4 hp-mb">
				<div class="hp-card hp-stat">
					<div class="hp-stat-icon" :class="up() === data.sites.length ? 'hp-tint-green' : 'hp-tint-orange'"><i class="fas fa-heart-pulse"></i></div>
					<div><div class="hp-stat-value"><span x-text="up()"></span><span class="hp-muted" style="font-size:1rem"> / <span x-text="data.sites.length"></span></span></div><div class="hp-stat-label"><?= __e('Sitios en línea') ?></div></div>
				</div>
				<div class="hp-card hp-stat" x-show="admin">
					<div class="hp-stat-icon" :class="data.internet.state === 'down' ? 'hp-tint-orange' : 'hp-tint-blue'"><i class="fas fa-globe"></i></div>
					<div>
						<div class="hp-stat-value" style="font-size:1.1rem" x-text="data.internet.state === 'up' ? HP.t('Conectado') : (data.internet.state === 'down' ? HP.t('Sin conexión') : '—')"></div>
						<div class="hp-stat-label" x-text="data.internet.seconds != null ? (data.internet.state === 'up' ? HP.t('Internet · desde hace %s', HP.duration(data.internet.seconds)) : HP.t('Internet · caída desde hace %s', HP.duration(data.internet.seconds))) : HP.t('Internet')"></div>
					</div>
				</div>
				<div class="hp-card hp-stat">
					<div class="hp-stat-icon hp-tint-purple"><i class="fas fa-clock"></i></div>
					<div><div class="hp-stat-value" style="font-size:1.1rem" x-text="data.last_check ? ago(data.last_check) : '—'"></div><div class="hp-stat-label"><?= __e('Última comprobación') ?></div></div>
				</div>
			</div>

			<div class="hp-card">
				<div class="hp-card-head">
					<h2 class="hp-card-title"><i class="fas fa-earth-americas"></i><?= __e('Estado de los sitios') ?></h2>
					<button type="button" class="hp-btn hp-btn-sm" x-on:click="load()"><i class="fas fa-rotate"></i></button>
				</div>
				<div class="hp-card-body hp-flush hp-table-wrap">
					<template x-if="!data.sites.length"><div class="hp-empty"><?= __e('Todavía no hay sitios que vigilar.') ?></div></template>
					<table class="hp-table" x-show="data.sites.length">
						<thead><tr>
							<th><?= __e('Sitio') ?></th><th><?= __e('Estado') ?></th><th><?= __e('En línea desde hace') ?></th>
							<th class="hp-right"><?= __e('Hoy') ?></th><th class="hp-right"><?= __e('7 días') ?></th><th class="hp-right"><?= __e('30 días') ?></th>
							<th class="hp-right"><?= __e('Respuesta') ?></th><th><?= __e('Última caída') ?></th><th></th>
						</tr></thead>
						<tbody>
							<template x-for="s in data.sites" :key="s.domain">
								<tr>
									<td><b x-text="s.domain"></b><div class="hp-muted hp-small" x-show="admin" x-text="s.user + (s.port ? ' · ' + HP.t('puerto %s', s.port) : '')"></div></td>
									<td>
										<span class="hp-badge" :class="s.state === 'up' ? 'hp-badge-ok' : (s.state === 'down' ? 'hp-badge-danger' : 'hp-badge-muted')"><i class="fas fa-circle"></i><span x-text="s.state === 'up' ? HP.t('En línea') : (s.state === 'down' ? HP.t('Caído') : HP.t('Sin datos'))"></span></span>
										<div class="hp-muted hp-small" x-show="s.state === 'down'" x-text="reason(s.reason, s.code)"></div>
									</td>
									<td>
										<span x-text="s.seconds != null ? HP.duration(s.seconds) : '—'"></span>
										<div class="hp-muted hp-small" x-show="s.state === 'down' && s.seconds != null" x-text="HP.t('caído desde hace %s', HP.duration(s.seconds))"></div>
									</td>
									<td class="hp-right" x-text="pct(s.today)"></td>
									<td class="hp-right" x-text="pct(s.d7)"></td>
									<td class="hp-right" x-text="pct(s.d30)"></td>
									<td class="hp-right hp-nowrap" x-text="s.state === 'up' && s.ms != null ? s.ms + ' ms' : '—'"></td>
									<td class="hp-small hp-muted" x-text="s.last_down ? new Date(s.last_down * 1000).toLocaleString() : HP.t('Ninguna')"></td>
									<td class="hp-right"><button type="button" class="hp-btn hp-btn-xs" x-on:click="domain = s.domain; loadLog()"><i class="fas fa-list"></i></button></td>
								</tr>
							</template>
						</tbody>
					</table>
				</div>
			</div>

			<div class="hp-card" id="log">
				<div class="hp-card-head">
					<h2 class="hp-card-title"><i class="fas fa-clock-rotate-left"></i><?= __e('Registro de incidentes') ?></h2>
					<select class="hp-input" style="max-width:260px" x-model="domain" x-on:change="loadLog()">
						<option value=""><?= __e('Todos los sitios') ?></option>
						<template x-for="s in data.sites" :key="s.domain"><option :value="s.domain" x-text="s.domain"></option></template>
					</select>
				</div>
				<div class="hp-card-body hp-flush hp-table-wrap">
					<template x-if="log && !log.events.length"><div class="hp-empty"><i class="fas fa-circle-check hp-empty-icon" style="color:var(--hp-ok)"></i><h3><?= __e('Sin incidentes registrados') ?></h3></div></template>
					<table class="hp-table" x-show="log && log.events.length">
						<thead><tr><th><?= __e('Fecha') ?></th><th><?= __e('Sitio') ?></th><th><?= __e('Evento') ?></th><th><?= __e('Motivo') ?></th><th class="hp-right"><?= __e('Duración') ?></th></tr></thead>
						<tbody>
							<template x-for="(e, i) in (log ? log.events : [])" :key="i">
								<tr>
									<td class="hp-nowrap" x-text="new Date(e.ts * 1000).toLocaleString()"></td>
									<td x-text="e.domain === '*' ? HP.t('Servidor') : (e.domain === '_internet' ? HP.t('Internet') : e.domain)"></td>
									<td>
										<span class="hp-badge" :class="e.event === 'up' ? 'hp-badge-ok' : (e.event === 'down' ? 'hp-badge-danger' : 'hp-badge-warn')"><i class="fas fa-circle"></i><span x-text="eventLabel(e)"></span></span>
										<span class="hp-badge hp-badge-warn" x-show="e.internet === false && e.domain !== '_internet'"><?= __e('Sin internet') ?></span>
									</td>
									<td class="hp-small" x-text="e.event === 'gap' ? HP.t('El servidor o el panel estuvieron parados') : reason(e.reason, e.code)"></td>
									<td class="hp-right hp-nowrap">
										<span x-show="e.duration != null" x-text="HP.duration(e.duration)"></span>
										<span x-show="e.event === 'down' && log.ongoing.includes(e.domain) && isLastDown(e, i)" class="hp-badge hp-badge-danger"><?= __e('en curso') ?></span>
									</td>
								</tr>
							</template>
						</tbody>
					</table>
				</div>
			</div>
			<p class="hp-muted hp-small"><?= __e('Se comprueba cada minuto desde el propio servidor (puerto de la aplicación y petición real a nginx). Un sitio se considera caído tras 2 fallos seguidos.') ?></p>
		</div>
	</template>
</div>
<script>
document.addEventListener('alpine:init', () => {
	Alpine.data('hpUptime', (c) => ({
		admin: c.admin,
		data: null,
		log: null,
		domain: '',
		timer: null,
		init() { this.load(); this.timer = setInterval(() => { this.load(); this.loadLog(); }, 30000); },
		async load() {
			try { this.data = await HP.api('uptime-status'); } catch (e) { HP.toast(e.message, 'error'); if (!this.data) this.data = { sites: [], internet: {}, last_check: null }; }
			if (!this.log) this.loadLog();
		},
		async loadLog() {
			try { this.log = await HP.api('uptime-log', { domain: this.domain, limit: 300 }); } catch (e) { HP.toast(e.message, 'error'); }
		},
		up() { return this.data.sites.filter((s) => s.state === 'up').length; },
		pct(v) { return v == null ? '—' : v.toFixed(v === 100 ? 0 : 2).replace('.', HP.lang === 'en' ? '.' : ',') + '%'; },
		ago(ts) { const s = Math.max(0, Math.floor(Date.now() / 1000 - ts)); return s < 90 ? HP.t('hace %s s', s) : HP.t('hace %s', HP.duration(s)); },
		reason(r, code) {
			const m = { port: HP.t('El puerto de la aplicación está cerrado'), nginx: HP.t('nginx no acepta conexiones'), timeout: HP.t('Tiempo de espera agotado'),
				tls: HP.t('Error de TLS'), reset: HP.t('Conexión cortada'), invalid: HP.t('Respuesta no válida'), no_internet: HP.t('El servidor no llega a internet') };
			if (m[r]) return m[r];
			if (r && r.startsWith('http')) return HP.t('Error HTTP %s', code || r.slice(4));
			return r || '—';
		},
		eventLabel(e) {
			if (e.domain === '_internet') return e.event === 'down' ? HP.t('Internet caído') : HP.t('Internet recuperado');
			if (e.event === 'gap') return HP.t('Sin sondeos');
			return e.event === 'down' ? HP.t('Caído') : HP.t('Recuperado');
		},
		isLastDown(e, i) { return !this.log.events.slice(0, i).some((x) => x.domain === e.domain); },
	}));
});
</script>
