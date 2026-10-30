<?php
hp_page_header("Seguridad y países", "De dónde llegan las visitas y quién puede entrar: por país o por IP, en cada sitio web y en los accesos de administración (SSH, panel, FTP).");
// Selector de países reutilizable: x-model apunta a la lista del componente padre
$hp_cc_picker = function (string $model, string $placeholder = "Escribe un país…") { ?>
	<div class="hp-cc-picker" x-data="hpCountries" x-modelable="value" x-model="<?= $model ?>" x-on:click.outside="open = false" x-on:click="$refs.q.focus()">
		<template x-for="cc in value" :key="cc">
			<span class="hp-cc-chip"><b x-text="cc"></b><span x-text="HP.countryName(cc)"></span><button type="button" x-on:click.stop="remove(cc)" :title="'Quitar ' + HP.countryName(cc)">&times;</button></span>
		</template>
		<input class="hp-cc-input" x-ref="q" x-model="q" x-on:focus="open = true" x-on:input="open = true; hi = 0" x-on:keydown="key($event)" placeholder="<?= e($placeholder) ?>">
		<div class="hp-cc-menu" x-show="open && options.length" x-cloak>
			<template x-for="(o, i) in options" :key="o.cc">
				<button type="button" :class="i === hi && 'hi'" x-on:mousedown.prevent="add(o.cc)" x-on:mouseenter="hi = i"><b x-text="o.cc"></b><span x-text="o.name"></span></button>
			</template>
		</div>
	</div>
<?php };
?>
<div x-data="hpSecurity">
	<!-- Cambio pendiente de confirmar (se deshace solo) -->
	<template x-if="left > 0">
		<div class="hp-pending">
			<i class="fas fa-hourglass-half"></i>
			<div><b>Comprueba que sigues pudiendo entrar</b> (abre una sesión SSH nueva o recarga el panel en otra pestaña).
				Si no confirmas, el cambio se deshace solo en <b x-text="mmss(left)"></b>.</div>
			<button type="button" class="hp-btn hp-btn-primary" x-on:click="confirmFw()"><i class="fas fa-check"></i>Todo funciona, confirmar</button>
		</div>
	</template>

	<template x-if="v && !v.status.ready">
		<div class="hp-card hp-mb">
			<div class="hp-card-body hp-flex hp-wrap" style="gap:18px">
				<span class="hp-chip hp-chip-lg" style="background:linear-gradient(135deg,#1f6fd6,#14945a)"><i class="fas fa-earth-americas"></i></span>
				<div style="flex:1;min-width:260px">
					<h2 style="margin:0 0 4px;color:var(--hp-text);font-size:1.05rem">Activar el acceso por país</h2>
					<p class="hp-muted" style="margin:0">Descarga la base gratuita de países de DB-IP (se actualiza sola cada mes) e instala <span class="hp-mono">ipset</span> para el cortafuegos. No bloquea nada por sí solo: las reglas las decides tú.</p>
				</div>
				<button type="button" class="hp-btn hp-btn-primary" x-on:click="install()"><i class="fas fa-power-off"></i>Activar</button>
			</div>
		</div>
	</template>

	<div class="hp-tabs">
		<button class="hp-tab" :class="tab === 'map' && 'active'" x-on:click="go('map')"><i class="fas fa-earth-americas"></i>Mapa de visitas</button>
		<button class="hp-tab" :class="tab === 'sites' && 'active'" x-on:click="go('sites')"><i class="fas fa-globe"></i>Sitios web</button>
		<button class="hp-tab" :class="tab === 'admin' && 'active'" x-on:click="go('admin')"><i class="fas fa-user-shield"></i>Acceso de administración
			<span class="hp-badge hp-badge-ok" x-show="v && v.admin.enabled" x-cloak>activo</span></button>
		<button class="hp-tab" :class="tab === 'server' && 'active'" x-on:click="go('server')"><i class="fas fa-ban"></i>Bloqueo en todo el servidor
			<span class="hp-badge hp-badge-danger" x-show="v && (v.server.countries.length || v.server.ips.length)" x-cloak x-text="v ? v.server.countries.length + v.server.ips.length : ''"></span></button>
	</div>

	<!-- ===================================================== MAPA -->
	<div x-show="tab === 'map'">
		<div class="hp-between hp-mb hp-wrap">
			<div class="hp-segmented">
				<template x-for="m in metrics" :key="m[0]">
					<button type="button" :class="metric === m[0] && 'active'" x-on:click="metric = m[0]; paint()"><i class="fas" :class="m[2]"></i> <span x-text="m[1]"></span></button>
				</template>
			</div>
			<div class="hp-segmented" x-show="metric !== 'live'">
				<template x-for="r in [['1h','1 hora'],['24h','24 horas'],['7d','7 días'],['30d','30 días']]" :key="r[0]">
					<button type="button" :class="range === r[0] && 'active'" x-on:click="range = r[0]; loadMap()" x-text="r[1]"></button>
				</template>
			</div>
			<span class="hp-badge hp-badge-ok" x-show="metric === 'live'" x-cloak><span class="hp-live-dot on" style="display:inline-block;width:7px;height:7px;border-radius:50%;background:#e5484d;margin-right:4px"></span>Conexiones abiertas ahora · cada 5 s</span>
		</div>
		<div class="hp-geo-layout">
			<div class="hp-card">
				<div class="hp-card-body" style="padding:10px">
					<div x-ref="map" class="hp-map-box"></div>
					<div class="hp-between hp-small hp-muted hp-wrap" style="margin-top:8px">
						<div class="hp-flex"><span>Menos</span><span class="hp-map-scale"></span><span>Más</span>
							<span class="hp-map-key-blocked"></span><span>Bloqueado en todo el servidor</span>
							<span class="hp-map-key-dot"></span><span>Conectados ahora</span></div>
						<span>Ctrl + rueda o botones para acercar · arrastra para moverte · clic en un país para ver el detalle</span>
					</div>
				</div>
			</div>
			<div class="hp-card">
				<template x-if="!sel">
					<div class="hp-card-body">
						<h3 class="hp-geo-h">Países con más <span x-text="metricLabel()"></span></h3>
						<template x-for="c in ranking().slice(0, 12)" :key="c.cc">
							<button type="button" class="hp-geo-row" x-on:click="pick(c.cc)">
								<span class="hp-cc-code" x-text="c.cc === '--' ? '?' : c.cc"></span><span class="hp-geo-name" x-text="HP.countryName(c.cc)"></span>
								<span class="hp-geo-bar"><i :style="'width:' + c.pct + '%'"></i></span><b x-text="num(c.v)"></b>
							</button>
						</template>
						<p class="hp-muted hp-small" x-show="md && !ranking().length">Sin datos en este periodo todavía.</p>
					</div>
				</template>
				<template x-if="sel">
					<div class="hp-card-body">
						<div class="hp-between"><h3 class="hp-geo-h" style="margin:0"><span class="hp-cc-code" x-text="sel"></span> <span x-text="HP.countryName(sel)"></span></h3>
							<button type="button" class="hp-icon-btn" x-on:click="pick(null)" title="Cerrar"><i class="fas fa-xmark"></i></button></div>
						<div class="hp-geo-stats">
							<div><b x-text="num(info().req)"></b><span>peticiones</span></div>
							<div><b x-text="num(info().ips)"></b><span>IPs distintas</span></div>
							<div><b x-text="info().req ? pct(info().err, info().req) : '—'"></b><span>con error</span></div>
							<div><b x-text="num(info().conns)"></b><span>conectados ahora</span></div>
							<div x-show="info().blk"><b x-text="num(info().blk)"></b><span>bloqueadas (451)</span></div>
							<div x-show="info().fw"><b x-text="num(info().fw)"></b><span>paquetes descartados</span></div>
						</div>
						<p class="hp-small" x-show="blockedWhere(sel).length" style="color:var(--hp-danger)"><i class="fas fa-ban"></i> Bloqueado en: <span x-text="blockedWhere(sel).join(', ')"></span></p>
						<template x-if="info().top.length">
							<div>
								<div class="hp-small hp-muted" style="margin:10px 0 4px">IPs que más piden · clic para ver sus peticiones</div>
								<template x-for="i in info().top" :key="i.ip">
									<button type="button" class="hp-geo-row" x-on:click="logs({ ip: i.ip })"><span class="hp-mono hp-geo-name" x-text="i.ip"></span><span class="hp-small hp-muted" x-show="i.err" x-text="pct(i.err, i.req) + ' error'"></span><b x-text="num(i.req)"></b></button>
								</template>
							</div>
						</template>
						<div class="hp-geo-actions">
							<button type="button" class="hp-btn" x-on:click="logs({})"><i class="fas fa-file-lines"></i>Ver sus peticiones</button>
							<template x-if="sel !== 'LO' && sel !== '--'">
								<div class="hp-flex hp-wrap">
									<button type="button" class="hp-btn hp-btn-danger" x-show="!v.server.countries.includes(sel)" x-on:click="blockCountry('server')"><i class="fas fa-ban"></i>Bloquear en todo el servidor</button>
									<button type="button" class="hp-btn" x-show="v.server.countries.includes(sel)" x-on:click="unblockServer(sel)"><i class="fas fa-unlock"></i>Quitar el bloqueo del servidor</button>
									<div class="hp-flex">
										<select class="hp-select" x-model="blockDomain" style="max-width:220px"><option value="">Bloquear en un sitio…</option>
											<template x-for="s in v.sites" :key="s.domain"><option :value="s.domain" x-text="s.domain"></option></template></select>
										<button type="button" class="hp-btn" :disabled="!blockDomain" x-on:click="blockCountry('site')">Bloquear</button>
									</div>
								</div>
							</template>
						</div>
					</div>
				</template>
			</div>
		</div>
		<p class="hp-muted hp-small hp-mt">Visitas web (peticiones a tus dominios) de los últimos días agrupadas por país; "conectados ahora" son las conexiones HTTP/HTTPS abiertas en este momento.
			Datos de país: <span x-html="v ? v.status.attribution : ''"></span><span x-show="v && v.status.month" x-text="v ? ' (base de ' + v.status.month + ')' : ''"></span>.
			<a href="#" x-on:click.prevent="updateDb()" x-show="v && v.status.db">Actualizar ahora</a></p>
	</div>

	<!-- ===================================================== SITIOS -->
	<div x-show="tab === 'sites'" x-cloak>
		<div class="hp-card">
			<div class="hp-card-head"><h2 class="hp-card-title"><i class="fas fa-globe"></i>Quién puede ver cada sitio</h2></div>
			<div class="hp-card-body hp-flush hp-table-wrap">
				<table class="hp-table">
					<thead><tr><th>Dominio</th><th>Países</th><th>IPs</th><th class="hp-right"></th></tr></thead>
					<tbody>
						<template x-for="s in (v ? v.sites : [])" :key="s.domain">
							<tr>
								<td><b x-text="s.domain"></b><div class="hp-muted hp-small" x-text="s.user"></div></td>
								<td>
									<span class="hp-badge" x-show="s.mode === 'off' || !s.countries.length">Todo el mundo</span>
									<template x-if="s.mode === 'allow' && s.countries.length"><span class="hp-badge hp-badge-ok" x-text="'Solo ' + names(s.countries)"></span></template>
									<template x-if="s.mode === 'deny' && s.countries.length"><span class="hp-badge hp-badge-danger" x-text="'Excepto ' + names(s.countries)"></span></template>
								</td>
								<td class="hp-small">
									<span x-show="s.allow_ips.length" x-text="s.allow_ips.length + ' siempre permitidas'"></span>
									<span x-show="s.allow_ips.length && s.deny_ips.length"> · </span>
									<span x-show="s.deny_ips.length" style="color:var(--hp-danger)" x-text="s.deny_ips.length + ' bloqueadas'"></span>
									<span class="hp-muted" x-show="!s.allow_ips.length && !s.deny_ips.length">—</span>
								</td>
								<td class="hp-right hp-nowrap">
									<button type="button" class="hp-btn hp-btn-sm" x-show="s.mode !== 'off' || s.deny_ips.length" x-on:click="logsSite(s.domain)" title="Ver quién fue bloqueado"><i class="fas fa-file-lines"></i>Bloqueados</button>
									<button type="button" class="hp-btn hp-btn-sm hp-btn-primary" x-on:click="editSite(s)"><i class="fas fa-pen"></i>Configurar</button>
								</td>
							</tr>
						</template>
					</tbody>
				</table>
			</div>
			<div class="hp-card-body hp-small hp-muted" style="border-top:1px solid var(--hp-border)">
				Los visitantes bloqueados ven una página «Este sitio no está disponible desde tu ubicación» (código <b>451</b>, aparece así en los registros).
				La validación de Let's Encrypt sigue funcionando y el propio servidor y las redes privadas nunca se bloquean. Las VPN pueden saltarse el bloqueo por país.
			</div>
		</div>
	</div>

	<dialog class="hp-modal" x-ref="site">
		<div class="hp-modal-head"><h3 x-text="site ? 'Acceso a ' + site.domain : ''"></h3><button type="button" class="hp-icon-btn" x-on:click="$refs.site.close()"><i class="fas fa-xmark"></i></button></div>
		<template x-if="site">
			<div class="hp-modal-body hp-form">
				<div class="hp-choices" style="grid-template-columns:repeat(auto-fill,minmax(190px,1fr))">
					<label class="hp-choice" :class="site.mode === 'off' && 'active'" x-on:click="site.mode = 'off'"><b>Todo el mundo</b><small>Sin límite por país.</small></label>
					<label class="hp-choice" :class="site.mode === 'allow' && 'active'" x-on:click="site.mode = 'allow'"><b>Solo estos países</b><small>Lista blanca: el resto queda fuera.</small></label>
					<label class="hp-choice" :class="site.mode === 'deny' && 'active'" x-on:click="site.mode = 'deny'"><b>Todos menos estos</b><small>Lista negra de países.</small></label>
				</div>
				<div class="hp-field" x-show="site.mode !== 'off'">
					<label x-text="site.mode === 'allow' ? 'Países con acceso' : 'Países bloqueados'"></label>
					<?php $hp_cc_picker("site.countries"); ?>
					<div class="hp-hint" x-show="suggest(site).length">
						<span x-text="site.mode === 'allow' ? 'De donde vienen tus visitas:' : 'Con muchos errores (posibles escáneres):'"></span>
						<template x-for="cc in suggest(site)" :key="cc"><button type="button" class="hp-linkish hp-cc-sugg" x-on:click="site.countries = site.countries.concat(cc)" x-text="'+ ' + HP.countryName(cc)"></button></template>
					</div>
				</div>
				<div class="hp-grid hp-grid-2">
					<div class="hp-field"><label>IPs siempre permitidas</label><textarea class="hp-textarea hp-mono" rows="4" x-model="site.allow_ips" placeholder="190.116.43.0/24&#10;una IP o rango por línea"></textarea><div class="hp-hint">Entran aunque su país esté bloqueado.</div></div>
					<div class="hp-field"><label>IPs bloqueadas</label><textarea class="hp-textarea hp-mono" rows="4" x-model="site.deny_ips" placeholder="45.148.10.62&#10;una IP o rango por línea"></textarea><div class="hp-hint">Bloqueadas aunque su país esté permitido.</div></div>
				</div>
				<p class="inline-alert inline-alert-info" x-show="site.mode === 'allow'" style="margin:0"><i class="fas fa-circle-info"></i> Con «Solo estos países», los buscadores (Google, Bing) de otros países no podrán indexar el sitio.</p>
			</div>
		</template>
		<div class="hp-modal-foot">
			<button type="button" class="hp-btn" x-on:click="$refs.site.close()">Cancelar</button>
			<button type="button" class="hp-btn hp-btn-primary" :disabled="busy" x-on:click="saveSite()"><i class="fas" :class="busy ? 'fa-circle-notch fa-spin' : 'fa-check'"></i>Guardar y aplicar</button>
		</div>
	</dialog>

	<!-- ===================================================== ADMINISTRACIÓN -->
	<div x-show="tab === 'admin'" x-cloak>
		<template x-if="v && v.ssh.failed">
			<div class="hp-card hp-mb hp-geo-attacks">
				<div class="hp-card-body">
					<b><i class="fas fa-user-ninja"></i> <span x-text="num(v.ssh.failed)"></span> intentos fallidos de entrar por SSH en las últimas 24 horas</b>
					<span class="hp-muted" x-text="'desde ' + num(v.ssh.ips) + ' IPs: ' + v.ssh.countries.slice(0, 5).map((c) => HP.countryName(c.cc) + ' ' + num(c.n)).join(' · ')"></span>
					<div class="hp-small hp-muted">Son robots probando contraseñas. Una lista blanca los deja fuera antes de que lleguen a intentarlo.</div>
				</div>
			</div>
		</template>
		<div class="hp-card" x-show="v">
			<div class="hp-card-head"><h2 class="hp-card-title"><i class="fas fa-user-shield"></i>Lista blanca para entrar al servidor</h2>
				<label class="hp-check" style="margin:0"><input type="checkbox" x-model="fw.admin.enabled" x-on:change="check()"><span><b x-text="fw.admin.enabled ? 'Activada' : 'Desactivada'"></b></span></label></div>
			<div class="hp-card-body hp-form">
				<div class="hp-field">
					<label>Servicios protegidos</label>
					<div class="hp-flex hp-wrap" style="gap:16px">
						<template x-for="(s, key) in (v ? v.services : {})" :key="key">
							<label class="hp-check" style="margin:0"><input type="checkbox" :value="key" x-model="fw.admin.services" x-on:change="check()"><span><b x-text="s.label"></b><small x-text="'puerto ' + s.ports.replace(':', '-')"></small></span></label>
						</template>
					</div>
					<div class="hp-hint">Las webs, el correo y el DNS no se ven afectados. phpMyAdmin va por la web y sigue pidiendo usuario y contraseña.</div>
				</div>
				<div class="hp-grid hp-grid-2">
					<div class="hp-field">
						<label>IPs permitidas</label>
						<textarea class="hp-textarea hp-mono" rows="5" x-model="fw.admin.ips" x-on:change="check()" placeholder="una IP o rango por línea"></textarea>
						<div class="hp-hint" x-show="v && v.client.ip">Estás entrando desde <b class="hp-mono" x-text="v.client.ip"></b> <span x-text="chk && chk.client ? '(' + HP.countryName(chk.client.cc) + ')' : ''"></span>
							<a href="#" x-on:click.prevent="addMyIp()" x-show="!hasMyIp()">Añadir mi IP</a></div>
					</div>
					<div class="hp-field">
						<label>Países permitidos <small class="hp-muted">(respaldo si tu IP cambia)</small></label>
						<?php $hp_cc_picker("fw.admin.countries"); ?>
						<div class="hp-hint">Tu proveedor de internet puede cambiarte la IP: permitir tu país evita quedarte fuera. Tailscale y las redes privadas siempre pueden entrar.</div>
					</div>
				</div>
				<template x-if="chk">
					<div class="hp-geo-check">
						<div class="hp-small hp-muted">Con esta configuración:</div>
						<div x-show="chk.client"><i class="fas" :class="chk.client && chk.client.admin && chk.client.server ? 'fa-circle-check ok' : 'fa-circle-xmark bad'"></i>
							Tu conexión actual (<span class="hp-mono" x-text="chk.client ? chk.client.ip : ''"></span>) <b x-text="chk.client && chk.client.admin && chk.client.server ? 'puede entrar' : 'quedaría fuera'"></b></div>
						<template x-for="s in chk.ssh" :key="s.ip">
							<div><i class="fas" :class="s.admin && s.server ? 'fa-circle-check ok' : 'fa-circle-minus bad'"></i> Sesión SSH abierta desde <span class="hp-mono" x-text="s.ip"></span> (<span x-text="HP.countryName(s.cc)"></span>):
								<b x-text="s.admin && s.server ? 'podrá volver a entrar' : 'no podrá volver a entrar'"></b></div>
						</template>
					</div>
				</template>
			</div>
			<div class="hp-modal-foot" style="border-top:1px solid var(--hp-border)">
				<span class="hp-small hp-muted" style="margin-right:auto">Al aplicar tienes 3 minutos para confirmar; si no, se deshace solo.</span>
				<button type="button" class="hp-btn hp-btn-primary" :disabled="busy" x-on:click="saveFw()"><i class="fas" :class="busy ? 'fa-circle-notch fa-spin' : 'fa-shield-halved'"></i>Aplicar</button>
			</div>
		</div>
	</div>

	<!-- ===================================================== SERVIDOR -->
	<div x-show="tab === 'server'" x-cloak>
		<div class="hp-card" x-show="v">
			<div class="hp-card-head"><h2 class="hp-card-title"><i class="fas fa-ban"></i>Bloquear en todos los servicios del servidor</h2></div>
			<div class="hp-card-body hp-form">
				<p class="inline-alert inline-alert-warning" style="margin:0 0 14px"><i class="fas fa-triangle-exclamation"></i>
					Esto corta <b>todo</b>: webs, correo (no recibirás emails de servidores de esos países), DNS, FTP y SSH. Para limitar solo un sitio usa la pestaña <a href="#sites" x-on:click.prevent="go('sites')">Sitios web</a>.</p>
				<div class="hp-grid hp-grid-2">
					<div class="hp-field"><label>Países bloqueados</label><?php $hp_cc_picker("fw.server.countries"); ?>
						<div class="hp-hint" x-show="attackSuggest().length">Más intentos de ataque por SSH:
							<template x-for="cc in attackSuggest()" :key="cc"><button type="button" class="hp-linkish hp-cc-sugg" x-on:click="fw.server.countries = fw.server.countries.concat(cc)" x-text="'+ ' + HP.countryName(cc)"></button></template></div>
					</div>
					<div class="hp-field"><label>IPs bloqueadas</label><textarea class="hp-textarea hp-mono" rows="5" x-model="fw.server.ips" placeholder="una IP o rango por línea"></textarea></div>
				</div>
				<template x-if="md && Object.keys(md.fw).length">
					<div class="hp-small"><b>Descartado desde el último cambio:</b>
						<template x-for="(n, cc) in md.fw" :key="cc"><span class="hp-badge" style="margin:2px" x-text="HP.countryName(cc) + ': ' + num(n) + ' paquetes'"></span></template></div>
				</template>
			</div>
			<div class="hp-modal-foot" style="border-top:1px solid var(--hp-border)">
				<span class="hp-small hp-muted" style="margin-right:auto">Las conexiones ya abiertas no se cortan. Tu IP actual nunca se puede bloquear.</span>
				<button type="button" class="hp-btn hp-btn-primary" :disabled="busy" x-on:click="saveFw()"><i class="fas" :class="busy ? 'fa-circle-notch fa-spin' : 'fa-ban'"></i>Aplicar</button>
			</div>
		</div>
	</div>
