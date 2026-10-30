<?php
hp_page_header(__('Aplicaciones'), __('Node.js, Python, contenedores Docker y proxys publicados en tus dominios, con reinicio automático y SSL.'), [],
	('<a class="hp-btn hp-btn-primary" href="/pro/new/?hosting=node">' . '<i class="fas fa-plus">' . '</i>' . __('Nueva app en un dominio nuevo') . '</a>'));
?>
<div x-data="hpApps(<?= e(json_encode(["isAdmin" => hp_admin(), "openNew" => !empty($_GET["new"])])) ?>)">
	<div class="hp-tabs">
		<button class="hp-tab" :class="tab === 'list' && 'active'" x-on:click="go('list')"><i class="fas fa-cubes"></i><?= __e('Aplicaciones') ?> <span class="hp-badge hp-badge-muted"><?= count($apps) ?></span></button>
		<button class="hp-tab" :class="tab === 'new' && 'active'" x-on:click="go('new')"><i class="fas fa-plus"></i><?= __e('Añadir a un dominio existente') ?></button>
		<button class="hp-tab" :class="tab === 'runtimes' && 'active'" x-on:click="go('runtimes')"><i class="fas fa-layer-group"></i>Entornos (versiones)</button>
	</div>

	<!-- Lista -->
	<div x-show="tab === 'list'">
		<div class="hp-card">
			<?php if (!$apps) { ?>
				<div class="hp-empty">
					<i class="fas fa-cubes hp-empty-icon"></i>
					<h3><?= __e('Todavía no hay aplicaciones') ?></h3>
					<p><?= __e('Publica una app Node.js, Python o un contenedor Docker en cualquier dominio. iaachz-panel crea el servicio, el proxy nginx y el SSL.') ?></p>
					<button type="button" class="hp-btn hp-btn-primary" x-on:click="go('new')"><i class="fas fa-plus"></i><?= __e('Añadir aplicación') ?></button>
				</div>
			<?php } else { ?>
				<div class="hp-table-wrap">
					<table class="hp-table">
						<thead><tr><th></th><th><?= __e('Dominio') ?></th><?= hp_admin() ? ("<th>" . __('Suscripción') . "</th>") : "" ?><th><?= __e('Entorno') ?></th><th><?= __e('Estado') ?></th><th class="hp-right">RAM</th><th class="hp-right"><?= __e('Puerto') ?></th><th class="hp-right"><?= __e('Acciones') ?></th></tr></thead>
						<tbody>
							<?php foreach ($apps as $a) {
								$q = http_build_query(["user" => $a["user"], "domain" => $a["domain"]]);
								$desc = ["node" => "Node.js " . ($a["version"] ?? ""), "python" => "Python " . ($a["version"] ?? "") . " · " . ($a["server"] ?? ""), "docker" => $a["image"] ?? "", "proxy" => $a["target"] ?? ""][$a["type"]] ?? $a["type"];
								?>
								<tr>
									<td style="width:44px"><?= hp_type_chip($a["type"], "sm") ?></td>
									<td><a href="/pro/site/?<?= e($q) ?>#app"><b><?= e($a["domain"]) ?></b></a></td>
									<?= hp_admin() ? "<td>" . e($a["user"]) . "</td>" : "" ?>
									<td class="hp-mono"><?= e($desc) ?></td>
									<td><?= hp_state_badge($a["state"]) ?></td>
									<td class="hp-right hp-nowrap"><?= !empty($a["memory"]) ? hp_bytes($a["memory"]) : "—" ?></td>
									<td class="hp-right hp-mono"><?= !empty($a["port"]) ? (int) $a["port"] : "—" ?></td>
									<td class="hp-right hp-nowrap">
										<?php if ($a["type"] !== "proxy") { ?>
											<button type="button" class="hp-btn hp-btn-xs" title="<?= __e('Reiniciar') ?>" x-on:click="act(<?= e(json_encode($a["user"])) ?>, <?= e(json_encode($a["domain"])) ?>, 'restart')"><i class="fas fa-rotate"></i></button>
											<?php if ($a["state"] === "running") { ?>
												<button type="button" class="hp-btn hp-btn-xs" title="<?= __e('Detener') ?>" x-on:click="act(<?= e(json_encode($a["user"])) ?>, <?= e(json_encode($a["domain"])) ?>, 'stop')"><i class="fas fa-stop"></i></button>
											<?php } else { ?>
												<button type="button" class="hp-btn hp-btn-xs" title="<?= __e('Iniciar') ?>" x-on:click="act(<?= e(json_encode($a["user"])) ?>, <?= e(json_encode($a["domain"])) ?>, 'start')"><i class="fas fa-play"></i></button>
											<?php } ?>
										<?php } ?>
										<button type="button" class="hp-btn hp-btn-xs" title="<?= __e('Registros') ?>" x-on:click="logs(<?= e(json_encode($a["user"])) ?>, <?= e(json_encode($a["domain"])) ?>)"><i class="fas fa-file-lines"></i></button>
										<a class="hp-btn hp-btn-xs" title="<?= __e('Configurar') ?>" href="/pro/site/?<?= e($q) ?>#app"><i class="fas fa-gear"></i></a>
									</td>
								</tr>
							<?php } ?>
						</tbody>
					</table>
				</div>
			<?php } ?>
		</div>
	</div>

	<!-- Nueva en dominio existente -->
	<div x-show="tab === 'new'" x-cloak>
		<div class="hp-card">
			<div class="hp-card-head"><h2 class="hp-card-title"><i class="fas fa-plus"></i><?= __e('Elige el dominio donde se publicará la aplicación') ?></h2></div>
			<div class="hp-card-body">
				<?php if (!$free) { ?>
					<div class="hp-empty"><p><?= __e('Todos tus dominios ya tienen una aplicación o no tienes dominios.') ?></p><a class="hp-btn hp-btn-primary" href="/pro/new/?hosting=node"><i class="fas fa-plus"></i><?= __e('Crear dominio con app') ?></a></div>
				<?php } else { ?>
					<p class="hp-muted" style="margin-top:0"><?= __e('El dominio dejará de servir PHP y pasará a servir la aplicación. Puedes volver atrás quitando la aplicación.') ?></p>
					<div class="hp-tools">
						<?php foreach ($free as $s) { ?>
							<a class="hp-tool" href="/pro/site/?<?= e(http_build_query(["user" => $s["user"], "domain" => $s["domain"]])) ?>#app">
								<i class="fas fa-earth-americas"></i><span style="min-width:0;overflow:hidden;text-overflow:ellipsis"><?= e($s["domain"]) ?><?= hp_admin() ? '<br><small class="hp-muted">' . e($s["user"]) . "</small>" : "" ?></span>
							</a>
						<?php } ?>
					</div>
				<?php } ?>
			</div>
		</div>
	</div>

	<!-- Entornos -->
	<div x-show="tab === 'runtimes'" x-cloak>
		<template x-if="!rt"><div class="hp-card"><div class="hp-empty"><i class="fas fa-circle-notch fa-spin"></i> <?= __e('Cargando entornos…') ?></div></div></template>
		<template x-if="rt">
			<div class="hp-grid hp-grid-2">
				<div class="hp-card">
					<div class="hp-card-head"><h2 class="hp-card-title"><span class="hp-chip hp-chip-node hp-chip-sm">JS</span>Node.js</h2></div>
					<div class="hp-card-body hp-flush">
						<table class="hp-table">
							<tbody>
								<template x-for="m in rt.node.available" :key="m">
									<tr>
										<td><b x-text="'Node.js ' + m"></b> <span class="hp-badge hp-badge-info" x-show="['22','24'].includes(m)">LTS</span>
											<div class="hp-muted hp-small" x-text="rt.node.installed[m] ? HP.t('Instalado: ') + rt.node.installed[m] + (rt.node.in_use[m] ? HP.t(' · usado por ') + rt.node.in_use[m].join(', ') : '') : HP.t('No instalado')"></div></td>
										<td class="hp-right">
											<template x-if="isAdmin && !rt.node.installed[m]"><button type="button" class="hp-btn hp-btn-sm hp-btn-primary" x-on:click="install('node', m)"><i class="fas fa-download"></i><?= __e('Instalar') ?></button></template>
											<template x-if="isAdmin && rt.node.installed[m]"><span class="hp-btn-group">
												<button type="button" class="hp-btn hp-btn-sm" x-on:click="install('node', m)" title="<?= __e('Actualizar a la última versión menor') ?>"><i class="fas fa-arrows-rotate"></i></button>
												<button type="button" class="hp-btn hp-btn-sm hp-btn-danger" x-on:click="remove('node', m)" :disabled="rt.node.in_use[m]"><i class="fas fa-trash"></i></button>
											</span></template>
										</td>
									</tr>
								</template>
							</tbody>
						</table>
					</div>
				</div>
				<div class="hp-card">
					<div class="hp-card-head"><h2 class="hp-card-title"><span class="hp-chip hp-chip-python hp-chip-sm">PY</span>Python</h2></div>
					<div class="hp-card-body hp-flush">
						<table class="hp-table">
							<tbody>
								<tr x-show="rt.python.installed.system">
									<td><b><?= __e('Python del sistema') ?></b><div class="hp-muted hp-small" x-text="(rt.python.installed.system || {}).version"></div></td>
									<td class="hp-right"><span class="hp-badge hp-badge-ok"><?= __e('Incluido') ?></span></td>
								</tr>
								<template x-for="v in rt.python.available" :key="v">
									<tr>
										<td><b x-text="'Python ' + v"></b>
											<div class="hp-muted hp-small" x-text="rt.python.installed[v] ? HP.t('Instalado: ') + rt.python.installed[v].version + (rt.python.in_use[v] ? HP.t(' · usado por ') + rt.python.in_use[v].join(', ') : '') : HP.t('No instalado')"></div></td>
										<td class="hp-right">
											<template x-if="isAdmin && !rt.python.installed[v]"><button type="button" class="hp-btn hp-btn-sm hp-btn-primary" x-on:click="install('python', v)"><i class="fas fa-download"></i><?= __e('Instalar') ?></button></template>
											<template x-if="isAdmin && rt.python.installed[v]"><button type="button" class="hp-btn hp-btn-sm hp-btn-danger" x-on:click="remove('python', v)" :disabled="rt.python.in_use[v]"><i class="fas fa-trash"></i></button></template>
										</td>
									</tr>
								</template>
							</tbody>
						</table>
					</div>
				</div>
				<div class="hp-card" style="grid-column:1/-1" x-show="rt.apache">
					<div class="hp-card-body hp-between hp-wrap">
						<div class="hp-flex"><span class="hp-chip hp-chip-sm" style="background:linear-gradient(135deg,#d22128,#8a1538)">A</span><div><b><?= __e('Apache (detrás de nginx)') ?></b><div class="hp-muted hp-small" x-text="rt.apache && rt.apache.installed ? HP.t('Versión ') + rt.apache.version + HP.t(' en 127.0.0.1:') + rt.apache.port + ' · ' + rt.apache.domains + ' dominio(s) en modo nginx + Apache' + (rt.apache.active ? '' : HP.t(' · DETENIDO')) : 'No instalado: se instala solo al activar nginx + Apache en un dominio (Hosting y PHP)'"></div></div></div>
						<template x-if="isAdmin && rt.apache && !rt.apache.installed"><button type="button" class="hp-btn" x-on:click="HP.runJob('apache-install', {}, 'Instalando Apache').then(() => loadRt()).catch(e => HP.toast(e.message, 'error'))"><i class="fas fa-download"></i><?= __e('Instalar ahora') ?></button></template>
					</div>
				</div>
				<div class="hp-card" style="grid-column:1/-1">
					<div class="hp-card-body hp-between hp-wrap">
						<div class="hp-flex"><span class="hp-chip hp-chip-docker hp-chip-sm"><i class="fas fa-box"></i></span><div><b>Docker</b><div class="hp-muted hp-small" x-text="rt.docker.installed ? HP.t('Versión ') + rt.docker.version + HP.t(' · Compose ') + rt.docker.compose + (rt.docker.active ? '' : HP.t(' · DETENIDO')) : HP.t('No instalado')"></div></div></div>
						<a class="hp-btn" href="/pro/docker/" x-show="isAdmin"><i class="fas fa-box"></i><?= __e('Gestionar Docker') ?></a>
					</div>
				</div>
			</div>
		</template>
		<p class="hp-muted hp-small hp-mt"><?= __e('Las versiones se descargan de nodejs.org y python-build-standalone verificando su firma SHA256, y se instalan en') ?> <code>/opt/iaachz-panel/runtimes</code> <?= __e('sin tocar los paquetes del sistema.') ?></p>
	</div>
