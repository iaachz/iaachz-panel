<?php
hp_page_header("Monitor", "Recursos, tráfico web y conexiones del servidor, muestreados cada minuto (8 días al detalle y 1 año por horas).", [],
	'<a class="hp-btn" href="/pro/tools/#alerts"><i class="fas fa-bell"></i>Alertas</a><a class="hp-btn" href="/list/rrd/"><i class="fas fa-chart-column"></i>Gráficos de servicios (RRD)</a>');
?>
<div x-data="hpMonitor">
	<div class="hp-tabs">
		<button class="hp-tab" :class="tab === 'resources' && 'active'" x-on:click="go('resources')"><i class="fas fa-microchip"></i>Recursos</button>
		<button class="hp-tab" :class="tab === 'traffic' && 'active'" x-on:click="go('traffic')"><i class="fas fa-wave-square"></i>Tráfico y conexiones</button>
	</div>

	<div class="hp-between hp-mb hp-wrap">
		<div class="hp-segmented">
			<template x-for="r in ranges" :key="r[0]">
				<button type="button" :class="range === r[0] && 'active'" x-on:click="setRange(r[0])">
					<span x-show="r[0] === 'live'" class="hp-live-dot" :class="range === 'live' && 'on'"></span><span x-text="r[1]"></span>
				</button>
			</template>
		</div>
		<span class="hp-badge hp-badge-ok" x-show="range === 'live'" x-cloak><i class="fas fa-circle"></i>Actualizando cada ~2 s · últimos 5 minutos</span>
		<div class="hp-flex" style="margin-left:auto">
			<span class="hp-muted hp-small hp-hide-sm" x-show="live" x-text="live ? 'CPU ' + Math.round(live.cpu) + '% · RAM ' + Math.round(live.mem.percent) + '% · carga ' + live.load[0].toFixed(2) + (live.web && live.web.req != null ? ' · ' + live.web.req + ' pet/s' : '') : ''"></span>
			<div class="hp-menu" x-data="{ open: false }">
				<button type="button" class="hp-btn hp-btn-sm" :class="exporting && 'is-loading'" x-on:click="open = !open">
					<i class="fas" :class="exporting ? 'fa-circle-notch fa-spin' : 'fa-file-export'"></i>Exportar<i class="fas fa-chevron-down" style="font-size:.7em"></i>
				</button>
				<div class="hp-menu-panel hp-menu-right" x-cloak x-show="open" x-on:click.outside="open = false" style="min-width:290px">
					<button type="button" class="hp-menu-item" x-on:click="open = false; exportPdf()"><i class="fas fa-file-pdf" style="color:#d23f3f"></i><span>Informe PDF<br><small class="hp-muted">Se abre para imprimir → "Guardar como PDF"</small></span></button>
					<button type="button" class="hp-menu-item" x-on:click="open = false; exportHtml()"><i class="fas fa-file-code" style="color:#1f6fd6"></i><span>Informe HTML<br><small class="hp-muted">Un solo archivo con gráficos y tablas</small></span></button>
					<button type="button" class="hp-menu-item" x-on:click="open = false; exportCsv()"><i class="fas fa-file-csv" style="color:#14945a"></i><span>Datos CSV (Excel)<br><small class="hp-muted">Todas las series del periodo elegido</small></span></button>
				</div>
			</div>
		</div>
	</div>
	<p class="inline-alert inline-alert-info" x-show="error" x-cloak x-text="error"></p>

	<!-- ===================================================== RECURSOS -->
	<div x-show="tab === 'resources'">
		<div class="hp-grid hp-grid-2">
			<div class="hp-card"><div class="hp-card-head"><h2 class="hp-card-title"><i class="fas fa-microchip"></i>CPU</h2></div><div class="hp-card-body"><div class="hp-chart"><canvas x-ref="cpu"></canvas></div></div></div>
			<div class="hp-card"><div class="hp-card-head"><h2 class="hp-card-title"><i class="fas fa-memory"></i>Memoria</h2></div><div class="hp-card-body"><div class="hp-chart"><canvas x-ref="mem"></canvas></div></div></div>
			<div class="hp-card"><div class="hp-card-head"><h2 class="hp-card-title"><i class="fas fa-gauge-high"></i>Carga media</h2></div><div class="hp-card-body"><div class="hp-chart"><canvas x-ref="load"></canvas></div></div></div>
			<div class="hp-card"><div class="hp-card-head"><h2 class="hp-card-title"><i class="fas fa-network-wired"></i>Red</h2></div><div class="hp-card-body"><div class="hp-chart"><canvas x-ref="net"></canvas></div></div></div>
			<div class="hp-card"><div class="hp-card-head"><h2 class="hp-card-title"><i class="fas fa-hard-drive"></i>Lectura / escritura de disco</h2></div><div class="hp-card-body"><div class="hp-chart"><canvas x-ref="io"></canvas></div></div></div>
			<div class="hp-card"><div class="hp-card-head"><h2 class="hp-card-title"><i class="fas fa-database"></i>Ocupación del disco</h2></div><div class="hp-card-body"><div class="hp-chart"><canvas x-ref="disk"></canvas></div></div></div>
		</div>

		<div class="hp-grid hp-grid-2 hp-mt">
			<div class="hp-card">
				<div class="hp-card-head"><h2 class="hp-card-title"><i class="fas fa-hard-drive"></i>Discos</h2></div>
				<div class="hp-card-body">
					<template x-for="d in (live ? live.disks : [])" :key="d.mount">
						<div class="hp-mb">
							<div class="hp-between hp-small"><span><b x-text="d.mount"></b> <span class="hp-muted" x-text="d.device + ' · ' + d.fs"></span></span><span x-text="fmt.bytes(d.used) + ' / ' + fmt.bytes(d.total) + ' (' + d.percent + '%)'"></span></div>
							<div class="hp-progress" style="margin-top:6px" :class="'is-' + fmt.level(d.percent, 80, 90)"><span :style="'width:' + d.percent + '%'"></span></div>
							<div class="hp-muted hp-small" style="margin-top:4px" x-text="'Inodos ' + d.inodes_percent + '%'"></div>
						</div>
					</template>
				</div>
			</div>
			<div class="hp-card">
				<div class="hp-card-head"><h2 class="hp-card-title"><i class="fas fa-microchip"></i>Uso por núcleo</h2></div>
				<div class="hp-card-body">
					<div class="hp-grid" style="grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:10px">
						<template x-for="(c, i) in (live ? live.cpu_cores : [])" :key="i">
							<div>
								<div class="hp-between hp-small"><span class="hp-muted" x-text="'Núcleo ' + i"></span><b x-text="Math.round(c) + '%'"></b></div>
								<div class="hp-progress" style="margin-top:4px" :class="'is-' + fmt.level(c)"><span :style="'width:' + c + '%'"></span></div>
							</div>
						</template>
					</div>
				</div>
			</div>
		</div>

		<div class="hp-card hp-mt">
			<div class="hp-card-head"><h2 class="hp-card-title"><i class="fas fa-list-ol"></i>Procesos (se actualiza cada 5 s)</h2></div>
			<div class="hp-card-body hp-flush hp-table-wrap">
				<table class="hp-table">
					<thead><tr><th>PID</th><th>Proceso</th><th>Usuario</th><th class="hp-right">CPU</th><th class="hp-right">RAM</th><th class="hp-right">Memoria</th><th class="hp-right">Tiempo</th></tr></thead>
					<tbody>
						<template x-for="p in (live ? live.top : [])" :key="p.pid">
							<tr><td class="hp-mono" x-text="p.pid"></td><td class="hp-mono" x-text="p.cmd"></td><td x-text="p.user"></td><td class="hp-right" x-text="p.cpu.toFixed(1) + '%'"></td><td class="hp-right" x-text="p.mem.toFixed(1) + '%'"></td><td class="hp-right" x-text="fmt.bytes(p.rss)"></td><td class="hp-right" x-text="fmt.duration(p.time)"></td></tr>
						</template>
					</tbody>
				</table>
			</div>
		</div>
	</div>

	<!-- ===================================================== TRÁFICO -->
	<div x-show="tab === 'traffic'" x-cloak>
		<!-- En vivo -->
		<div class="hp-grid hp-grid-4 hp-mb">
			<div class="hp-card hp-stat">
				<div class="hp-stat-icon hp-tint-blue"><i class="fas fa-bolt"></i></div>
				<div><div class="hp-stat-value" x-text="live && live.web ? num(live.web.req, 1) : '…'"></div><div class="hp-stat-label">Peticiones por segundo (ahora)</div></div>
			</div>
			<div class="hp-card hp-stat">
				<div class="hp-stat-icon hp-tint-green"><i class="fas fa-plug"></i></div>
				<div><div class="hp-stat-value" x-text="live && live.web ? num(live.web.conn, 1) : '…'"></div><div class="hp-stat-label">Conexiones nuevas por segundo</div></div>
			</div>
			<div class="hp-card hp-stat">
				<div class="hp-stat-icon hp-tint-purple"><i class="fas fa-users"></i></div>
				<div><div class="hp-stat-value" x-text="live && live.web ? num(live.web.active) : '…'"></div>
					<div class="hp-stat-label" x-text="live && live.web ? 'Abiertas: ' + num(live.web.reading) + ' leyendo · ' + num(live.web.writing) + ' respondiendo · ' + num(live.web.waiting) + ' en espera' : 'Conexiones abiertas'"></div></div>
			</div>
			<div class="hp-card hp-stat">
				<div class="hp-stat-icon hp-tint-teal"><i class="fas fa-database"></i></div>
				<div><div class="hp-stat-value" x-text="live && live.web ? num(live.web.db_qps) : '…'"></div>
					<div class="hp-stat-label" x-text="live && live.web ? 'Consultas MySQL/s · ' + num(live.web.db_conn) + ' conexiones' : 'Consultas MySQL/s'"></div></div>
			</div>
		</div>

		<div class="hp-grid hp-grid-2">
			<div class="hp-card"><div class="hp-card-head"><h2 class="hp-card-title"><i class="fas fa-bolt"></i>Peticiones y conexiones por segundo</h2></div><div class="hp-card-body"><div class="hp-chart"><canvas x-ref="webreq"></canvas></div></div></div>
			<div class="hp-card"><div class="hp-card-head"><h2 class="hp-card-title"><i class="fas fa-traffic-light"></i>Respuestas por minuto según código <span class="hp-muted hp-small" style="font-weight:400" x-show="range === 'live'">(última hora)</span></h2></div><div class="hp-card-body"><div class="hp-chart"><canvas x-ref="codes"></canvas></div></div></div>
			<div class="hp-card"><div class="hp-card-head"><h2 class="hp-card-title"><i class="fas fa-users"></i>Conexiones abiertas</h2></div><div class="hp-card-body"><div class="hp-chart"><canvas x-ref="conns"></canvas></div></div></div>
			<div class="hp-card"><div class="hp-card-head"><h2 class="hp-card-title"><i class="fas fa-database"></i>MySQL / MariaDB</h2></div><div class="hp-card-body"><div class="hp-chart"><canvas x-ref="mysql"></canvas></div></div></div>
		</div>

		<!-- Resumen del periodo -->
		<template x-if="tr">
			<div>
				<div class="hp-grid hp-grid-4 hp-mt">
					<div class="hp-card hp-stat"><div><div class="hp-stat-value" x-text="num(tr.total.req)"></div><div class="hp-stat-label" :title="'IPs distintas: ' + num(tr.total.ips) + ' en total, ' + num(tr.total.visitors) + ' sin contar bots'" x-text="'Peticiones en el periodo · ' + num(tr.total.visitors) + ' visitantes (IPs distintas)'"></div></div></div>
					<div class="hp-card hp-stat hp-click" title="Ver las peticiones con error" x-on:click="logs({ status: 'err' })"><div><div class="hp-stat-value" :style="tr.total.req && (tr.total.s5 * 100 / tr.total.req) >= 1 ? 'color:var(--hp-danger)' : ''" x-text="pct(tr.total.s4 + tr.total.s5, tr.total.req)"></div><div class="hp-stat-label" x-text="'Con error (' + num(tr.total.s4) + ' 4xx · ' + num(tr.total.s5) + ' 5xx) · ver detalle'"></div></div></div>
					<div class="hp-card hp-stat hp-click" title="Ver las peticiones de bots" x-on:click="logs({ bots: 'only' })"><div><div class="hp-stat-value" x-text="pct(tr.total.bots, tr.total.req)"></div><div class="hp-stat-label">Bots, rastreadores y scripts</div></div></div>
					<div class="hp-card hp-stat"><div><div class="hp-stat-value" style="font-size:1rem" x-text="peakLabel(tr.peak)"></div><div class="hp-stat-label">Momento de más tráfico</div></div></div>
				</div>

				<div class="hp-card hp-mt" x-data="hpGeoCard" x-effect="setRange(range)">
					<div class="hp-card-head"><h2 class="hp-card-title"><i class="fas fa-earth-americas"></i>¿Desde dónde te visitan?</h2><a class="hp-btn hp-btn-sm" href="/pro/security/"><i class="fas fa-globe"></i>Seguridad y países</a></div>
					<div class="hp-card-body">
						<div class="hp-geo-card-grid">
							<div x-ref="map"></div>
							<div>
								<p class="hp-muted hp-small" x-show="!ready">Activa el acceso por país en <a href="/pro/security/">Seguridad y países</a> para ver el mapa.</p>
								<template x-for="c in top" :key="c.cc">
									<button type="button" class="hp-geo-row" x-on:click="logs(c.cc)" :title="'Ver las peticiones desde ' + HP.countryName(c.cc)">
										<span class="hp-cc-code" x-text="c.cc === '--' ? '?' : c.cc"></span><span class="hp-geo-name" x-text="HP.countryName(c.cc)"></span>
										<span class="hp-geo-bar"><i :style="'width:' + share(c.req) + '%'"></i></span><b x-text="shareLabel(c.req)"></b>
									</button>
								</template>
								<p class="hp-muted hp-small" x-show="ready && !top.length">Sin visitas registradas en este periodo.</p>
							</div>
						</div>
					</div>
				</div>

				<div class="hp-grid hp-grid-2 hp-mt">
					<div class="hp-card">
						<div class="hp-card-head"><h2 class="hp-card-title"><i class="fas fa-earth-americas"></i>Dominios con más tráfico</h2><button type="button" class="hp-btn hp-btn-sm" x-on:click="logs({})"><i class="fas fa-file-lines"></i>Ver registros</button></div>
						<div class="hp-card-body hp-flush hp-table-wrap">
							<table class="hp-table">
								<thead><tr><th>Dominio</th><th class="hp-right" title="IPs distintas que no son bots ni scripts (entre paréntesis, todas las IPs)">Visitantes</th><th class="hp-right">Peticiones</th><th class="hp-right">Errores</th><th class="hp-right">Bots</th><th class="hp-right">Enviado</th></tr></thead>
								<tbody>
									<template x-for="d in tr.domains" :key="d.domain">
										<tr class="hp-click" title="Ver las peticiones de este dominio" x-on:click="logs({ domain: d.domain })"><td><b x-text="d.domain"></b></td>
											<td class="hp-right hp-nowrap" :title="num(d.visitors) + ' IPs de personas · ' + num(d.ips) + ' IPs en total (con bots)'"><b x-text="num(d.visitors)"></b> <span class="hp-muted hp-small" x-show="d.ips !== d.visitors" x-text="'(' + num(d.ips) + ')'"></span></td>
											<td class="hp-right" x-text="num(d.req)"></td>
											<td class="hp-right"><template x-if="d.s4 + d.s5"><button type="button" :class="d.s5 ? 'hp-badge hp-badge-danger' : 'hp-badge hp-badge-warn'" :title="num(d.s4) + ' con 4xx · ' + num(d.s5) + ' con 5xx · clic para verlos'" x-on:click.stop="logs({ domain: d.domain, status: d.s5 ? '5xx' : 'err' })" x-text="pct(d.s4 + d.s5, d.req)"></button></template><span x-show="!(d.s4 + d.s5)">0%</span></td>
											<td class="hp-right"><a href="#" class="hp-linkish" title="Ver solo bots" x-on:click.prevent.stop="logs({ domain: d.domain, bots: 'only' })" x-text="pct(d.bots, d.req)"></a></td><td class="hp-right" x-text="fmt.bytes(d.bytes)"></td></tr>
									</template>
									<template x-if="!tr.domains.length"><tr><td colspan="6" class="hp-muted">Sin tráfico registrado en este periodo todavía.</td></tr></template>
								</tbody>
							</table>
						</div>
					</div>
					<div class="hp-card">
						<div class="hp-card-head"><h2 class="hp-card-title"><i class="fas fa-link"></i>Páginas más visitadas</h2></div>
						<div class="hp-card-body hp-flush hp-table-wrap">
							<table class="hp-table">
								<thead><tr><th>URL</th><th class="hp-right">Peticiones</th></tr></thead>
								<tbody>
									<template x-for="u in tr.urls" :key="u.domain + u.path">
										<tr class="hp-click" title="Ver quién pide esta URL" x-on:click="logs({ domain: u.domain, q: u.path })"><td><span class="hp-mono hp-ellipsis" :title="u.path" x-text="u.path"></span><div class="hp-muted hp-small" x-text="u.domain"></div></td><td class="hp-right" x-text="num(u.req)"></td></tr>
									</template>
									<template x-if="!tr.urls.length"><tr><td colspan="2" class="hp-muted">Sin datos todavía.</td></tr></template>
								</tbody>
							</table>
						</div>
					</div>
					<div class="hp-card">
						<div class="hp-card-head"><h2 class="hp-card-title"><i class="fas fa-triangle-exclamation"></i>URLs con errores (404 y 5xx)</h2></div>
						<div class="hp-card-body hp-flush hp-table-wrap">
							<table class="hp-table">
								<thead><tr><th>URL</th><th class="hp-right">404</th><th class="hp-right">5xx</th></tr></thead>
								<tbody>
									<template x-for="u in tr.errors" :key="u.domain + u.path">
										<tr class="hp-click" title="Ver estas peticiones con error" x-on:click="logs({ domain: u.domain, q: u.path, status: u.e5 && !u.e404 ? '5xx' : (u.e404 && !u.e5 ? '404' : 'err') })"><td><span class="hp-mono hp-ellipsis" :title="u.path" x-text="u.path"></span><div class="hp-muted hp-small" x-text="u.domain"></div></td>
											<td class="hp-right"><a href="#" class="hp-linkish" x-show="u.e404" x-on:click.prevent.stop="logs({ domain: u.domain, q: u.path, status: '404' })" x-text="num(u.e404)"></a><span x-show="!u.e404">0</span></td>
											<td class="hp-right"><template x-if="u.e5"><button type="button" class="hp-badge hp-badge-danger" x-on:click.stop="logs({ domain: u.domain, q: u.path, status: '5xx' })" x-text="num(u.e5)"></button></template><span x-show="!u.e5">0</span></td></tr>
									</template>
									<template x-if="!tr.errors.length"><tr><td colspan="3" class="hp-muted">Sin errores en este periodo. 👍</td></tr></template>
								</tbody>
							</table>
						</div>
						<div class="hp-card-body hp-small hp-muted" style="border-top:1px solid var(--hp-border)">Los <b>404</b> suelen ser enlaces rotos o escáneres buscando archivos; los <b>5xx</b> son fallos de tu aplicación. Pulsa una fila para ver cada petición (IP, hora, navegador, de dónde viene).</div>
					</div>
					<div class="hp-card">
						<div class="hp-card-head"><h2 class="hp-card-title"><i class="fas fa-user-secret"></i>IPs sospechosas</h2></div>
						<div class="hp-card-body hp-flush hp-table-wrap">
							<table class="hp-table">
								<thead><tr><th>IP</th><th class="hp-right">Peticiones</th><th class="hp-right">Con error</th></tr></thead>
								<tbody>
									<template x-for="i in tr.suspects" :key="i.ip">
										<tr class="hp-click" title="Ver qué buscó esta IP" x-on:click="logs({ ip: i.ip, status: 'err' })"><td class="hp-nowrap"><span class="hp-cc-code" x-show="i.cc" x-text="i.cc" :title="HP.countryName(i.cc)" style="margin-right:6px"></span><span class="hp-mono" x-text="i.ip"></span></td><td class="hp-right" x-text="num(i.req)"></td><td class="hp-right"><span class="hp-badge hp-badge-danger" x-text="pct(i.err, i.req)"></span></td></tr>
									</template>
									<template x-if="!tr.suspects.length"><tr><td colspan="3" class="hp-muted">Ninguna IP con un patrón de escaneo o ataque. 👍</td></tr></template>
								</tbody>
							</table>
						</div>
						<div class="hp-card-body hp-small hp-muted" style="border-top:1px solid var(--hp-border)">Se marca una IP cuando hace 30 o más peticiones y al menos la mitad fallan. Puedes bloquearla en <a href="/list/firewall/banlist/">Cortafuegos → IPs bloqueadas</a>.</div>
					</div>
					<div class="hp-card">
						<div class="hp-card-head"><h2 class="hp-card-title"><i class="fas fa-ranking-star"></i>IPs con más peticiones</h2></div>
						<div class="hp-card-body hp-flush hp-table-wrap">
							<table class="hp-table">
								<thead><tr><th>IP</th><th class="hp-right">Peticiones</th><th class="hp-right">Con error</th></tr></thead>
								<tbody>
									<template x-for="i in tr.ips" :key="i.ip">
										<tr class="hp-click" title="Ver las peticiones de esta IP" x-on:click="logs({ ip: i.ip })"><td class="hp-nowrap"><span class="hp-cc-code" x-show="i.cc" x-text="i.cc" :title="HP.countryName(i.cc)" style="margin-right:6px"></span><span class="hp-mono" x-text="i.ip"></span></td><td class="hp-right" x-text="num(i.req)"></td><td class="hp-right"><a href="#" class="hp-linkish" x-show="i.err" x-on:click.prevent.stop="logs({ ip: i.ip, status: 'err' })" x-text="pct(i.err, i.req)"></a><span x-show="!i.err">0%</span></td></tr>
									</template>
									<template x-if="!tr.ips.length"><tr><td colspan="3" class="hp-muted">Sin datos todavía.</td></tr></template>
								</tbody>
							</table>
						</div>
					</div>
					<div class="hp-card">
						<div class="hp-card-head"><h2 class="hp-card-title"><i class="fas fa-satellite-dish"></i>Conectados ahora (HTTP/HTTPS)</h2></div>
						<div class="hp-card-body hp-flush hp-table-wrap">
							<table class="hp-table">
								<thead><tr><th>IP</th><th class="hp-right">Conexiones abiertas</th></tr></thead>
								<tbody>
									<template x-for="i in (live && live.web_ips ? live.web_ips.top : [])" :key="i.ip">
										<tr class="hp-click" title="Ver lo que pidió esta IP en los últimos 15 minutos" x-on:click="logs({ ip: i.ip, range: '15m' })"><td class="hp-mono" x-text="i.ip"></td><td class="hp-right" x-text="i.conns"></td></tr>
									</template>
								</tbody>
							</table>
						</div>
						<div class="hp-card-body hp-small hp-muted" style="border-top:1px solid var(--hp-border)" x-text="live && live.web_ips ? live.web_ips.total + ' conexiones de ' + live.web_ips.ips + ' IPs distintas · se actualiza cada 5 s' : ''"></div>
					</div>
				</div>
				<p class="hp-muted hp-small hp-mt"><i class="fas fa-hand-pointer"></i> Pulsa cualquier dominio, URL, IP o porcentaje de error para abrir sus registros filtrados. <span x-show="range === 'live'">En vivo, estas tablas resumen la última hora. </span>Páginas, errores e IPs se guardan 8 días; para periodos más largos se muestran los últimos 8 días. El análisis empezó a recogerse al instalar esta función, no incluye el historial anterior.</p>
			</div>
		</template>
	</div>
</div>
<?php include __DIR__ . "/logs_modal.php"; ?>
