<?php
$s = $site;
$d = $s["domain"];
$t = $s["app"]["type"] ?? ($s["cms"] === "WordPress" ? "wordpress" : $s["hosting"]);
$own = $s_user === hp_me();
$url = ($s["ssl"] ? "https://" : "http://") . $d . "/";
$qs = fn(array $extra = []) => e(http_build_query(array_merge(["domain" => $d, "token" => $_SESSION["token"]], $extra)));
$loginas = "/login/?" . e(http_build_query(["loginas" => $s_user, "token" => $_SESSION["token"]]));
$native = fn(string $href) => $own ? $href : $loginas;
$hasWeb = $s["hosting"] !== "none";
$cfg = ["user" => $s_user, "domain" => $d, "isAdmin" => hp_admin()];

$actions = $hasWeb ? '<a class="hp-btn" href="' . e($url) . '" target="_blank" rel="noopener"><i class="fas fa-arrow-up-right-from-square"></i>Abrir sitio</a>' : "";
if (!$own && hp_admin()) {
	$actions .= '<a class="hp-btn" href="' . $loginas . '"><i class="fas fa-right-to-bracket"></i>Entrar como ' . e($s_user) . "</a>";
}
hp_page_header($d, ($hasWeb ? e(hp_hosting_meta($t)[0]) . " · " : "") . "Suscripción <b>" . e($s_user) . "</b>" . (!empty($s["package"]) ? " · plan " . e($s["package"]) : ""), ["Sitios web y dominios" => "/pro/sites/"], $actions);
?>
<div x-data="hpSite(<?= e(json_encode(array_merge($cfg, ["ssl" => $s["ssl"], "force" => $s["force_ssl"] ?? false, "hsts" => $s["hsts"] ?? false, "backend" => $s["backend"] ?? "", "tpl" => $s["tpl"] ?? "", "suspended" => $s["suspended"], "wp" => $s["cms"] === "WordPress", "dns" => $s["dns"], "mail" => $s["mail"], "webserver" => $apache["enabled"] ? "apache" : "nginx", "apacheStatic" => $apache["static"], "apacheExt" => $apache["ext"]]))) ?>)">
	<div class="hp-tabs">
		<button class="hp-tab" :class="tab === 'overview' && 'active'" x-on:click="go('overview')"><i class="fas fa-gauge"></i>Panel</button>
		<?php if ($hasWeb) { ?>
			<button class="hp-tab" :class="tab === 'hosting' && 'active'" x-on:click="go('hosting')"><i class="fas fa-sliders"></i>Hosting y PHP</button>
			<button class="hp-tab" :class="tab === 'app' && 'active'" x-on:click="go('app')"><i class="fas fa-cubes"></i>Aplicación</button>
			<button class="hp-tab" :class="tab === 'ssl' && 'active'" x-on:click="go('ssl')"><i class="fas fa-lock"></i>SSL/TLS</button>
			<button class="hp-tab" :class="tab === 'git' && 'active'" x-on:click="go('git')"><i class="fas fa-code-branch"></i>Git</button>
			<?php if ($s["cms"] === "WordPress") { ?>
				<button class="hp-tab" :class="tab === 'wp' && 'active'" x-on:click="go('wp')"><i class="fas fa-pen-nib"></i>WordPress</button>
			<?php } ?>
		<?php } ?>
		<button class="hp-tab" :class="tab === 'danger' && 'active'" x-on:click="go('danger')"><i class="fas fa-triangle-exclamation"></i>Suspender / eliminar</button>
	</div>

	<!-- ===================================================== PANEL -->
	<div x-show="tab === 'overview'">
		<div class="hp-grid hp-grid-main">
			<div>
				<div class="hp-card">
					<div class="hp-card-body hp-flex" style="gap:16px">
						<?= hp_type_chip($t, "lg") ?>
						<div style="flex:1;min-width:0">
							<div style="font-size:1.2rem;font-weight:700;color:var(--hp-text)"><?= e($d) ?></div>
							<div class="hp-domain-meta">
								<?php if ($s["suspended"]) { ?><span class="hp-badge hp-badge-danger">Suspendido</span><?php } else { ?><span class="hp-badge hp-badge-ok"><i class="fas fa-circle"></i>Activo</span><?php } ?>
								<?php if ($hasWeb) {
									echo $s["ssl"] ? '<span class="hp-badge hp-badge-ok"><i class="fas fa-lock"></i>HTTPS' . (!empty($s["cert"]) ? " · vence en " . (int) $s["cert"]["days"] . " días" : "") . "</span>" : '<span class="hp-badge hp-badge-warn"><i class="fas fa-lock-open"></i>Sin SSL</span>';
								} ?>
								<?php if ($s["app"]) { echo hp_state_badge($s["app"]["state"]); } ?>
								<template x-if="dnsInfo">
									<span class="hp-badge" :class="dnsInfo.points_here ? 'hp-badge-ok' : 'hp-badge-warn'" :title="'A: ' + (dnsInfo.a.join(', ') || 'sin registro')">
										<i class="fas fa-sitemap"></i><span x-text="dnsInfo.points_here ? 'DNS apunta aquí' : (dnsInfo.a.length ? 'DNS apunta a ' + dnsInfo.a[0] : 'Sin registro A')"></span>
									</span>
								</template>
							</div>
						</div>
					</div>
				</div>

				<?php if ($hasWeb) { ?>
				<div class="hp-card">
					<div class="hp-card-head">
						<h2 class="hp-card-title"><i class="fas fa-wave-square"></i>Tráfico</h2>
						<div class="hp-flex">
							<div class="hp-segmented">
								<template x-for="r in [['1h','1 h'],['24h','24 h'],['7d','7 días']]">
									<button type="button" x-text="r[1]" :class="trRange === r[0] && 'active'" x-on:click="trRange = r[0]; loadTraffic()"></button>
								</template>
							</div>
							<button type="button" class="hp-btn hp-btn-sm" x-on:click="logs({})"><i class="fas fa-file-lines"></i>Registros</button>
							<button type="button" class="hp-btn hp-btn-sm" x-on:click="logs({ kind: 'error' })"><i class="fas fa-bug"></i>Errores</button>
						</div>
					</div>
					<div class="hp-card-body">
						<template x-if="tr">
							<div class="hp-domain-meta" style="margin:0 0 12px">
								<span class="hp-tag" :title="(tr.top.total.ips || 0).toLocaleString() + ' IPs distintas en total, contando bots'"><i class="fas fa-users"></i><span x-text="(tr.top.total.visitors || 0).toLocaleString() + ' visitantes'"></span></span>
								<span class="hp-tag"><i class="fas fa-bolt"></i><span x-text="tr.top.total.req.toLocaleString() + ' peticiones'"></span></span>
								<button type="button" class="hp-tag hp-click" style="border:0;font:inherit" :class="tr.top.total.s5 && 'hp-badge-danger'" title="Ver las peticiones con error" x-on:click="logs({ status: 'err' })"><i class="fas fa-triangle-exclamation"></i><span x-text="tr.top.total.s4 + ' errores 4xx · ' + tr.top.total.s5 + ' errores 5xx'"></span></button>
								<span class="hp-tag"><i class="fas fa-robot"></i><span x-text="(tr.top.total.req ? Math.round(tr.top.total.bots * 100 / tr.top.total.req) : 0) + '% bots'"></span></span>
								<span class="hp-tag"><i class="fas fa-arrow-up"></i><span x-text="HP.bytes(tr.top.total.bytes) + ' enviados'"></span></span>
							</div>
						</template>
						<div class="hp-chart hp-chart-sm"><canvas x-ref="dtraffic"></canvas></div>
						<template x-if="tr && (tr.top.urls.length || tr.top.errors.length)">
							<div class="hp-grid hp-grid-2 hp-mt">
								<div>
									<div class="hp-label">Páginas más visitadas</div>
									<template x-for="u in tr.top.urls.slice(0, 6)" :key="u.path">
										<div class="hp-between hp-small hp-click" title="Ver quién pide esta URL" x-on:click="logs({ q: u.path })" style="padding:3px 0;border-bottom:1px solid var(--hp-border)"><span class="hp-mono" style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap" x-text="u.path"></span><b x-text="u.req"></b></div>
									</template>
								</div>
								<div>
									<div class="hp-label">URLs con errores</div>
									<template x-for="u in tr.top.errors.slice(0, 6)" :key="u.path">
										<div class="hp-between hp-small hp-click" title="Ver estas peticiones con error" x-on:click="logs({ q: u.path, status: u.e5 && !u.e404 ? '5xx' : (u.e404 && !u.e5 ? '404' : 'err') })" style="padding:3px 0;border-bottom:1px solid var(--hp-border)"><span class="hp-mono" style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap" x-text="u.path"></span><span class="hp-badge" :class="u.e5 ? 'hp-badge-danger' : 'hp-badge-warn'" x-text="(u.e404 ? u.e404 + '×404 ' : '') + (u.e5 ? u.e5 + '×5xx' : '')"></span></div>
									</template>
									<template x-if="!tr.top.errors.length"><p class="hp-muted hp-small" style="margin:0">Sin errores 👍</p></template>
								</div>
							</div>
						</template>
					</div>
				</div>
				<?php } ?>

				<div class="hp-card">
					<div class="hp-card-head"><h2 class="hp-card-title"><i class="fas fa-toolbox"></i>Herramientas del dominio</h2></div>
					<div class="hp-card-body">
						<div class="hp-tools" style="grid-template-columns:repeat(auto-fill,minmax(130px,1fr))">
							<?php if ($hasWeb) { ?>
								<a class="hp-tool hp-tool-big" href="<?= $native("/fm/?path=" . urlencode("/web/" . $d)) ?>"><i class="fas fa-folder-open"></i>Archivos</a>
								<button type="button" class="hp-tool hp-tool-big" x-on:click="go('hosting')"><i class="fas fa-sliders"></i>Hosting y PHP</button>
								<button type="button" class="hp-tool hp-tool-big" x-on:click="go('app')"><i class="fas fa-cubes"></i>Node.js · Python · Docker</button>
								<button type="button" class="hp-tool hp-tool-big" x-on:click="go('ssl')"><i class="fas fa-lock"></i>SSL/TLS</button>
								<button type="button" class="hp-tool hp-tool-big" x-on:click="go('git')"><i class="fas fa-code-branch"></i>Git</button>
							<?php } ?>
							<a class="hp-tool hp-tool-big" href="<?= $native($s["dns"] ? "/list/dns/?" . $qs() : "/list/dns-proveedor/?" . e(http_build_query(["domain" => $d]))) ?>"><i class="fas fa-sitemap"></i>DNS</a>
							<a class="hp-tool hp-tool-big" href="<?= $native($s["mail"] ? "/list/mail/?" . $qs() : "/add/mail/") ?>"><i class="fas fa-envelope"></i>Correo</a>
							<a class="hp-tool hp-tool-big" href="/pro/databases/"><i class="fas fa-database"></i>Bases de datos</a>
							<?php if ($hasWeb) { ?>
								<a class="hp-tool hp-tool-big" href="<?= $native("/list/web-log/?" . e(http_build_query(["domain" => $d, "type" => "access"]))) ?>"><i class="fas fa-file-lines"></i>Registros</a>
								<?php if (!empty($s["stats"])) { ?>
									<a class="hp-tool hp-tool-big" href="<?= e($url) ?>vstats/" target="_blank" rel="noopener"><i class="fas fa-chart-column"></i>Estadísticas</a>
								<?php } ?>
								<?php if ($s["cms"] === "WordPress") { ?>
									<button type="button" class="hp-tool hp-tool-big" x-on:click="go('wp')"><i class="fas fa-pen-nib"></i>WordPress</button>
								<?php } elseif ($own && !$s["app"]) { ?>
									<a class="hp-tool hp-tool-big" href="/add/webapp/?<?= e(http_build_query(["domain" => $d])) ?>"><i class="fas fa-wand-magic-sparkles"></i>Instalar aplicación</a>
								<?php } ?>
								<a class="hp-tool hp-tool-big" href="<?= $native("/list/backup/") ?>"><i class="fas fa-life-ring"></i>Copias</a>
								<a class="hp-tool hp-tool-big" href="<?= $native("/edit/web/?" . $qs()) ?>"><i class="fas fa-pen"></i>Ajustes avanzados</a>
							<?php } ?>
						</div>
					</div>
				</div>
			</div>

			<div>
				<div class="hp-card">
					<div class="hp-card-head"><h2 class="hp-card-title"><i class="fas fa-circle-info"></i>Detalles</h2></div>
					<div class="hp-card-body">
						<dl class="hp-kv">
							<dt>Suscripción</dt><dd><?= e($s_user) ?></dd>
							<?php if ($hasWeb) { ?>
								<dt>IP</dt><dd><?= e($s["ip"]) ?></dd>
								<dt>Alias</dt><dd><?= e(implode(", ", $s["aliases"]) ?: "—") ?></dd>
								<dt>Hosting</dt><dd><?= e($s["app"] ? hp_hosting_meta($s["app"]["type"])[0] . " " . ($s["app"]["version"] ?? "") : $s["php"]) ?></dd>
								<dt>Servidor web</dt><dd><?= $s["app"] ? "nginx (proxy a la aplicación)" : (($s["webserver"] ?? "nginx") === "apache" ? "nginx + Apache (.htaccess)" : "Solo nginx") ?></dd>
								<dt>Plantilla</dt><dd class="hp-mono"><?= e($s["tpl"]) ?></dd>
								<dt>Carpeta</dt><dd class="hp-mono"><?= e($s["docroot"] ?: "/home/" . $s_user . "/web/" . $d . "/public_html") ?></dd>
								<dt>Disco</dt><dd><?= hp_mb($s["disk"]) ?></dd>
								<dt>Tráfico</dt><dd><?= hp_mb($s["bandwidth"]) ?> este mes</dd>
								<dt>Creado</dt><dd><?= e($s["created"]) ?></dd>
							<?php } ?>
							<dt>DNS</dt><dd><?= $s["dns"] ? "Zona local · " . (int) $s["dns_records"] . " registros" : "Externo" ?></dd>
							<dt>Correo</dt><dd><?= $s["mail"] ? (int) $s["mail_accounts"] . " buzones" : "Desactivado" ?></dd>
						</dl>
					</div>
				</div>
			</div>
		</div>
	</div>

	<?php if ($hasWeb) { ?>
	<!-- ===================================================== HOSTING -->
	<div x-show="tab === 'hosting'" x-cloak>
		<div class="hp-card hp-mb">
			<div class="hp-card-head">
				<h2 class="hp-card-title"><i class="fas fa-server"></i>Servidor web (nginx y Apache)</h2>
				<?php if (!empty($apache["apache"]["installed"])) { ?>
					<span class="hp-badge <?= $apache["apache"]["active"] ? "hp-badge-ok" : "hp-badge-danger" ?>"><i class="fas fa-circle"></i>Apache <?= e($apache["apache"]["version"]) ?> <?= $apache["apache"]["active"] ? "activo" : "detenido" ?></span>
				<?php } ?>
			</div>
			<div class="hp-card-body hp-form">
				<?php if ($s["app"]) { ?>
					<p class="inline-alert inline-alert-info" style="margin:0"><i class="fas fa-circle-info"></i> Este dominio sirve una aplicación <?= e(hp_hosting_meta($s["app"]["type"])[0]) ?> a través de nginx. Quita la aplicación para elegir entre nginx y Apache.</p>
				<?php } else { ?>
					<div class="hp-choices" style="grid-template-columns:repeat(auto-fill,minmax(260px,1fr))">
						<label class="hp-choice" :class="webserver === 'nginx' && 'active'" x-on:click="webserver = 'nginx'">
							<span class="hp-chip hp-chip-sm" style="background:linear-gradient(135deg,#2bb24c,#009639)">N</span>
							<b>Solo nginx</b><small>nginx ejecuta PHP-FPM directamente. Lo más rápido y con menos memoria. Las reglas van en las plantillas nginx; <b>no lee .htaccess</b>.</small>
						</label>
						<label class="hp-choice" :class="webserver === 'apache' && 'active'" x-on:click="webserver = 'apache'">
							<span class="hp-chip hp-chip-sm" style="background:linear-gradient(135deg,#d22128,#8a1538)">A</span>
							<b>nginx + Apache</b><small>nginx recibe las visitas y el SSL y pasa las páginas a Apache, que <b>respeta .htaccess</b> (WordPress, Joomla, PrestaShop, reglas de reescritura y protección por carpetas). Como el modo proxy de Plesk.</small>
						</label>
					</div>
					<template x-if="webserver === 'apache'">
						<div class="hp-form">
							<label class="hp-check"><input type="checkbox" x-model="apacheStatic"><span><b>nginx sirve directamente los archivos estáticos</b><small>Imágenes, CSS, JS y fuentes salen de nginx sin pasar por Apache (más rápido). Desmárcalo si tus .htaccess protegen esos archivos.</small></span></label>
							<div class="hp-field" x-show="apacheStatic">
								<label>Extensiones estáticas</label>
								<input class="hp-input hp-mono" x-model="apacheExt">
								<p class="hp-hint">Separadas por comas. Nunca se incluyen .php ni .htaccess.</p>
							</div>
						</div>
					</template>
					<?php if (empty($apache["apache"]["installed"])) { ?>
						<p class="hp-hint" style="margin:0" x-show="webserver === 'apache'"><i class="fas fa-circle-info"></i> Apache aún no está instalado: se instalará automáticamente (solo escuchará en 127.0.0.1:7080, detrás de nginx).</p>
					<?php } ?>
					<div><button type="button" class="hp-btn hp-btn-primary" x-on:click="applyWebserver()"><i class="fas fa-check"></i>Aplicar servidor web</button></div>
				<?php } ?>
			</div>
		</div>
		<div class="hp-grid hp-grid-2">
			<div class="hp-card">
				<div class="hp-card-head"><h2 class="hp-card-title"><i class="fas fa-code"></i>Versión de PHP</h2></div>
				<div class="hp-card-body hp-form">
					<?php if ($s["app"]) { ?>
						<p class="inline-alert inline-alert-info" style="margin:0"><i class="fas fa-circle-info"></i> Este dominio sirve una aplicación <?= e(hp_hosting_meta($s["app"]["type"])[0]) ?>; PHP no se usa mientras la aplicación esté activa.</p>
					<?php } ?>
					<div class="hp-field">
						<label>Manejador PHP-FPM</label>
						<select class="hp-select" x-model="backend">
							<?php foreach ($backends as $b) { ?>
								<option value="<?= e($b) ?>"><?= e(preg_match('/^PHP-(\d+)_(\d+)$/', $b, $m) ? "PHP {$m[1]}.{$m[2]}" : ($b === "default" ? "Predeterminado del servidor" : ($b === "no-php" ? "Sin PHP" : $b))) ?></option>
							<?php } ?>
						</select>
						<p class="hp-hint">Cada versión tiene su propio pool PHP-FPM aislado para este usuario.</p>
					</div>
					<div><button type="button" class="hp-btn hp-btn-primary" x-on:click="setPhp()"><i class="fas fa-check"></i>Aplicar versión</button></div>
				</div>
			</div>
			<div class="hp-card">
				<div class="hp-card-head"><h2 class="hp-card-title"><i class="fas fa-file-code"></i>Plantilla del servidor web</h2></div>
				<div class="hp-card-body hp-form">
					<div class="hp-field">
						<label>Plantilla nginx</label>
						<select class="hp-select" x-model="tpl" <?= $s["app"] || $apache["enabled"] ? "disabled" : "" ?>>
							<?php if ($s["app"] || $apache["enabled"]) { ?><option value="<?= e($s["tpl"]) ?>"><?= e($s["tpl"]) ?> (gestionada por iaachz-panel)</option><?php } ?>
							<?php foreach ($templates as $tp) { ?><option value="<?= e($tp) ?>"><?= e($tp) ?></option><?php } ?>
						</select>
						<p class="hp-hint">Optimiza nginx para WordPress, Laravel, Moodle, etc. <?= $s["app"] ? "Con una aplicación activa se usa la plantilla <code>hp-app</code>." : ($apache["enabled"] ? "En modo nginx + Apache las reglas las pone tu <code>.htaccess</code> (plantilla <code>hp-apache</code>)." : "") ?></p>
					</div>
					<div><button type="button" class="hp-btn hp-btn-primary" x-on:click="setTpl()" <?= $s["app"] || $apache["enabled"] ? "disabled" : "" ?>><i class="fas fa-check"></i>Aplicar plantilla</button></div>
				</div>
			</div>
		</div>
		<div class="hp-card hp-mt">
			<div class="hp-card-body hp-between hp-wrap">
				<div><b>Alias, raíz de documentos, caché FastCGI, estadísticas, FTP y redirecciones</b><div class="hp-muted hp-small">Disponibles en la configuración avanzada del dominio.</div></div>
				<a class="hp-btn" href="<?= $native("/edit/web/?" . $qs()) ?>"><i class="fas fa-pen"></i>Configuración avanzada</a>
			</div>
		</div>
	</div>

	<!-- ===================================================== APLICACIÓN -->
	<div x-show="tab === 'app'" x-cloak>
		<?php include __DIR__ . "/app_form.php"; ?>
	</div>

	<!-- ===================================================== SSL -->
	<div x-show="tab === 'ssl'" x-cloak>
		<div class="hp-card">
			<div class="hp-card-head"><h2 class="hp-card-title"><i class="fas fa-lock"></i>Certificado SSL/TLS</h2></div>
			<div class="hp-card-body">
				<div class="hp-flex hp-wrap" style="gap:20px;align-items:flex-start">
					<div class="hp-stat-icon <?= $s["ssl"] ? "hp-tint-green" : "hp-tint-orange" ?>" style="width:64px;height:64px;font-size:1.6rem"><i class="fas <?= $s["ssl"] ? "fa-lock" : "fa-lock-open" ?>"></i></div>
					<div style="flex:1;min-width:240px">
						<?php if ($s["ssl"]) { ?>
							<h3 style="margin:0 0 4px;color:var(--hp-text)">Protegido con HTTPS</h3>
							<p class="hp-muted" style="margin:0">
								<?= e($s["cert"]["issuer"] ?? "Certificado propio") ?><?= !empty($s["cert"]) ? " · vence el " . date("d/m/Y", $s["cert"]["expires"]) . " (" . (int) $s["cert"]["days"] . " días)" : "" ?>
								<?= $s["letsencrypt"] ? " · se renueva automáticamente" : "" ?>
							</p>
						<?php } else { ?>
							<h3 style="margin:0 0 4px;color:var(--hp-text)">Sin certificado</h3>
							<p class="hp-muted" style="margin:0">Emite un certificado gratuito de Let's Encrypt. El dominio debe apuntar a este servidor.</p>
						<?php } ?>
						<div class="hp-btn-group hp-mt">
							<button type="button" class="hp-btn hp-btn-primary" x-on:click="issueSsl()"><i class="fas fa-certificate"></i><?= $s["ssl"] ? "Reemitir Let's Encrypt" : "Emitir Let's Encrypt gratis" ?></button>
							<?php if ($s["letsencrypt"]) { ?><button type="button" class="hp-btn hp-btn-danger" x-on:click="sslOff()"><i class="fas fa-xmark"></i>Quitar certificado</button><?php } ?>
							<a class="hp-btn" href="<?= $native("/edit/web/?" . $qs()) ?>#ssl"><i class="fas fa-upload"></i>Subir certificado propio</a>
						</div>
					</div>
				</div>
			</div>
		</div>
		<?php if ($s["ssl"]) { ?>
			<div class="hp-card">
				<div class="hp-card-head"><h2 class="hp-card-title"><i class="fas fa-shield"></i>Seguridad HTTPS</h2></div>
				<div class="hp-card-body hp-form">
					<label class="hp-check"><input type="checkbox" :checked="force" x-on:change="toggleForce($event.target.checked)"><span><b>Redirigir HTTP → HTTPS (301)</b><small>Todos los visitantes usan la versión segura.</small></span></label>
					<label class="hp-check"><input type="checkbox" :checked="hsts" x-on:change="toggleHsts($event.target.checked)"><span><b>HSTS</b><small>El navegador recordará usar siempre HTTPS (recomendado en producción).</small></span></label>
				</div>
			</div>
		<?php } ?>
	</div>

	<!-- ===================================================== GIT -->
	<div x-show="tab === 'git'" x-cloak>
		<?php include __DIR__ . "/git_form.php"; ?>
	</div>

	<?php if ($s["cms"] === "WordPress") { ?>
	<!-- ===================================================== WORDPRESS -->
	<div x-show="tab === 'wp'" x-cloak x-init="$watch('tab', v => v === 'wp' && !wpInfo && loadWp())">
		<div class="hp-card">
			<div class="hp-card-head">
				<h2 class="hp-card-title"><i class="fas fa-pen-nib"></i>WordPress <?= e($s["wp_version"] ?? "") ?></h2>
				<div class="hp-btn-group">
					<a class="hp-btn hp-btn-sm" href="<?= e($url) ?>wp-admin/" target="_blank" rel="noopener"><i class="fas fa-right-to-bracket"></i>Escritorio</a>
					<button type="button" class="hp-btn hp-btn-sm hp-btn-primary" x-on:click="wpUpdate()"><i class="fas fa-arrows-rotate"></i>Actualizar todo</button>
				</div>
			</div>
			<div class="hp-card-body hp-flush">
				<template x-if="!wpInfo"><div class="hp-empty"><i class="fas fa-circle-notch fa-spin"></i> Consultando WordPress…</div></template>
				<template x-if="wpInfo && wpInfo.error"><p class="inline-alert inline-alert-danger" style="margin:16px" x-text="wpInfo.error"></p></template>
				<template x-if="wpInfo && !wpInfo.error">
					<div>
						<div class="hp-card-body" style="border-bottom:1px solid var(--hp-border)">
							<span x-show="wpInfo.core_update && wpInfo.core_update.length" class="hp-badge hp-badge-warn"><i class="fas fa-circle"></i><span x-text="'Nueva versión disponible: ' + (wpInfo.core_update[0] || {}).version"></span></span>
							<span x-show="!wpInfo.core_update || !wpInfo.core_update.length" class="hp-badge hp-badge-ok"><i class="fas fa-circle"></i>Núcleo al día</span>
						</div>
						<table class="hp-table">
							<thead><tr><th>Plugin / tema</th><th>Estado</th><th>Versión</th><th>Actualización</th></tr></thead>
							<tbody>
								<template x-for="p in (wpInfo.plugins || []).map(x => Object.assign({kind: 'Plugin'}, x)).concat((wpInfo.themes || []).map(x => Object.assign({kind: 'Tema'}, x)))">
									<tr>
										<td><b x-text="p.name"></b> <span class="hp-muted hp-small" x-text="p.kind"></span></td>
										<td><span class="hp-badge" :class="['active','active-network'].includes(p.status) ? 'hp-badge-ok' : 'hp-badge-muted'" x-text="p.status"></span></td>
										<td x-text="p.version"></td>
										<td><span x-show="p.update === 'available'" class="hp-badge hp-badge-warn">Disponible</span><span x-show="p.update !== 'available'" class="hp-muted">—</span></td>
									</tr>
								</template>
							</tbody>
						</table>
					</div>
				</template>
			</div>
		</div>
	</div>
	<?php } ?>
	<?php } ?>

	<!-- ===================================================== PELIGRO -->
	<div x-show="tab === 'danger'" x-cloak>
		<div class="hp-card">
			<div class="hp-card-body hp-between hp-wrap">
				<div><b><?= $s["suspended"] ? "Reactivar el sitio" : "Suspender el sitio" ?></b><div class="hp-muted hp-small">Un sitio suspendido muestra una página de "suspendido" y no ejecuta PHP ni aplicaciones.</div></div>
				<button type="button" class="hp-btn" x-on:click="suspend(<?= $s["suspended"] ? "false" : "true" ?>)"><i class="fas <?= $s["suspended"] ? "fa-play" : "fa-pause" ?>"></i><?= $s["suspended"] ? "Reactivar" : "Suspender" ?></button>
			</div>
		</div>
		<div class="hp-card" style="border-color:#f1c4c4">
			<div class="hp-card-head"><h2 class="hp-card-title" style="color:var(--hp-danger)"><i class="fas fa-trash" style="color:var(--hp-danger)"></i>Eliminar dominio</h2></div>
			<div class="hp-card-body hp-form">
				<p class="hp-muted" style="margin:0">Se borran el sitio web y sus archivos de <code>/home/<?= e($s_user) ?>/web/<?= e($d) ?></code>. Esta acción no se puede deshacer; haz antes una copia de seguridad si la necesitas.</p>
				<?php if ($s["dns"]) { ?><label class="hp-check"><input type="checkbox" x-model="delDns"><span><b>Eliminar también la zona DNS</b></span></label><?php } ?>
				<?php if ($s["mail"]) { ?><label class="hp-check"><input type="checkbox" x-model="delMail"><span><b>Eliminar también el correo y sus <?= (int) $s["mail_accounts"] ?> buzones</b></span></label><?php } ?>
				<div class="hp-field" style="max-width:360px">
					<label>Escribe <code><?= e($d) ?></code> para confirmar</label>
					<input class="hp-input" x-model="confirmName" autocomplete="off">
				</div>
				<div><button type="button" class="hp-btn hp-btn-danger" :disabled="confirmName !== domain" x-on:click="destroy()"><i class="fas fa-trash"></i>Eliminar definitivamente</button></div>
			</div>
		</div>
	</div>