</div>

<script>
function hpApps(c) {
	return {
		...c,
		tab: c.openNew ? 'new' : (location.hash || '#list').slice(1),
		rt: null,
		init() {
			this.$watch('tab', (t) => t === 'runtimes' && !this.rt && this.loadRt());
			if (this.tab === 'runtimes') this.loadRt();
		},
		go(t) {
			history.replaceState(null, '', '#' + t);
			this.tab = t;
		},
		async loadRt() {
			try {
				this.rt = await HP.api('runtimes');
			} catch (e) {
				HP.toast(e.message, 'error');
			}
		},
		async install(kind, version) {
			try {
				await HP.runJob('runtime-install', { kind, version }, HP.t('Instalando ') + (kind === 'node' ? 'Node.js ' : 'Python ') + version, { autoClose: true });
				HP.toast(HP.t('Instalado'));
				this.loadRt();
			} catch (e) {
				HP.toast(e.message, 'error');
			}
		},
		async remove(kind, version) {
			if (!(await HP.confirm(HP.t('¿Desinstalar ') + kind + ' ' + version + '?', { danger: true, ok: HP.t('Desinstalar') }))) return;
			try {
				await HP.api('runtime-remove', { kind, version });
				HP.toast(HP.t('Desinstalado'));
				this.loadRt();
			} catch (e) {
				HP.toast(e.message, 'error');
			}
		},
		async act(user, domain, what) {
			try {
				await HP.api('app-action', { user, domain, what });
				HP.toast(HP.t('Hecho'));
				setTimeout(() => location.reload(), 500);
			} catch (e) {
				HP.toast(e.message, 'error');
			}
		},
		logs(user, domain) {
			HP.logs(HP.t('Registros de ') + domain, () => HP.api('app-logs', { user, domain, lines: 400 }));
		},
	};
}
</script>
