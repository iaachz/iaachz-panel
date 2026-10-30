<?php
$isSub = $wiz["mode"] === "subscription";
hp_page_header($isSub ? "Nueva suscripción" : "Agregar dominio",
	$isSub ? "Crea un cliente con su plan, su dominio principal y todos los servicios en un solo paso." : "Crea el sitio, su DNS, el correo, la base de datos y el certificado SSL en un solo paso.",
	["Sitios web y dominios" => "/pro/sites/"]);
?>
<div x-data="hpWizard(<?= e(json_encode($wiz)) ?>)" class="hp-grid hp-grid-main">
	<div>
		<div class="hp-wizard-steps">
			<template x-for="(name, i) in steps" :key="i">
				<div class="hp-wizard-step" :class="{active: step === i, done: step > i}"><span class="hp-wnum" x-text="step > i ? '✓' : i + 1"></span><span x-text="name"></span></div>
			</template>
		</div>

		<!-- 1. Dominio y suscripción -->
		<div class="hp-card" x-show="step === 0">
			<div class="hp-card-head"><h2 class="hp-card-title"><i class="fas fa-earth-americas"></i>Dominio</h2></div>
			<div class="hp-card-body hp-form">
				<div class="hp-field">
					<label>Nombre de dominio</label>
					<input class="hp-input" style="font-size:1.05rem" x-model.trim="domain" x-on:input.debounce.700ms="checkDns()" placeholder="ejemplo.com o tienda.ejemplo.com" autofocus>
					<p class="hp-hint" x-show="dns">
						<template x-if="dns && dns.points_here"><span style="color:var(--hp-ok)"><i class="fas fa-circle-check"></i> El dominio ya apunta a este servidor: el SSL se emitirá al instante.</span></template>
						<template x-if="dns && !dns.points_here"><span style="color:var(--hp-warn)"><i class="fas fa-triangle-exclamation"></i> <span x-text="dns.a.length ? 'Ahora apunta a ' + dns.a.join(', ') : 'Aún no tiene registro A'"></span>. Apúntalo a <b x-text="dns.server_ips.join(', ')"></b> en tu proveedor de DNS para poder emitir el SSL.</span></template>
					</p>
				</div>
				<label class="hp-check"><input type="checkbox" x-model="www"><span><b>Incluir www.<span x-text="domain || 'dominio'"></span></b><small>Recomendado para dominios principales.</small></span></label>

				<template x-if="isAdmin && mode !== 'subscription'">
					<div class="hp-field">
						<label>Suscripción (propietario)</label>
						<select class="hp-select" x-model="user">
							<template x-for="u in users" :key="u"><option :value="u" x-text="u" :selected="u === user"></option></template>
						</select>
						<p class="hp-hint">¿Es un cliente nuevo? <a href="/pro/new/?mode=subscription">Crea una suscripción</a>.</p>
					</div>
				</template>

				<template x-if="mode === 'subscription'">
					<div class="hp-form">
						<div class="hp-form-row">
							<div class="hp-field"><label>Usuario del cliente</label><input class="hp-input" x-model.trim="nu.username" placeholder="cliente1" autocomplete="off"><p class="hp-hint">Minúsculas y números. Será también el usuario SSH/FTP.</p></div>
							<div class="hp-field"><label>Nombre o empresa</label><input class="hp-input" x-model="nu.name" placeholder="Empresa S.A.C."></div>
						</div>
						<div class="hp-form-row">
							<div class="hp-field"><label>Correo de contacto</label><input class="hp-input" type="email" x-model.trim="nu.email" placeholder="contacto@cliente.com"></div>
							<div class="hp-field">
								<label>Plan de servicio</label>
								<select class="hp-select" x-model="nu.package">
									<template x-for="p in packages" :key="p"><option :value="p" x-text="p" :selected="p === nu.package"></option></template>
								</select>
								<p class="hp-hint"><a href="/list/package/">Gestionar planes</a></p>
							</div>
						</div>
						<div class="hp-field">
							<label>Contraseña del panel</label>
							<div class="hp-input-group"><input class="hp-input hp-mono" x-model="nu.password"><button type="button" class="hp-btn" x-on:click="nu.password = HP.password()"><i class="fas fa-dice"></i>Generar</button></div>
						</div>
					</div>
				</template>
			</div>
		</div>

		<!-- 2. Hosting -->
		<div class="hp-card" x-show="step === 1" x-cloak>
			<div class="hp-card-head"><h2 class="hp-card-title"><i class="fas fa-server"></i>¿Qué vas a alojar?</h2></div>
			<div class="hp-card-body hp-form">
				<div class="hp-choices">
					<template x-for="h in hostingTypes" :key="h.id">
						<label class="hp-choice" :class="hosting === h.id && 'active'" x-show="!h.admin || isAdmin" x-on:click="hosting = h.id">
							<span class="hp-chip hp-chip-sm" :class="'hp-chip-' + h.chip" x-html="h.icon"></span><b x-text="h.name"></b><small x-text="h.desc"></small>
						</label>
					</template>
				</div>

				<template x-if="['php','wordpress','static'].includes(hosting)">
					<div class="hp-field">
						<label>Servidor web</label>
						<div class="hp-segmented">
							<button type="button" :class="webserver === 'nginx' && 'active'" x-on:click="webserver = 'nginx'">Solo nginx (más rápido)</button>
							<button type="button" :class="webserver === 'apache' && 'active'" x-on:click="webserver = 'apache'">nginx + Apache (.htaccess)</button>
						</div>
						<p class="hp-hint" x-text="webserver === 'apache' ? 'nginx atiende las visitas y el SSL, sirve los estáticos y pasa el resto a Apache, que respeta .htaccess (como el modo proxy de Plesk).' : 'nginx ejecuta PHP-FPM directamente. Si tu sitio depende de .htaccess elige nginx + Apache.'"></p>
					</div>
				</template>

				<template x-if="['php','wordpress'].includes(hosting) && backends.length">
					<div class="hp-field" style="max-width:320px">
						<label>Versión de PHP</label>
						<select class="hp-select" x-model="php"><template x-for="b in backends" :key="b"><option :value="b" x-text="b.replace('PHP-', 'PHP ').replace('_', '.')" :selected="b === php"></option></template></select>
					</div>
				</template>

				<template x-if="hosting === 'wordpress'">
					<div class="hp-form">
						<div class="hp-form-row">
							<div class="hp-field"><label>Título del sitio</label><input class="hp-input" x-model="wp.title" :placeholder="domain"></div>
							<div class="hp-field"><label>Idioma</label><select class="hp-select" x-model="wp.locale"><option value="es_ES">Español</option><option value="es_PE">Español (Perú)</option><option value="es_MX">Español (México)</option><option value="en_US">English</option><option value="pt_BR">Português (Brasil)</option></select></div>
						</div>
						<div class="hp-form-row">
							<div class="hp-field"><label>Usuario administrador</label><input class="hp-input" x-model="wp.admin_user"></div>
							<div class="hp-field"><label>Correo del administrador</label><input class="hp-input" type="email" x-model="wp.admin_email" :placeholder="nu.email || 'admin@' + (domain || 'dominio')"></div>
						</div>
						<div class="hp-field"><label>Contraseña del administrador</label><div class="hp-input-group"><input class="hp-input hp-mono" x-model="wp.admin_password"><button type="button" class="hp-btn" x-on:click="wp.admin_password = HP.password()"><i class="fas fa-dice"></i>Generar</button></div></div>
					</div>
				</template>

				<template x-if="hosting === 'node'">
					<div class="hp-form">
						<template x-if="!Object.keys(runtimes.node.installed).length"><p class="inline-alert inline-alert-danger" style="margin:0"><i class="fas fa-circle-exclamation"></i> No hay Node.js instalado. <a href="/pro/apps/#runtimes">Instálalo en Entornos</a>.</p></template>
						<div class="hp-form-row">
							<div class="hp-field"><label>Versión de Node.js</label><select class="hp-select" x-model="app.version"><template x-for="(v, m) in runtimes.node.installed" :key="m"><option :value="m" x-text="'Node.js ' + m + ' (' + v + ')'" :selected="String(app.version) === m"></option></template></select></div>
							<div class="hp-field"><label>Archivo de inicio</label><input class="hp-input hp-mono" x-model="app.startup" placeholder="app.js"></div>
						</div>
						<p class="hp-hint" style="margin:0">Se crea un ejemplo funcionando en <code>~/web/<span x-text="domain || 'dominio'"></span>/app</code>. Después sube tu código o conecta Git.</p>
					</div>
				</template>

				<template x-if="hosting === 'python'">
					<div class="hp-form-row">
						<div class="hp-field"><label>Versión de Python</label><select class="hp-select" x-model="app.version"><template x-for="(v, k) in runtimes.python.installed" :key="k"><option :value="k" x-text="(k === 'system' ? 'Sistema' : 'Python ' + k) + ' (' + v.version + ')'" :selected="String(app.version) === k"></option></template></select></div>
						<div class="hp-field"><label>Servidor</label><select class="hp-select" x-model="app.server"><option value="gunicorn">Gunicorn (Django, Flask)</option><option value="uvicorn">Uvicorn (FastAPI)</option></select></div>
					</div>
				</template>

				<template x-if="hosting === 'docker'">
					<div class="hp-form-row">
						<div class="hp-field"><label>Imagen</label><input class="hp-input hp-mono" x-model="app.image" placeholder="nginx:alpine · ghost:5 · louislam/uptime-kuma"></div>
						<div class="hp-field"><label>Puerto del contenedor</label><input class="hp-input" type="number" x-model.number="app.container_port"></div>
					</div>
				</template>

				<template x-if="hosting === 'proxy'">
					<div class="hp-field"><label>Destino</label><input class="hp-input hp-mono" x-model="app.target" placeholder="http://127.0.0.1:8080"></div>
				</template>
			</div>
		</div>

		<!-- 3. Servicios -->
		<div class="hp-card" x-show="step === 2" x-cloak>
			<div class="hp-card-head"><h2 class="hp-card-title"><i class="fas fa-layer-group"></i>Servicios del dominio</h2></div>
			<div class="hp-card-body hp-form">
				<label class="hp-check" x-show="hosting !== 'none'"><input type="checkbox" x-model="ssl"><span><b>Certificado SSL gratuito (Let's Encrypt)</b><small x-text="dns && !dns.points_here ? 'El dominio aún no apunta aquí: se omitirá y podrás emitirlo luego desde SSL/TLS.' : 'Se emite y renueva automáticamente, con redirección a HTTPS.'"></small></span></label>
				<label class="hp-check"><input type="checkbox" x-model="dnsZone"><span><b>Zona DNS en este servidor</b><small>Útil si usarás estos nameservers. Si tu DNS está en otro proveedor, desmárcalo.</small></span></label>
				<label class="hp-check"><input type="checkbox" x-model="mail"><span><b>Correo electrónico</b><small>Buzones @<span x-text="domain || 'dominio'"></span> con webmail, antispam y DKIM.</small></span></label>
				<label class="hp-check" x-show="!['wordpress','none','proxy','static'].includes(hosting)"><input type="checkbox" x-model="db.create"><span><b>Base de datos MySQL/MariaDB</b><small>Se crea vacía con un usuario propio.</small></span></label>
				<template x-if="db.create && !['wordpress','none','proxy','static'].includes(hosting)">
					<div class="hp-form-row">
						<div class="hp-field"><label>Nombre</label><div class="hp-flex" style="gap:0"><span class="hp-addon" x-text="owner() + '_'"></span><input class="hp-input" x-model="db.name"></div></div>
						<div class="hp-field"><label>Usuario</label><div class="hp-flex" style="gap:0"><span class="hp-addon" x-text="owner() + '_'"></span><input class="hp-input" x-model="db.user"></div></div>
					</div>
				</template>
			</div>
		</div>

		<p class="inline-alert inline-alert-danger" x-show="error" x-cloak x-text="error"></p>

		<div class="hp-between hp-mt">
			<button type="button" class="hp-btn" x-show="step > 0" x-on:click="step--"><i class="fas fa-arrow-left"></i>Atrás</button>
			<span x-show="step === 0"></span>
			<button type="button" class="hp-btn hp-btn-primary" x-show="step < steps.length - 1" x-on:click="next()">Siguiente<i class="fas fa-arrow-right"></i></button>
			<button type="button" class="hp-btn hp-btn-primary" x-show="step === steps.length - 1" :class="busy && 'is-loading'" x-on:click="create()"><i class="fas fa-rocket"></i><span x-text="mode === 'subscription' ? 'Crear suscripción' : 'Crear dominio'"></span></button>
		</div>
	</div>

	<!-- Resumen -->
	<div>
		<div class="hp-card" style="position:sticky;top:80px">
			<div class="hp-card-head"><h2 class="hp-card-title"><i class="fas fa-clipboard-list"></i>Resumen</h2></div>
			<div class="hp-card-body">
				<dl class="hp-kv">
					<dt>Dominio</dt><dd><b x-text="domain || '—'"></b><span x-show="www && domain" class="hp-muted" x-text="' + www'"></span></dd>
					<dt>Suscripción</dt><dd x-text="mode === 'subscription' ? (nu.username || '—') + ' (nueva, plan ' + nu.package + ')' : user"></dd>
					<dt>Hosting</dt><dd x-text="(hostingTypes.find(h => h.id === hosting) || {}).name"></dd>
					<dt x-show="['php','wordpress','static'].includes(hosting)">Servidor web</dt><dd x-show="['php','wordpress','static'].includes(hosting)" x-text="webserver === 'apache' ? 'nginx + Apache' : 'Solo nginx'"></dd>
					<dt>SSL</dt><dd x-text="ssl && hosting !== 'none' ? 'Let\'s Encrypt' : 'No'"></dd>
					<dt>DNS</dt><dd x-text="dnsZone ? 'Zona local' : 'Externo'"></dd>
					<dt>Correo</dt><dd x-text="mail ? 'Sí' : 'No'"></dd>
					<dt>Base de datos</dt><dd x-text="hosting === 'wordpress' ? 'Automática para WordPress' : (db.create && !['none','proxy','static'].includes(hosting) ? owner() + '_' + db.name : 'No')"></dd>
				</dl>
			</div>
		</div>
	</div>

	<!-- Resultado -->
	<dialog class="hp-modal" x-ref="done">
		<div class="hp-modal-head"><h3><i class="fas fa-circle-check" style="color:var(--hp-ok)"></i> ¡Listo!</h3></div>
		<div class="hp-modal-body" x-show="result">
			<p style="margin-top:0">Se creó <b x-text="result && result.domain"></b><span x-show="result && result.created && result.created.length" x-text="' con: ' + (result ? result.created.join(', ') : '')"></span>.</p>
			<template x-if="result && result.warnings && result.warnings.length">
				<div class="inline-alert inline-alert-danger hp-mb" style="background:var(--hp-warn-50);color:var(--hp-warn)"><i class="fas fa-triangle-exclamation"></i><div><template x-for="w in result.warnings"><p style="margin:0 0 4px" x-text="w"></p></template></div></div>
			</template>
			<template x-if="result && (result.user_password || result.database || result.wordpress)">
				<div class="hp-card" style="box-shadow:none">
					<div class="hp-card-head"><h4 class="hp-card-title"><i class="fas fa-key"></i>Credenciales (guárdalas ahora)</h4></div>
					<div class="hp-card-body">
						<dl class="hp-kv">
							<template x-if="result.user_password"><dt>Panel</dt></template>
							<template x-if="result.user_password"><dd><span class="hp-secret" x-text="result.user"></span> / <span class="hp-secret" x-text="result.user_password"></span></dd></template>
							<template x-if="result.database"><dt>Base de datos</dt></template>
							<template x-if="result.database"><dd><span class="hp-secret" x-text="result.database.name"></span> · usuario <span class="hp-secret" x-text="result.database.user"></span><span x-show="result.database.password"> · clave <span class="hp-secret" x-text="result.database.password"></span></span></dd></template>
							<template x-if="result.wordpress"><dt>WordPress</dt></template>
							<template x-if="result.wordpress"><dd><a :href="result.wordpress.url" target="_blank" x-text="result.wordpress.url"></a><br><span class="hp-secret" x-text="result.wordpress.user"></span><span x-show="result.wordpress.password"> / <span class="hp-secret" x-text="result.wordpress.password"></span></span></dd></template>
						</dl>
					</div>
				</div>
			</template>
		</div>
		<div class="hp-modal-foot">
			<a class="hp-btn" href="/pro/sites/">Ir a sitios</a>
			<a class="hp-btn hp-btn-primary" :href="result ? '/pro/site/?user=' + encodeURIComponent(result.user) + '&domain=' + encodeURIComponent(result.domain) : '#'"><i class="fas fa-gear"></i>Gestionar dominio</a>
		</div>
	</dialog>
</div>

<script>
function hpWizard(c) {
	const nodes = Object.keys((c.runtimes.node || {}).installed || {});
	return {
		...c,
		runtimes: Object.assign({ node: { installed: {} }, python: { installed: { system: { version: '' } } }, docker: {} }, c.runtimes),
		steps: ['Dominio', 'Hosting', 'Servicios'],
		step: 0,
		domain: '',
		www: true,
		dns: null,
		hosting: c.hosting,
		php: c.backends[0] || '',
		webserver: 'nginx',
		ssl: true,
		dnsZone: false,
		mail: true,
		db: { create: false, name: 'db', user: 'db' },
		nu: { username: '', name: '', email: '', package: c.packages.includes('default') ? 'default' : c.packages[0] || 'default', password: HP.password() },
		wp: { title: '', locale: 'es_ES', admin_user: 'admin', admin_email: '', admin_password: HP.password() },
		app: { version: nodes[nodes.length - 1] || '', startup: 'app.js', server: 'gunicorn', image: '', container_port: 80, target: 'http://127.0.0.1:8080' },
		error: '',
		busy: false,
		result: null,
		hostingTypes: [
			{ id: 'php', name: 'Sitio web PHP', desc: 'HTML, PHP, Laravel, Moodle… con la versión de PHP que elijas.', chip: 'php', icon: 'PHP' },
			{ id: 'wordpress', name: 'WordPress', desc: 'Instalado y listo, con base de datos y administrador.', chip: 'wordpress', icon: 'WP' },
			{ id: 'node', name: 'Node.js', desc: 'Express, Next.js, NestJS… como servicio con reinicio automático.', chip: 'node', icon: 'JS' },
			{ id: 'python', name: 'Python', desc: 'Django, Flask o FastAPI con su entorno virtual.', chip: 'python', icon: 'PY' },
			{ id: 'docker', name: 'Contenedor Docker', desc: 'Cualquier imagen publicada en el dominio con SSL.', chip: 'docker', icon: '<i class="fas fa-box"></i>', admin: true },
			{ id: 'proxy', name: 'Proxy inverso', desc: 'Reenvía el dominio a otro puerto o servidor.', chip: 'proxy', icon: '<i class="fas fa-right-left"></i>' },
			{ id: 'static', name: 'Sitio estático', desc: 'Solo HTML/CSS/JS, sin PHP (más rápido y seguro).', chip: 'static', icon: '<i class="fas fa-file-code"></i>' },
			{ id: 'none', name: 'Solo DNS / correo', desc: 'Sin sitio web: para correo o zona DNS.', chip: 'none', icon: '<i class="fas fa-at"></i>' },
		],
		init() {
			if (this.mode === 'subscription') this.steps = ['Cliente y dominio', 'Hosting', 'Servicios'];
			if (this.hosting === 'python') this.app.version = 'system';
			this.$watch('hosting', (h) => {
				if (h === 'python' && !(String(this.app.version) in this.runtimes.python.installed)) this.app.version = 'system';
				if (h === 'node') this.app.version = nodes[nodes.length - 1] || '';
			});
		},
		owner() {
			return this.mode === 'subscription' ? this.nu.username || 'usuario' : this.user;
		},
		async checkDns() {
			this.dns = null;
			if (!/^[a-z0-9.-]+\.[a-z]{2,}$/i.test(this.domain)) return;
			try {
				this.dns = await HP.api('dns-check', { domain: this.domain });
				if (!this.dns.points_here) this.ssl = this.ssl && true;
			} catch (e) {}
		},
		validate() {
			this.error = '';
			if (this.step === 0) {
				if (!/^([a-z0-9]([a-z0-9-]*[a-z0-9])?\.)+[a-z]{2,}$/i.test(this.domain)) return (this.error = 'Escribe un dominio válido, por ejemplo midominio.com');
				if (this.mode === 'subscription') {
					if (!/^[a-z][a-z0-9_-]{1,27}$/.test(this.nu.username)) return (this.error = 'Usuario no válido: minúsculas y números, empezando por letra.');
					if (!/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(this.nu.email)) return (this.error = 'Escribe un correo de contacto válido.');
					if (!/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d).{8,}$/.test(this.nu.password)) return (this.error = 'La contraseña necesita 8+ caracteres con mayúsculas, minúsculas y números.');
				}
			}
			if (this.step === 1) {
				if (this.hosting === 'node' && !this.app.version) return (this.error = 'Instala primero una versión de Node.js.');
				if (this.hosting === 'docker' && !this.app.image) return (this.error = 'Indica la imagen de Docker.');
				if (this.hosting === 'proxy' && !/^https?:\/\//.test(this.app.target)) return (this.error = 'Indica un destino http(s)://');
			}
			return '';
		},
		next() {
			if (!this.validate()) this.step++;
		},
		payload() {
			const p = { domain: this.domain.toLowerCase(), www: this.www, hosting: this.hosting, php: this.php, webserver: this.webserver, ssl: this.ssl, force_ssl: true, dns: this.dnsZone, mail: this.mail, user: this.user };
			if (this.mode === 'subscription') p.new_user = this.nu;
			if (this.db.create && !['wordpress', 'none', 'proxy', 'static'].includes(this.hosting)) p.db = { name: this.db.name, user: this.db.user };
			if (this.hosting === 'wordpress') p.wordpress = Object.assign({}, this.wp, { title: this.wp.title || this.domain, admin_email: this.wp.admin_email || this.nu.email || '' });
			if (this.hosting === 'node') p.app = { version: this.app.version, root: 'app', start_mode: 'file', startup: this.app.startup || 'app.js' };
			if (this.hosting === 'python') p.app = { version: this.app.version, root: 'app', server: this.app.server, module: 'app:app' };
			if (this.hosting === 'docker') p.app = { image: this.app.image, container_port: this.app.container_port };
			if (this.hosting === 'proxy') p.app = { target: this.app.target };
			return p;
		},
		async create() {
			this.step = 0;
			if (this.validate()) return;
			this.step = 1;
			if (this.validate()) return;
			this.step = 2;
			this.busy = true;
			try {
				const r = await HP.runJob('site-create', { site: this.payload() }, 'Creando ' + this.domain);
				r.dialog.close();
				this.result = r.result || { domain: this.domain, user: this.owner(), created: [] };
				if (this.result.wordpress && !this.result.wordpress.password) this.result.wordpress.password = this.wp.admin_password;
				if (this.mode === 'subscription' && !this.result.user_password) this.result.user_password = this.nu.password;
				this.$refs.done.showModal();
			} catch (e) {
				this.error = e.message;
			}
			this.busy = false;
		},
	};
}
</script>