</div>

<?php if ($hasWeb) { include __DIR__ . "/logs_modal.php"; } ?>
<script>
function hpSite(c) {
	return {
		...c,
		tab: (location.hash || '#overview').slice(1),
		dnsInfo: null,
		wpInfo: null,
		tr: null,
		trRange: '24h',
		delDns: true,
		delMail: false,
		confirmName: '',
		init() {
			window.addEventListener('hashchange', () => (this.tab = (location.hash || '#overview').slice(1)));
			HP.api('dns-check', { domain: this.domain }).then((r) => (this.dnsInfo = r)).catch(() => {});
			if (this.$refs.dtraffic) this.loadTraffic();
		},
		async loadTraffic() {
			try {
				this.tr = await HP.api('traffic-domain', { user: this.user, domain: this.domain, range: this.trRange });
				const s = this.tr.series;
				const labels = s.ts.map((t) => HP.timeLabel(t, this.trRange));
				if (window.hpDomainChart) window.hpDomainChart.destroy();
				const per = s.bucket >= 3600 ? 'hora' : s.bucket > 60 ? Math.round(s.bucket / 60) + ' min' : 'min';
				window.hpDomainChart = Alpine.raw(await HP.lineChart(this.$refs.dtraffic, labels, [
					{ label: 'Peticiones por ' + per, data: s.req, borderColor: '#1f6fd6' },
					{ label: 'Errores 4xx', data: s.s4, borderColor: '#e08a00', fill: false },
					{ label: 'Errores 5xx', data: s.s5, borderColor: '#d23f3f', fill: false },
				], { fmt: (v) => Math.round(v) }));
			} catch (e) {
				this.tr = null;
			}
		},
		logs(f) {
			const range = { '1h': '1h', '7d': '7d' }[this.trRange] || '24h';
			window.dispatchEvent(new CustomEvent('hp-logs', { detail: Object.assign({ domain: this.domain, range }, f) }));
		},
		go(t) {
			history.replaceState(null, '', '#' + t);
			this.tab = t;
		},
		async call(action, data, msg) {
			try {
				await HP.api(action, Object.assign({ user: this.user, domain: this.domain }, data || {}));
				HP.toast(msg || 'Hecho');
				return true;
			} catch (e) {
				HP.toast(e.message, 'error');
				return false;
			}
		},
		async setPhp() {
			if (await this.call('php-version', { backend: this.backend }, 'Versión de PHP aplicada')) setTimeout(() => location.reload(), 600);
		},
		async applyWebserver() {
			try {
				if (this.webserver === 'apache') {
					await HP.runJob('apache-enable', { user: this.user, domain: this.domain, static: this.apacheStatic, ext: this.apacheExt }, 'nginx + Apache para ' + this.domain);
				} else {
					await HP.api('apache-disable', { user: this.user, domain: this.domain });
					HP.toast('El dominio vuelve a usar solo nginx');
				}
				setTimeout(() => location.reload(), 700);
			} catch (e) {
				if (!/ya usa solo nginx/.test(e.message)) HP.toast(e.message, 'error');
				else HP.toast('El dominio ya usa solo nginx');
			}
		},
		async setTpl() {
			if (await this.call('web-tpl', { tpl: this.tpl }, 'Plantilla aplicada')) setTimeout(() => location.reload(), 600);
		},
		async issueSsl() {
			try {
				await HP.runJob('ssl-issue', { user: this.user, domain: this.domain }, 'Certificado Let\'s Encrypt para ' + this.domain);
				setTimeout(() => location.reload(), 800);
			} catch (e) {
				HP.toast(e.message, 'error');
			}
		},
		async sslOff() {
			if (!(await HP.confirm('¿Quitar el certificado SSL de ' + this.domain + '? El sitio quedará solo en HTTP.', { danger: true, ok: 'Quitar' }))) return;
			if (await this.call('ssl-off', {}, 'Certificado eliminado')) setTimeout(() => location.reload(), 600);
		},
		async toggleForce(on) {
			this.force = on;
			await this.call('force-ssl', { on }, on ? 'HTTPS forzado' : 'Redirección desactivada');
		},
		async toggleHsts(on) {
			this.hsts = on;
			await this.call('hsts', { on }, on ? 'HSTS activado' : 'HSTS desactivado');
		},
		async loadWp() {
			try {
				this.wpInfo = await HP.api('wp-info', { user: this.user, domain: this.domain });
			} catch (e) {
				this.wpInfo = { error: e.message };
			}
		},
		async wpUpdate() {
			if (!(await HP.confirm('Se actualizarán el núcleo, los plugins y los temas de WordPress. Se recomienda tener una copia de seguridad reciente.', { ok: 'Actualizar' }))) return;
			try {
				await HP.runJob('wp-update', { user: this.user, domain: this.domain }, 'Actualizando WordPress');
				this.wpInfo = null;
				this.loadWp();
			} catch (e) {
				HP.toast(e.message, 'error');
			}
		},
		async suspend(on) {
			if (await this.call('domain-suspend', { on }, on ? 'Sitio suspendido' : 'Sitio reactivado')) setTimeout(() => location.reload(), 600);
		},
		async destroy() {
			if (await this.call('domain-delete', { dns: this.delDns, mail: this.delMail }, 'Dominio eliminado')) setTimeout(() => (location.href = '/pro/sites/'), 600);
		},
	};
}
</script>
