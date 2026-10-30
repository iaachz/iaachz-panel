<?php
$hour = (int) date("G");
$greet = $hour < 12 ? "Buenos días" : ($hour < 19 ? "Buenas tardes" : "Buenas noches");
$actions = '<a class="hp-btn" href="/pro/sites/"><i class="fas fa-earth-americas"></i>Sitios web</a>'
	. '<a class="hp-btn hp-btn-primary" href="/pro/new/"><i class="fas fa-plus"></i>Agregar dominio</a>';
if (hp_admin()) {
	$actions .= '<a class="hp-btn hp-btn-primary" href="/pro/new/?mode=subscription"><i class="fas fa-user-plus"></i>Nueva suscripción</a>';
}
hp_page_header($greet . ", " . hp_me(), hp_admin() ? "Resumen del servidor y de todas las suscripciones" : "Resumen de tu cuenta de hosting", [], $actions);
?>

<!-- KPIs -->
<div class="hp-grid hp-grid-4 hp-mb">
	<?php if (hp_admin()) { ?>
		<a class="hp-card hp-stat" href="/pro/subscriptions/" style="text-decoration:none">
			<div class="hp-stat-icon hp-tint-purple"><i class="fas fa-users"></i></div>
			<div><div class="hp-stat-value"><?= $totals["users"] ?></div><div class="hp-stat-label">Suscripciones</div></div>
		</a>
	<?php } else { $uu = $totals["user"]; ?>
		<div class="hp-card hp-stat">
			<div class="hp-stat-icon hp-tint-purple"><i class="fas fa-hard-drive"></i></div>
			<div style="flex:1">
				<div class="hp-stat-value"><?= hp_mb($uu["U_DISK"] ?? 0) ?></div>
				<div class="hp-stat-label">Disco usado de <?= hp_mb($uu["DISK_QUOTA"] ?? "unlimited") ?></div>
				<?php $pct = get_percentage($uu["U_DISK"] ?? 0, $uu["DISK_QUOTA"] ?? "unlimited"); ?>
				<div class="hp-progress hp-mt is-<?= $pct >= 90 ? "danger" : ($pct >= 75 ? "warn" : "ok") ?>" style="margin-top:8px"><span style="width:<?= $pct ?>%"></span></div>
			</div>
		</div>
	<?php } ?>
	<a class="hp-card hp-stat" href="/pro/sites/" style="text-decoration:none">
		<div class="hp-stat-icon hp-tint-blue"><i class="fas fa-earth-americas"></i></div>
		<div><div class="hp-stat-value"><?= $totals["web"] ?></div><div class="hp-stat-label">Sitios web</div></div>
	</a>
	<a class="hp-card hp-stat" href="/pro/apps/" style="text-decoration:none">
		<div class="hp-stat-icon hp-tint-green"><i class="fas fa-cubes"></i></div>
		<div><div class="hp-stat-value"><?= $running ?><span class="hp-muted" style="font-size:1rem"> / <?= count($apps) ?></span></div><div class="hp-stat-label">Aplicaciones en marcha</div></div>
	</a>
	<a class="hp-card hp-stat" href="/pro/databases/" style="text-decoration:none">
		<div class="hp-stat-icon hp-tint-orange"><i class="fas fa-database"></i></div>
		<div><div class="hp-stat-value"><?= $totals["db"] ?></div><div class="hp-stat-label">Bases de datos · <?= $totals["mail"] ?> buzones</div></div>
	</a>
</div>