</div>
<?php include __DIR__ . "/logs_modal.php"; ?>
<script>
document.addEventListener('alpine:init', () => {
	Alpine.data('hpSecurity', () => {
		let map = null, timer = null, poll = null, chkTimer = null;
		const lines = (a) => (a || []).join('\n');
		return {
			tab: (location.hash || '#map').slice(1),
			v: null,
			md: null,
			fw: { admin: { enabled: false, services: [], ips: '', countries: [] }, server: { countries: [], ips: '' } },
			chk: null,
			site: null,
			sel: null,
			blockDomain: '',
			busy: false,
			left: 0,
			range: '7d',
			metric: 'req',
			metrics: [['req', 'Visitas', 'fa-eye'], ['live', 'Conectados ahora', 'fa-satellite-dish'], ['err', 'Errores', 'fa-triangle-exclamation'], ['blk', 'Bloqueados', 'fa-ban']],
			async init() {
				window.addEventListener('hashchange', () => (this.tab = (location.hash || '#map').slice(1)));
				await this.loadView();
				map = await HP.worldMap(this.$refs.map, { tip: (cc) => this.tip(cc), onClick: (cc) => this.pick(cc) });
				this.loadMap();
				poll = setInterval(() => {
					if (!document.hidden && this.metric === 'live' && this.tab === 'map') this.loadMap();
				}, 5000);
			},
			go(t) {
				history.replaceState(null, '', '#' + t);
				this.tab = t;
				if (t === 'admin' || t === 'server') this.check();
			},
			async loadView() {
				try {
					this.v = await HP.api('geo-view');
				} catch (e) {
					return HP.toast(e.message, 'error');
				}
				this.fw = {
					admin: { enabled: this.v.admin.enabled, services: this.v.admin.services.slice(), ips: lines(this.v.admin.ips), countries: this.v.admin.countries.slice() },
					server: { countries: this.v.server.countries.slice(), ips: lines(this.v.server.ips) },
				};
				this.countdown();
			},
			countdown() {
				clearInterval(timer);
				this.left = this.v.pending ? this.v.pending.until - this.v.now : 0;
				if (this.left <= 0) return;
				timer = setInterval(() => {
					this.left--;
					if (this.left <= 0) {
						clearInterval(timer);
						HP.toast('No se confirmó: se volvió a la configuración anterior', 'error');
						setTimeout(() => this.loadView(), 3000);
					}
				}, 1000);
			},
			mmss(s) {
				return Math.floor(s / 60) + ':' + String(s % 60).padStart(2, '0');
			},
			async install() {
				try {
					await HP.runJob('geo-install', {}, 'Activar acceso por país');
					await this.loadView();
					this.loadMap();
				} catch (e) {
					HP.toast(e.message, 'error');
				}
			},
			async updateDb() {
				try {
					await HP.runJob('geo-update', {}, 'Actualizar base de países');
					this.loadView();
				} catch (e) {
					HP.toast(e.message, 'error');
				}
			},
			// ------------------------------------------------ mapa
			async loadMap() {
				try {
					this.md = await HP.api('geo-map', { range: this.range });
				} catch (e) {
					return;
				}
				this.paint();
			},
			value(cc) {
				const c = this.md.countries[cc], l = this.md.live[cc];
				if (this.metric === 'live') return l ? l.conns : 0;
				if (this.metric === 'err') return c ? c.err : 0;
				if (this.metric === 'blk') return (c ? c.blk : 0) + (this.md.fw[cc] || 0);
				return c ? c.req : 0;
			},
			paint() {
				if (!map || !this.md) return;
				const vals = {};
				const all = new Set(Object.keys(this.md.countries).concat(Object.keys(this.md.live), Object.keys(this.md.fw)));
				all.forEach((cc) => (vals[cc] = this.value(cc)));
				const live = {};
				Object.entries(this.md.live).forEach(([cc, c]) => (live[cc] = c.conns));
				const color = { err: 'var(--hp-warn)', blk: 'var(--hp-danger)' }[this.metric] || 'var(--hp-primary)';
				map.update(vals, { blocked: this.md.blocked.server, live: this.metric === 'live' || this.metric === 'req' ? live : {}, color });
			},
			ranking() {
				if (!this.md) return [];
				const all = new Set(Object.keys(this.md.countries).concat(Object.keys(this.md.live), Object.keys(this.md.fw)));
				const rows = [...all].filter((cc) => cc !== 'IP').map((cc) => ({ cc, v: this.value(cc) })).filter((r) => r.v > 0).sort((a, b) => b.v - a.v);
				const max = rows.length ? rows[0].v : 1;
				rows.forEach((r) => (r.pct = Math.max(2, Math.round((r.v * 100) / max))));
				return rows;
			},
			metricLabel() {
				return { req: 'visitas', live: 'conexiones ahora', err: 'errores', blk: 'bloqueos' }[this.metric];
			},
			info() {
				const c = (this.md && this.md.countries[this.sel]) || { req: 0, err: 0, blk: 0, ips: 0, top: [] };
				const l = (this.md && this.md.live[this.sel]) || { conns: 0 };
				return Object.assign({}, c, { conns: l.conns, fw: (this.md && this.md.fw[this.sel]) || 0 });
			},
			tip(cc) {
				const c = this.md && this.md.countries[cc], l = this.md && this.md.live[cc];
				const where = this.blockedWhere(cc);
				return '<b>' + HP.esc(HP.countryName(cc)) + '</b>' +
					(c ? '<div>' + c.req.toLocaleString() + ' peticiones · ' + c.ips + ' IPs</div>' + (c.err ? '<div>' + this.pct(c.err, c.req) + ' con error</div>' : '') : '<div class="hp-muted">Sin visitas en el periodo</div>') +
					(l ? '<div>Ahora: ' + l.conns + ' conexiones</div>' : '') +
					(where.length ? '<div class="hp-map-tip-bad">Bloqueado en: ' + HP.esc(where.join(', ')) + '</div>' : '');
			},
			blockedWhere(cc) {
				if (!this.md) return [];
				const out = this.md.blocked.server.includes(cc) ? ['todo el servidor'] : [];
				Object.entries(this.md.blocked.sites).forEach(([d, s]) => {
					if ((s.mode === 'deny') === s.countries.includes(cc)) out.push(d);
				});
				return out;
			},
			pick(cc) {
				this.sel = cc;
				this.blockDomain = '';
				if (map) map.select(cc);
			},
			logs(extra) {
				const range = { '30d': '30d' }[this.range] || this.range;
				window.dispatchEvent(new CustomEvent('hp-logs', { detail: Object.assign({ domain: '*', cc: this.sel, range, domains: this.v.sites.map((s) => s.domain) }, extra) }));
			},
			logsSite(domain) {
				window.dispatchEvent(new CustomEvent('hp-logs', { detail: { domain, status: '451', range: '7d' } }));
			},
			async blockCountry(scope) {
				const cc = this.sel, name = HP.countryName(cc);
				const where = scope === 'site' ? this.blockDomain : 'todo el servidor (webs, correo, DNS, SSH…)';
				if (!(await HP.confirm('¿Bloquear ' + name + ' en ' + where + '?', { ok: 'Bloquear', danger: true }))) return;
				try {
					this.v = Object.assign(this.v, await HP.api('geo-block', { kind: 'country', value: cc, scope, domain: this.blockDomain }));
					HP.toast(name + ' bloqueado');
					await this.loadView();
					this.loadMap();
				} catch (e) {
					HP.toast(e.message, 'error');
				}
			},
			async unblockServer(cc) {
				this.fw.server.countries = this.fw.server.countries.filter((c) => c !== cc);
				await this.saveFw();
				this.loadMap();
			},
			// ------------------------------------------------ sitios
			names(ccs) {
				return ccs.slice(0, 4).map((c) => HP.countryName(c)).join(', ') + (ccs.length > 4 ? ' y ' + (ccs.length - 4) + ' más' : '');
			},
			editSite(s) {
				this.site = { domain: s.domain, user: s.user, mode: s.mode, countries: s.countries.slice(), allow_ips: lines(s.allow_ips), deny_ips: lines(s.deny_ips) };
				this.$refs.site.showModal();
			},
			suggest(site) {
				if (!this.md) return [];
				const rows = Object.entries(this.md.countries).filter(([cc]) => cc.length === 2 && cc !== 'LO' && !site.countries.includes(cc));
				if (site.mode === 'allow') return rows.sort((a, b) => b[1].req - a[1].req).slice(0, 5).map((r) => r[0]);
				return rows.filter(([, c]) => c.req >= 10 && c.err * 2 >= c.req).sort((a, b) => b[1].err - a[1].err).slice(0, 6).map((r) => r[0]);
			},
			async saveSite() {
				this.busy = true;
				try {
					const r = await HP.api('geo-site-set', this.site);
					this.v = Object.assign(this.v, r);
					this.$refs.site.close();
					HP.toast('Aplicado en ' + this.site.domain);
					this.loadMap();
				} catch (e) {
					HP.toast(e.message, 'error');
				} finally {
					this.busy = false;
				}
			},
			// ------------------------------------------------ cortafuegos
			payload() {
				const split = (t) => String(t || '').split(/[\s,;]+/).filter(Boolean);
				return {
					admin: { enabled: this.fw.admin.enabled, services: this.fw.admin.services, ips: split(this.fw.admin.ips), countries: this.fw.admin.countries },
					server: { countries: this.fw.server.countries, ips: split(this.fw.server.ips) },
				};
			},
			check() {
				clearTimeout(chkTimer);
				chkTimer = setTimeout(async () => {
					try {
						this.chk = await HP.api('geo-check', this.payload());
					} catch (e) {
						this.chk = null;
					}
				}, 300);
			},
			hasMyIp() {
				return this.v && this.fw.admin.ips.split(/[\s,;]+/).includes(this.v.client.ip);
			},
			addMyIp() {
				this.fw.admin.ips = (this.fw.admin.ips.trim() + '\n' + this.v.client.ip).trim();
				this.check();
			},
			attackSuggest() {
				if (!this.v) return [];
				return this.v.ssh.countries.map((c) => c.cc).filter((cc) => cc && cc !== '--' && cc !== 'LO' && cc !== (this.chk && this.chk.client ? this.chk.client.cc : '') && !this.fw.server.countries.includes(cc)).slice(0, 5);
			},
			async saveFw() {
				this.busy = true;
				try {
					this.v = Object.assign(this.v, await HP.api('geo-firewall-set', this.payload()));
					this.v.now = Math.floor(Date.now() / 1000);
					await this.loadView();
					HP.toast(this.left > 0 ? 'Aplicado: confirma antes de 3 minutos' : 'Aplicado');
					this.check();
				} catch (e) {
					HP.toast(e.message, 'error');
				} finally {
					this.busy = false;
				}
			},
			async confirmFw() {
				try {
					await HP.api('geo-confirm');
					clearInterval(timer);
					this.left = 0;
					HP.toast('Confirmado. Los cambios quedan aplicados.');
					this.loadView();
				} catch (e) {
					HP.toast(e.message, 'error');
				}
			},
			num(n) {
				return n == null ? '—' : Number(n).toLocaleString();
			},
			pct(a, b) {
				if (!b) return '—';
				const p = (a * 100) / b;
				return a > 0 && p < 1 ? '<1%' : Math.round(p) + '%';
			},
		};
	});
});
</script>
