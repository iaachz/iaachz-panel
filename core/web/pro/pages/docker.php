<?php
$sub = $status["installed"] ? "Docker " . e($status["version"] ?? "") . " · Compose " . e($status["compose"] ?? "") . ($status["active"] ? "" : " · <b style='color:var(--hp-danger)'>servicio detenido</b>") : "Docker no está instalado";
hp_page_header("Docker", $sub, [], $status["installed"] ? '<button type="button" class="hp-btn hp-btn-primary" x-data x-on:click="$dispatch(\'hp-docker-run\')"><i class="fas fa-play"></i>Ejecutar contenedor</button>' : "");
?>
<?php if (!$status["installed"]) { ?>
	<div class="hp-card" x-data>
		<div class="hp-empty">
			<span class="hp-chip hp-chip-docker hp-chip-lg" style="margin-bottom:14px"><i class="fas fa-box"></i></span>
			<h3>Instala Docker en un clic</h3>
			<p>Se instalan <code>docker.io</code> y <code>docker compose</code> desde los repositorios de Ubuntu, con rotación de registros.<br>Después podrás publicar cualquier imagen en un dominio con SSL, como en Plesk.</p>
			<button type="button" class="hp-btn hp-btn-primary" x-on:click="HP.runJob('docker-install', {}, 'Instalando Docker').then(() => location.reload()).catch(e => HP.toast(e.message, 'error'))"><i class="fas fa-download"></i>Instalar Docker</button>
		</div>
	</div>
<?php } else { ?>
<div x-data="hpDocker" x-on:hp-docker-run.window="openRun()">
	<div class="hp-tabs">
		<button class="hp-tab" :class="tab === 'containers' && 'active'" x-on:click="tab = 'containers'"><i class="fas fa-box"></i>Contenedores <span class="hp-badge hp-badge-muted" x-text="containers ? containers.length : '…'"></span></button>
		<button class="hp-tab" :class="tab === 'images' && 'active'" x-on:click="tab = 'images'; loadImages()"><i class="fas fa-layer-group"></i>Imágenes</button>
		<button class="hp-tab" :class="tab === 'compose' && 'active'" x-on:click="tab = 'compose'; loadStacks()"><i class="fas fa-diagram-project"></i>Pilas Compose</button>
	</div>

	<!-- Contenedores -->
	<div x-show="tab === 'containers'">
		<div class="hp-card">
			<div class="hp-card-head">
				<h2 class="hp-card-title"><i class="fas fa-box"></i>Contenedores</h2>
				<button type="button" class="hp-btn hp-btn-sm" x-on:click="load()"><i class="fas fa-rotate"></i>Actualizar</button>
			</div>
			<div class="hp-card-body hp-flush hp-table-wrap">
				<template x-if="!containers"><div class="hp-empty"><i class="fas fa-circle-notch fa-spin"></i></div></template>
				<template x-if="containers && !containers.length"><div class="hp-empty"><i class="fas fa-box-open hp-empty-icon"></i><h3>Sin contenedores</h3><p>Ejecuta uno o publícalo en un dominio desde Aplicaciones.</p></div></template>
				<table class="hp-table" x-show="containers && containers.length">
					<thead><tr><th>Nombre</th><th>Imagen</th><th>Estado</th><th>Puertos</th><th class="hp-right">CPU</th><th class="hp-right">Memoria</th><th class="hp-right">Acciones</th></tr></thead>
					<tbody>
						<template x-for="c in containers" :key="c.ID">
							<tr>
								<td>
									<b x-text="c.Names"></b>
									<div class="hp-small">
										<template x-if="c.hp_domain"><a :href="'/pro/site/?user=' + c.hp_user + '&domain=' + c.hp_domain + '#app'" x-text="'↳ ' + c.hp_domain"></a></template>
										<template x-if="c.compose"><span class="hp-muted" x-text="'pila ' + c.compose"></span></template>
									</div>
								</td>
								<td class="hp-mono" x-text="c.Image"></td>
								<td><span class="hp-badge" :class="c.State === 'running' ? 'hp-badge-ok' : (c.State === 'exited' ? 'hp-badge-danger' : 'hp-badge-muted')"><i class="fas fa-circle"></i><span x-text="c.Status"></span></span></td>
								<td class="hp-mono hp-small" x-text="c.Ports || '—'"></td>
								<td class="hp-right" x-text="c.CPUPerc || '—'"></td>
								<td class="hp-right hp-nowrap hp-small" x-text="c.MemUsage || '—'"></td>
								<td class="hp-right hp-nowrap">
									<button type="button" class="hp-btn hp-btn-xs" x-show="c.State !== 'running'" title="Iniciar" x-on:click="act(c, 'start')"><i class="fas fa-play"></i></button>
									<button type="button" class="hp-btn hp-btn-xs" x-show="c.State === 'running'" title="Detener" x-on:click="act(c, 'stop')"><i class="fas fa-stop"></i></button>
									<button type="button" class="hp-btn hp-btn-xs" title="Reiniciar" x-on:click="act(c, 'restart')"><i class="fas fa-rotate"></i></button>
									<button type="button" class="hp-btn hp-btn-xs" title="Registros" x-on:click="logs(c)"><i class="fas fa-file-lines"></i></button>
									<button type="button" class="hp-btn hp-btn-xs hp-btn-danger" title="Eliminar" x-on:click="act(c, 'remove')"><i class="fas fa-trash"></i></button>
								</td>
							</tr>
						</template>
					</tbody>
				</table>
			</div>
		</div>
	</div>

	<!-- Imágenes -->
	<div x-show="tab === 'images'" x-cloak>
		<div class="hp-card">
			<div class="hp-card-head">
				<h2 class="hp-card-title"><i class="fas fa-layer-group"></i>Imágenes locales</h2>
				<div class="hp-input-group" style="max-width:420px">
					<input class="hp-input hp-mono" x-model="pullName" placeholder="nginx:alpine · postgres:16 · redis" x-on:keydown.enter="pull()">
					<button type="button" class="hp-btn hp-btn-primary" x-on:click="pull()"><i class="fas fa-download"></i>Descargar</button>
				</div>
			</div>
			<div class="hp-card-body hp-flush hp-table-wrap">
				<template x-if="!images"><div class="hp-empty"><i class="fas fa-circle-notch fa-spin"></i></div></template>
				<table class="hp-table" x-show="images && images.length">
					<thead><tr><th>Repositorio</th><th>Etiqueta</th><th>ID</th><th>Creada</th><th class="hp-right">Tamaño</th><th></th></tr></thead>
					<tbody>
						<template x-for="i in images" :key="i.ID + i.Tag">
							<tr>
								<td><b x-text="i.Repository"></b></td>
								<td><span class="hp-tag" x-text="i.Tag"></span></td>
								<td class="hp-mono hp-small" x-text="i.ID"></td>
								<td class="hp-small" x-text="i.CreatedSince"></td>
								<td class="hp-right" x-text="i.Size"></td>
								<td class="hp-right hp-nowrap">
									<button type="button" class="hp-btn hp-btn-xs" title="Ejecutar" x-on:click="openRun(i.Repository + ':' + i.Tag)"><i class="fas fa-play"></i></button>
									<button type="button" class="hp-btn hp-btn-xs hp-btn-danger" title="Eliminar" x-on:click="rmi(i)"><i class="fas fa-trash"></i></button>
								</td>
							</tr>
						</template>
					</tbody>
				</table>
				<template x-if="images && !images.length"><div class="hp-empty">No hay imágenes descargadas.</div></template>
			</div>
		</div>
	</div>

	<!-- Compose -->
	<div x-show="tab === 'compose'" x-cloak>
		<div class="hp-grid hp-grid-main">
			<div class="hp-card">
				<div class="hp-card-head">
					<h2 class="hp-card-title"><i class="fas fa-file-code"></i><span x-text="stack.name ? 'Pila: ' + stack.name : 'Nueva pila'"></span></h2>
					<button type="button" class="hp-btn hp-btn-sm" x-on:click="newStack()"><i class="fas fa-plus"></i>Nueva</button>
				</div>
				<div class="hp-card-body hp-form">
					<div class="hp-field" style="max-width:320px"><label>Nombre</label><input class="hp-input" x-model="stack.name" :readonly="stack.saved" placeholder="mi-proyecto"></div>
					<div class="hp-field"><label>compose.yaml</label><textarea class="hp-textarea" style="min-height:300px" x-model="stack.yaml" spellcheck="false"></textarea></div>
					<div class="hp-field"><label>Variables (.env)</label><textarea class="hp-textarea" style="min-height:80px" x-model="stack.env" placeholder="POSTGRES_PASSWORD=..." spellcheck="false"></textarea></div>
					<p class="hp-hint" style="margin:0">Consejo: publica los puertos como <code>"127.0.0.1:8080:80"</code> y crea un dominio con hosting <b>Proxy</b> hacia <code>http://127.0.0.1:8080</code> para tener SSL.</p>
					<div class="hp-btn-group">
						<button type="button" class="hp-btn hp-btn-primary" x-on:click="saveStack(true)"><i class="fas fa-rocket"></i>Guardar y desplegar</button>
						<button type="button" class="hp-btn" x-on:click="saveStack(false)"><i class="fas fa-floppy-disk"></i>Solo guardar</button>
					</div>
				</div>
			</div>
			<div class="hp-card">
				<div class="hp-card-head"><h2 class="hp-card-title"><i class="fas fa-diagram-project"></i>Pilas</h2></div>
				<div class="hp-card-body hp-flush">
					<template x-if="stacks && !stacks.length"><div class="hp-empty">Sin pilas todavía.</div></template>
					<template x-for="s in (stacks || [])" :key="s.name">
						<div class="hp-advice">
							<i class="fas fa-diagram-project" style="color:var(--hp-primary)"></i>
							<div style="flex:1">
								<a href="#" x-on:click.prevent="openStack(s.name)"><b x-text="s.name"></b></a>
								<div class="hp-muted hp-small" x-text="s.status"></div>
								<div class="hp-btn-group hp-mt" style="margin-top:6px">
									<button type="button" class="hp-btn hp-btn-xs" x-on:click="stackAct(s.name, 'up')"><i class="fas fa-play"></i>Up</button>
									<button type="button" class="hp-btn hp-btn-xs" x-on:click="stackAct(s.name, 'restart')"><i class="fas fa-rotate"></i></button>
									<button type="button" class="hp-btn hp-btn-xs" x-on:click="stackAct(s.name, 'pull')" title="Actualizar imágenes"><i class="fas fa-download"></i></button>
									<button type="button" class="hp-btn hp-btn-xs" x-on:click="stackAct(s.name, 'down')"><i class="fas fa-stop"></i>Down</button>
									<button type="button" class="hp-btn hp-btn-xs hp-btn-danger" x-on:click="stackDelete(s.name)"><i class="fas fa-trash"></i></button>
								</div>
							</div>
						</div>
					</template>
				</div>
			</div>
		</div>
	</div>

	<!-- Ejecutar contenedor -->
	<dialog class="hp-modal" x-ref="run">
		<div class="hp-modal-head"><h3>Ejecutar contenedor</h3><button type="button" class="hp-icon-btn" x-on:click="$refs.run.close()"><i class="fas fa-xmark"></i></button></div>
		<div class="hp-modal-body hp-form">
			<div class="hp-form-row">
				<div class="hp-field"><label>Imagen</label><input class="hp-input hp-mono" x-model="run.image" placeholder="redis:7"></div>
				<div class="hp-field"><label>Nombre</label><input class="hp-input" x-model="run.name" placeholder="mi-redis"></div>
			</div>
			<div class="hp-field">
				<label>Puertos (servidor → contenedor)</label>
				<template x-for="(p, i) in run.ports" :key="i">
					<div class="hp-flex" style="margin-bottom:6px">
						<input class="hp-input" type="number" placeholder="8080" x-model.number="p.host" style="max-width:130px">
						<i class="fas fa-arrow-right hp-muted"></i>
						<input class="hp-input" type="number" placeholder="80" x-model.number="p.container" style="max-width:130px">
						<label class="hp-check" style="margin-left:8px"><input type="checkbox" x-model="p.public"><span><small>Público (0.0.0.0)</small></span></label>
						<button type="button" class="hp-btn hp-btn-ghost hp-btn-sm" x-on:click="run.ports.splice(i, 1)"><i class="fas fa-xmark"></i></button>
					</div>
				</template>
				<button type="button" class="hp-btn hp-btn-sm" x-on:click="run.ports.push({host: '', container: '', public: false})"><i class="fas fa-plus"></i>Puerto</button>
				<p class="hp-hint">Sin marcar "Público" solo se accede desde el propio servidor (ideal para usarlo con un dominio en modo Proxy).</p>
			</div>
			<div class="hp-field">
				<label>Volúmenes (ruta o volumen con nombre → ruta del contenedor)</label>
				<template x-for="(v, i) in run.volumes" :key="i">
					<div class="hp-flex" style="margin-bottom:6px">
						<input class="hp-input hp-mono" placeholder="datos-redis o /opt/datos" x-model="v.host">
						<i class="fas fa-arrow-right hp-muted"></i>
						<input class="hp-input hp-mono" placeholder="/data" x-model="v.container">
						<button type="button" class="hp-btn hp-btn-ghost hp-btn-sm" x-on:click="run.volumes.splice(i, 1)"><i class="fas fa-xmark"></i></button>
					</div>
				</template>
				<button type="button" class="hp-btn hp-btn-sm" x-on:click="run.volumes.push({host: '', container: ''})"><i class="fas fa-plus"></i>Volumen</button>
			</div>
			<div class="hp-field">
				<label>Variables de entorno</label>
				<textarea class="hp-textarea" style="min-height:70px" x-model="run.envText" placeholder="CLAVE=valor (una por línea)"></textarea>
			</div>
			<div class="hp-form-row">
				<div class="hp-field"><label>Reinicio</label><select class="hp-select" x-model="run.restart"><option value="unless-stopped">Siempre (salvo detenido)</option><option value="always">Siempre</option><option value="on-failure">Solo si falla</option><option value="no">Nunca</option></select></div>
				<div class="hp-field"><label>Límite de memoria (MB)</label><input class="hp-input" type="number" x-model.number="run.memory_mb" placeholder="0 = sin límite"></div>
			</div>
		</div>
		<div class="hp-modal-foot">
			<button type="button" class="hp-btn" x-on:click="$refs.run.close()">Cancelar</button>
			<button type="button" class="hp-btn hp-btn-primary" x-on:click="doRun()"><i class="fas fa-play"></i>Ejecutar</button>
		</div>
	</dialog>
</div>

<script>
document.addEventListener('alpine:init', () => {
	Alpine.data('hpDocker', () => ({
		tab: 'containers',
		containers: null,
		images: null,
		stacks: null,
		pullName: '',
		stack: { name: '', yaml: 'services:\n  web:\n    image: nginx:alpine\n    restart: unless-stopped\n    ports:\n      - "127.0.0.1:8081:80"\n', env: '', saved: false },
		run: { image: '', name: '', ports: [], volumes: [], envText: '', restart: 'unless-stopped', memory_mb: 0 },
		init() {
			this.load();
			setInterval(() => !document.hidden && this.tab === 'containers' && this.load(), 15000);
		},
		async load() {
			try { this.containers = await HP.api('docker-ps'); } catch (e) { HP.toast(e.message, 'error'); this.containers = []; }
		},
		async loadImages() {
			try { this.images = await HP.api('docker-images'); } catch (e) { HP.toast(e.message, 'error'); }
		},
		async loadStacks() {
			try { this.stacks = await HP.api('compose-list'); } catch (e) { HP.toast(e.message, 'error'); }
		},
		async act(c, what) {
			if (what === 'remove' && !(await HP.confirm('¿Eliminar el contenedor ' + c.Names + '? Los datos que no estén en volúmenes se pierden.', { danger: true, ok: 'Eliminar' }))) return;
			try { await HP.api('docker-action', { id: c.Names, what }); HP.toast('Hecho'); this.load(); } catch (e) { HP.toast(e.message, 'error'); }
		},
		logs(c) {
			HP.logs('Registros de ' + c.Names, () => HP.api('docker-logs', { id: c.Names, lines: 500 }));
		},
		async pull() {
			if (!this.pullName) return;
			try { await HP.runJob('docker-pull', { image: this.pullName }, 'Descargando ' + this.pullName, { autoClose: true }); this.pullName = ''; this.loadImages(); } catch (e) { HP.toast(e.message, 'error'); }
		},
		async rmi(i) {
			if (!(await HP.confirm('¿Eliminar la imagen ' + i.Repository + ':' + i.Tag + '?', { danger: true, ok: 'Eliminar' }))) return;
			try { await HP.api('docker-rmi', { id: i.Repository + ':' + i.Tag }); this.loadImages(); } catch (e) { HP.toast(e.message, 'error'); }
		},
		openRun(image) {
			if (image) {
				this.run.image = image;
				this.run.name = image.split('/').pop().split(':')[0].replace(/[^a-z0-9_.-]/gi, '-');
			}
			this.$refs.run.showModal();
		},
		async doRun() {
			const env = {};
			this.run.envText.split('\n').forEach((l) => { const i = l.indexOf('='); if (i > 0) env[l.slice(0, i).trim()] = l.slice(i + 1); });
			const container = Object.assign({}, this.run, { env });
			delete container.envText;
			this.$refs.run.close();
			try { await HP.runJob('docker-run', { container }, 'Creando ' + this.run.name); this.tab = 'containers'; this.load(); } catch (e) { HP.toast(e.message, 'error'); }
		},
		newStack() {
			this.stack = { name: '', yaml: 'services:\n  app:\n    image: \n    restart: unless-stopped\n', env: '', saved: false };
		},
		async openStack(name) {
			try { const s = await HP.api('compose-get', { name }); this.stack = Object.assign(s, { saved: true }); } catch (e) { HP.toast(e.message, 'error'); }
		},
		async saveStack(deploy) {
			try {
				const r = await HP.api('compose-save', { name: this.stack.name, yaml: this.stack.yaml, env: this.stack.env });
				if (r.warning) HP.toast('Aviso de compose: ' + r.warning, 'error');
				this.stack.saved = true;
				if (deploy) await HP.runJob('compose-action', { name: this.stack.name, what: 'up' }, 'Desplegando ' + this.stack.name);
				else HP.toast('Pila guardada');
				this.loadStacks();
			} catch (e) { HP.toast(e.message, 'error'); }
		},
		async stackAct(name, what) {
			try { await HP.runJob('compose-action', { name, what }, 'docker compose ' + what + ' · ' + name, { autoClose: what !== 'up' }); this.loadStacks(); } catch (e) { HP.toast(e.message, 'error'); }
		},
		async stackDelete(name) {
			if (!(await HP.confirm('¿Detener y eliminar la pila ' + name + '? Los volúmenes con nombre se conservan.', { danger: true, ok: 'Eliminar' }))) return;
			try { await HP.api('compose-delete', { name }); this.loadStacks(); if (this.stack.name === name) this.newStack(); } catch (e) { HP.toast(e.message, 'error'); }
		},
	}));
});
</script>
<?php } ?>