<?php if (hp_admin()) { ?>
<div x-data="hpDashboard">
	<div class="hp-grid hp-grid-main">
		<div>
			<!-- Estado del servidor -->
			<div class="hp-card">
				<div class="hp-card-head">
					<h2 class="hp-card-title"><i class="fas fa-server"></i>Estado del servidor
						<span class="hp-badge hp-badge-ok" x-show="live && !error"><i class="fas fa-circle"></i>En vivo</span>
					</h2>
					<a class="hp-btn hp-btn-sm" href="/pro/monitor/"><i class="fas fa-chart-line"></i>Monitor completo</a>
				</div>
				<div class="hp-card-body">
					<p class="inline-alert inline-alert-danger" x-show="error" x-text="error" x-cloak></p>
					<template x-if="!live && !error"><div class="hp-empty"><i class="fas fa-circle-notch fa-spin"></i> Leyendo métricas…</div></template>
					<template x-if="live">
						<div>
							<div class="hp-gauges">
								<div class="hp-gauge">
									<div class="hp-gauge-ring"><span x-html="gauge(live.cpu)"></span><div class="hp-gauge-val" x-text="Math.round(live.cpu) + '%'"></div></div>
									<div class="hp-gauge-label">CPU</div>
									<div class="hp-gauge-sub" x-text="live.cores + ' núcleos'"></div>
								</div>
								<div class="hp-gauge">
									<div class="hp-gauge-ring"><span x-html="gauge(live.mem.percent)"></span><div class="hp-gauge-val" x-text="Math.round(live.mem.percent) + '%'"></div></div>
									<div class="hp-gauge-label">Memoria</div>
									<div class="hp-gauge-sub" x-text="fmt.bytes(live.mem.used) + ' de ' + fmt.bytes(live.mem.total)"></div>
								</div>
								<div class="hp-gauge">
									<div class="hp-gauge-ring"><span x-html="gauge(rootDisk().percent, 80, 90)"></span><div class="hp-gauge-val" x-text="Math.round(rootDisk().percent) + '%'"></div></div>
									<div class="hp-gauge-label">Disco /</div>
									<div class="hp-gauge-sub" x-text="fmt.bytes(rootDisk().used) + ' de ' + fmt.bytes(rootDisk().total)"></div>
								</div>
								<div class="hp-gauge">
									<div class="hp-gauge-ring"><span x-html="gauge(loadPct(), 70, 100)"></span><div class="hp-gauge-val" x-text="live.load[0].toFixed(2)"></div></div>
									<div class="hp-gauge-label">Carga</div>
									<div class="hp-gauge-sub" x-text="live.load[1].toFixed(2) + ' · ' + live.load[2].toFixed(2) + ' (5 / 15 min)'"></div>
								</div>
							</div>
							<div class="hp-grid hp-grid-4 hp-mt" style="gap:10px">
								<div class="hp-tag" style="justify-content:center;padding:8px"><i class="fas fa-arrow-down" style="color:#0ea5e9"></i><span x-text="fmt.rate(live.net.rx)"></span></div>
								<div class="hp-tag" style="justify-content:center;padding:8px"><i class="fas fa-arrow-up" style="color:#14945a"></i><span x-text="fmt.rate(live.net.tx)"></span></div>
								<a class="hp-tag" href="/pro/monitor/#traffic" title="Ver tráfico y conexiones" style="justify-content:center;padding:8px;text-decoration:none"><i class="fas fa-bolt" style="color:#1f6fd6"></i><span x-text="live.web && live.web.req != null ? live.web.req + ' pet/s · ' + live.web.active + ' conexiones' : 'Tráfico'"></span></a>
								<div class="hp-tag" style="justify-content:center;padding:8px"><i class="fas fa-clock"></i><span x-text="'Activo ' + fmt.duration(live.uptime)"></span></div>
							</div>
						</div>
					</template>
				</div>
			</div>

			<!-- Gráficos -->
			<div class="hp-card">
				<div class="hp-card-head">
					<h2 class="hp-card-title"><i class="fas fa-wave-square"></i>Tiempo real <span class="hp-muted hp-small" style="font-weight:400">(últimos 2 minutos)</span></h2>
				</div>
				<div class="hp-card-body"><div class="hp-chart hp-chart-sm"><canvas x-ref="live"></canvas></div></div>
			</div>
			<div class="hp-card">
				<div class="hp-card-head">
					<h2 class="hp-card-title"><i class="fas fa-chart-area"></i>Historial de recursos</h2>
					<div class="hp-segmented">
						<template x-for="r in ['1h','24h','7d','30d']">
							<button type="button" x-text="r" :class="range === r && 'active'" x-on:click="range = r; loadHistory()"></button>
						</template>
					</div>
				</div>
				<div class="hp-card-body"><div class="hp-chart"><canvas x-ref="hist"></canvas></div></div>
			</div>

			<!-- Procesos -->
			<div class="hp-card">
				<div class="hp-card-head"><h2 class="hp-card-title"><i class="fas fa-list-ol"></i>Procesos con más consumo</h2></div>
				<div class="hp-card-body hp-flush hp-table-wrap">
					<table class="hp-table">
						<thead><tr><th>Proceso</th><th>Usuario</th><th class="hp-right">CPU</th><th class="hp-right">RAM</th><th class="hp-right">Memoria</th></tr></thead>
						<tbody>
							<template x-for="p in (live ? live.top : [])" :key="p.pid">
								<tr>
									<td><span class="hp-mono" x-text="p.cmd"></span> <span class="hp-muted hp-small" x-text="'#' + p.pid"></span></td>
									<td x-text="p.user"></td>
									<td class="hp-right" x-text="p.cpu.toFixed(1) + '%'"></td>
									<td class="hp-right" x-text="p.mem.toFixed(1) + '%'"></td>
									<td class="hp-right" x-text="fmt.bytes(p.rss)"></td>
								</tr>
							</template>
						</tbody>
					</table>
				</div>
			</div>
		</div>

		<div>
			<!-- Información -->
			<div class="hp-card">
				<div class="hp-card-head"><h2 class="hp-card-title"><i class="fas fa-circle-info"></i>Servidor</h2></div>
				<div class="hp-card-body">
					<template x-if="live">
						<dl class="hp-kv">
							<dt>Nombre</dt><dd x-text="live.hostname"></dd>
							<dt>Sistema</dt><dd x-text="live.os"></dd>
							<dt>Kernel</dt><dd x-text="live.kernel"></dd>
							<dt>Procesador</dt><dd x-text="live.cpu_model"></dd>
							<dt>Hestia</dt><dd x-text="'v' + live.hestia_version + ' + Pro'"></dd>
							<dt>Procesos</dt><dd x-text="live.procs"></dd>
						</dl>
					</template>
				</div>
			</div>

			<!-- Acciones rápidas -->
			<div class="hp-card">
				<div class="hp-card-head"><h2 class="hp-card-title"><i class="fas fa-bolt"></i>Acciones rápidas</h2></div>
				<div class="hp-card-body">
					<div class="hp-tools" style="grid-template-columns:repeat(2,minmax(0,1fr))">
						<a class="hp-tool" href="/pro/new/"><i class="fas fa-earth-americas"></i>Agregar dominio</a>
						<a class="hp-tool" href="/pro/new/?mode=subscription"><i class="fas fa-user-plus"></i>Nueva suscripción</a>
						<a class="hp-tool" href="/pro/new/?hosting=node"><i class="fas fa-cube" style="color:#3c873a"></i>App Node.js</a>
						<a class="hp-tool" href="/pro/new/?hosting=python"><i class="fas fa-terminal" style="color:#306998"></i>App Python</a>
						<a class="hp-tool" href="/pro/new/?hosting=wordpress"><i class="fas fa-pen-nib" style="color:#21759b"></i>WordPress</a>
						<a class="hp-tool" href="/pro/docker/"><i class="fas fa-box" style="color:#1d63ed"></i>Docker</a>
						<a class="hp-tool" href="/add/db/"><i class="fas fa-database"></i>Base de datos</a>
						<a class="hp-tool" href="/add/mail/"><i class="fas fa-envelope"></i>Correo</a>
						<a class="hp-tool" href="/list/dns/"><i class="fas fa-sitemap"></i>DNS</a>
						<a class="hp-tool" href="/list/backup/"><i class="fas fa-life-ring"></i>Copias</a>
						<a class="hp-tool" href="/fm/"><i class="fas fa-folder-open"></i>Archivos</a>
						<a class="hp-tool" href="/list/firewall/"><i class="fas fa-shield"></i>Cortafuegos</a>
					</div>
				</div>
			</div>

			<!-- Servicios -->
			<div class="hp-card" id="services">
				<div class="hp-card-head">
					<h2 class="hp-card-title"><i class="fas fa-gears"></i>Servicios</h2>
					<a class="hp-btn hp-btn-sm" href="/list/server/">Gestionar</a>
				</div>
				<div class="hp-card-body hp-flush">
					<template x-if="!services"><div class="hp-empty"><i class="fas fa-circle-notch fa-spin"></i></div></template>
					<table class="hp-table" x-show="services">
						<tbody>
							<template x-for="(s, name) in services" :key="name">
								<tr>
									<td><span class="hp-dot" :class="s.STATE === 'running' ? 'ok' : 'danger'"></span></td>
									<td><b x-text="name"></b><div class="hp-muted hp-small" x-text="s.SYSTEM"></div></td>
									<td class="hp-right hp-small hp-muted hp-nowrap" x-text="s.MEM + ' MB'"></td>
									<td class="hp-right"><button type="button" class="hp-btn hp-btn-xs" x-on:click="restart(name)" title="Reiniciar"><i class="fas fa-rotate"></i></button></td>
								</tr>
							</template>
						</tbody>
					</table>
				</div>
			</div>

			<div class="hp-card">
				<div class="hp-card-head">
					<h2 class="hp-card-title"><i class="fas fa-shield-halved"></i>Asesor de seguridad</h2>
				</div>
				<div class="hp-card-body">
					<p class="hp-muted" style="margin-top:0">Revisa SSL, actualizaciones, servicios, disco y aplicaciones caídas con recomendaciones de un clic.</p>
					<a class="hp-btn hp-btn-block" href="/pro/advisor/"><i class="fas fa-stethoscope"></i>Ver recomendaciones</a>
				</div>
			</div>
		</div>
	</div>
</div>
<?php } ?>

<!-- Sitios -->
<div class="hp-card hp-mt">
	<div class="hp-card-head">
		<h2 class="hp-card-title"><i class="fas fa-earth-americas"></i><?= hp_admin() ? "Sitios recientes" : "Tus sitios web" ?></h2>
		<a class="hp-btn hp-btn-sm" href="/pro/sites/">Ver todos</a>
	</div>
	<div class="hp-card-body hp-flush">
		<?php
		usort($sites, fn($a, $b) => strcmp($b["created"] ?? "", $a["created"] ?? ""));
		$shown = array_slice(array_values(array_filter($sites, fn($s) => $s["hosting"] !== "none")), 0, hp_admin() ? 6 : 50);
		if (!$shown) { ?>
			<div class="hp-empty">
				<i class="fas fa-earth-americas hp-empty-icon"></i>
				<h3>Aún no hay sitios</h3>
				<p>Crea tu primer sitio web en menos de un minuto.</p>
				<a class="hp-btn hp-btn-primary" href="/pro/new/"><i class="fas fa-plus"></i>Agregar dominio</a>
			</div>
		<?php } ?>
		<?php foreach ($shown as $s) {
			$t = $s["app"]["type"] ?? ($s["cms"] === "WordPress" ? "wordpress" : $s["hosting"]); ?>
			<div class="hp-domain">
				<div class="hp-domain-row">
					<?= hp_type_chip($t) ?>
					<div>
						<a class="hp-domain-name" href="/pro/site/?<?= e(http_build_query(["user" => $s["user"], "domain" => $s["domain"]])) ?>"><?= e($s["domain"]) ?></a>
						<div class="hp-domain-meta">
							<?php if (hp_admin()) { ?><span class="hp-tag"><i class="fas fa-user"></i><?= e($s["user"]) ?></span><?php } ?>
							<span class="hp-tag"><?= e($s["app"] ? hp_hosting_meta($s["app"]["type"])[0] . (!empty($s["app"]["version"]) ? " " . $s["app"]["version"] : "") : ($s["cms"] ?: $s["php"])) ?></span>
							<?= $s["ssl"] ? '<span class="hp-badge hp-badge-ok"><i class="fas fa-lock"></i>HTTPS</span>' : '<span class="hp-badge hp-badge-warn"><i class="fas fa-lock-open"></i>Sin SSL</span>' ?>
							<?php if ($s["app"]) { echo hp_state_badge($s["app"]["state"]); } ?>
						</div>
					</div>
					<div class="hp-btn-group">
						<a class="hp-btn hp-btn-sm" href="<?= ($s["ssl"] ? "https://" : "http://") . e($s["domain"]) ?>/" target="_blank" rel="noopener"><i class="fas fa-arrow-up-right-from-square"></i><span class="hp-hide-sm">Abrir</span></a>
						<a class="hp-btn hp-btn-sm" href="/pro/site/?<?= e(http_build_query(["user" => $s["user"], "domain" => $s["domain"]])) ?>"><i class="fas fa-gear"></i><span class="hp-hide-sm">Gestionar</span></a>
					</div>
				</div>
			</div>
		<?php } ?>
	</div>
</div>
