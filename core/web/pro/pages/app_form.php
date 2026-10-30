<?php
// Formulario de aplicación de un dominio. Requiere $cfg = ["user", "domain", "isAdmin"].
?>
<div x-data="hpAppForm(<?= e(json_encode($cfg)) ?>)">
	<template x-if="!loaded"><div class="hp-card"><div class="hp-empty"><i class="fas fa-circle-notch fa-spin"></i> Cargando…</div></div></template>

	<template x-if="loaded">
	<div>
		<!-- Estado de la app existente -->
		<template x-if="app && app.type">
			<div class="hp-card hp-mb">
				<div class="hp-card-body hp-between hp-wrap">
					<div class="hp-flex" style="gap:14px">
						<span class="hp-chip" :class="'hp-chip-' + app.type" x-html="{node:'JS',python:'PY',docker:'<i class=\'fas fa-box\'></i>',proxy:'<i class=\'fas fa-right-left\'></i>'}[app.type]"></span>
						<div>
							<div class="hp-flex hp-wrap">
								<b style="font-size:1.05rem;color:var(--hp-text)" x-text="{node:'Node.js ' + app.version, python:'Python ' + app.version, docker:'Docker · ' + app.image, proxy:'Proxy → ' + app.target}[app.type]"></b>
								<span class="hp-badge" :class="stateClass(app.state)"><i class="fas fa-circle"></i><span x-text="stateLabel(app.state)"></span></span>
							</div>
							<div class="hp-muted hp-small hp-mt" style="margin-top:4px">
								<span x-show="app.port" x-text="'Puerto interno 127.0.0.1:' + app.port"></span>
								<span x-show="app.pid" x-text="' · PID ' + app.pid"></span>
								<span x-show="app.memory" x-text="' · RAM ' + mem(app.memory)"></span>
								<span x-show="app.restarts" x-text="' · ' + app.restarts + ' reinicios'"></span>
							</div>
						</div>
					</div>
					<div class="hp-btn-group">
						<button type="button" class="hp-btn hp-btn-sm" x-show="app.type !== 'proxy' && app.state !== 'running'" x-on:click="act('start')"><i class="fas fa-play" style="color:var(--hp-ok)"></i>Iniciar</button>
						<button type="button" class="hp-btn hp-btn-sm" x-show="app.type !== 'proxy'" x-on:click="act('restart')"><i class="fas fa-rotate"></i>Reiniciar</button>
						<button type="button" class="hp-btn hp-btn-sm" x-show="app.type !== 'proxy' && app.state === 'running'" x-on:click="act('stop')"><i class="fas fa-stop" style="color:var(--hp-danger)"></i>Detener</button>
						<button type="button" class="hp-btn hp-btn-sm" x-show="app.enabled" x-on:click="act('disable')" title="Deja el sitio respondiendo 503"><i class="fas fa-toggle-on"></i>Deshabilitar</button>
						<button type="button" class="hp-btn hp-btn-sm" x-show="!app.enabled" x-on:click="act('enable')"><i class="fas fa-toggle-off"></i>Habilitar</button>
						<button type="button" class="hp-btn hp-btn-sm" x-on:click="logs()"><i class="fas fa-file-lines"></i>Registros</button>
						<button type="button" class="hp-btn hp-btn-sm" x-show="app.type === 'node' || app.type === 'python'" x-on:click="deps()"><i class="fas fa-download"></i><span x-text="app.type === 'node' ? 'NPM install' : 'pip install'"></span></button>
					</div>
				</div>
				<template x-if="app.type === 'node' && Object.keys(scripts).length">
					<div class="hp-card-body" style="border-top:1px solid var(--hp-border)">
						<span class="hp-muted hp-small" style="margin-right:8px">Ejecutar script de package.json:</span>
						<template x-for="(cmd, name) in scripts" :key="name">
							<button type="button" class="hp-btn hp-btn-xs" style="margin:2px" :title="cmd" x-on:click="runScript(name)"><i class="fas fa-play"></i><span x-text="name"></span></button>
						</template>
					</div>
				</template>
			</div>
		</template>

		<div class="hp-card">
			<div class="hp-card-head">
				<h2 class="hp-card-title"><i class="fas fa-sliders"></i><span x-text="app && app.type ? 'Configuración de la aplicación' : 'Configurar una aplicación para este dominio'"></span></h2>
			</div>
			<div class="hp-card-body hp-form">
				<div>
					<span class="hp-label">Tipo de aplicación</span>
					<div class="hp-choices">
						<label class="hp-choice" :class="form.type === 'node' && 'active'" x-on:click="setType('node')">
							<span class="hp-chip hp-chip-node hp-chip-sm">JS</span><b>Node.js</b><small>Express, Next.js, NestJS, Nuxt… con la versión que elijas.</small>
						</label>
						<label class="hp-choice" :class="form.type === 'python' && 'active'" x-on:click="setType('python')">
							<span class="hp-chip hp-chip-python hp-chip-sm">PY</span><b>Python</b><small>Django, Flask, FastAPI con entorno virtual propio.</small>
						</label>
						<label class="hp-choice" :class="form.type === 'docker' && 'active'" x-on:click="isAdmin && setType('docker')" :style="!isAdmin && 'opacity:.5;cursor:not-allowed'">
							<span class="hp-chip hp-chip-docker hp-chip-sm"><i class="fas fa-box"></i></span><b>Docker</b><small x-text="isAdmin ? 'Cualquier imagen de Docker Hub publicada en el dominio.' : 'Solo el administrador.'"></small>
						</label>
						<label class="hp-choice" :class="form.type === 'proxy' && 'active'" x-on:click="setType('proxy')">
							<span class="hp-chip hp-chip-proxy hp-chip-sm"><i class="fas fa-right-left"></i></span><b>Proxy inverso</b><small>Reenvía el dominio a otro servidor o puerto.</small>
						</label>
					</div>
				</div>

				<!-- Node.js -->
				<template x-if="form.type === 'node'">
					<div class="hp-form">
						<template x-if="!Object.keys(runtimes.node.installed || {}).length">
							<p class="inline-alert inline-alert-danger" style="margin:0"><i class="fas fa-circle-exclamation"></i> No hay versiones de Node.js instaladas. <a href="/pro/apps/#runtimes">Instala una en Aplicaciones → Entornos</a>.</p>
						</template>
						<div class="hp-form-row">
							<div class="hp-field">
								<label>Versión de Node.js</label>
								<select class="hp-select" x-model="form.version">
									<template x-for="(v, major) in runtimes.node.installed" :key="major"><option :value="major" x-text="'Node.js ' + major + ' (' + v + ')'" :selected="String(form.version) === String(major)"></option></template>
								</select>
							</div>
							<div class="hp-field">
								<label>Modo</label>
								<select class="hp-select" x-model="form.mode"><option value="production">Producción (NODE_ENV=production)</option><option value="development">Desarrollo</option></select>
							</div>
						</div>
						<div class="hp-form-row">
							<div class="hp-field">
								<label>Carpeta de la aplicación</label>
								<div class="hp-flex" style="gap:0"><span class="hp-addon" x-text="'~/web/' + domain + '/'"></span><input class="hp-input" x-model="form.root" placeholder="app"></div>
								<p class="hp-hint">Sube aquí el código (Archivos o Git). Si está vacía se crea un ejemplo.</p>
							</div>
							<div class="hp-field">
								<label>Cómo se inicia</label>
								<select class="hp-select" x-model="form.start_mode">
									<option value="file">Archivo de inicio (node archivo.js)</option>
									<option value="npm">Script de npm (npm run …)</option>
									<option value="command">Comando personalizado</option>
								</select>
							</div>
						</div>
						<div class="hp-field">
							<label x-text="{file: 'Archivo de inicio', npm: 'Script de npm', command: 'Comando'}[form.start_mode]"></label>
							<input class="hp-input hp-mono" x-model="form.startup" :placeholder="{file: 'app.js', npm: 'start', command: 'npx next start -p $PORT'}[form.start_mode]">
							<p class="hp-hint">La app debe escuchar en <code>process.env.PORT</code> (y <code>HOST=127.0.0.1</code>). nginx reenvía el dominio a ese puerto, con WebSockets.</p>
						</div>
					</div>
				</template>

				<!-- Python -->
				<template x-if="form.type === 'python'">
					<div class="hp-form">
						<div class="hp-form-row">
							<div class="hp-field">
								<label>Versión de Python</label>
								<select class="hp-select" x-model="form.version">
									<template x-for="(v, key) in runtimes.python.installed" :key="key"><option :value="key" x-text="(key === 'system' ? 'Sistema' : 'Python ' + key) + ' (' + v.version + ')'" :selected="String(form.version) === key"></option></template>
								</select>
							</div>
							<div class="hp-field">
								<label>Servidor de aplicaciones</label>
								<select class="hp-select" x-model="form.server">
									<option value="gunicorn">Gunicorn (WSGI: Django, Flask)</option>
									<option value="uvicorn">Uvicorn (ASGI: FastAPI, Starlette)</option>
									<option value="command">Comando personalizado</option>
								</select>
							</div>
						</div>
						<div class="hp-form-row">
							<div class="hp-field">
								<label>Carpeta de la aplicación</label>
								<div class="hp-flex" style="gap:0"><span class="hp-addon" x-text="'~/web/' + domain + '/'"></span><input class="hp-input" x-model="form.root" placeholder="app"></div>
								<p class="hp-hint">Se crea un entorno <code>.venv</code> y se instala <code>requirements.txt</code>.</p>
							</div>
							<div class="hp-field" x-show="form.server !== 'command'">
								<label>Módulo de la aplicación</label>
								<input class="hp-input hp-mono" x-model="form.module" placeholder="app:app · proyecto.wsgi:application · main:app">
							</div>
							<div class="hp-field" x-show="form.server === 'command'">
								<label>Comando</label>
								<input class="hp-input hp-mono" x-model="form.command" placeholder="python manage.py runserver 127.0.0.1:$PORT">
							</div>
						</div>
						<div class="hp-field" style="max-width:200px" x-show="form.server !== 'command'">
							<label>Procesos (workers)</label>
							<input class="hp-input" type="number" min="1" max="32" x-model.number="form.workers">
						</div>
					</div>
				</template>

				<!-- Docker -->
				<template x-if="form.type === 'docker'">
					<div class="hp-form">
						<template x-if="runtimes.docker && !runtimes.docker.installed">
							<p class="inline-alert inline-alert-danger" style="margin:0"><i class="fas fa-circle-exclamation"></i> Docker no está instalado. <a href="/pro/docker/">Instálalo en Docker</a>.</p>
						</template>
						<div class="hp-form-row">
							<div class="hp-field">
								<label>Imagen</label>
								<input class="hp-input hp-mono" x-model="form.image" placeholder="nginx:alpine · ghost:5 · grafana/grafana">
							</div>
							<div class="hp-field">
								<label>Puerto del contenedor</label>
								<input class="hp-input" type="number" x-model.number="form.container_port" placeholder="80">
								<p class="hp-hint">Se publica solo en 127.0.0.1 y nginx lo sirve en el dominio con SSL.</p>
							</div>
						</div>
						<div class="hp-field">
							<label>Volúmenes</label>
							<template x-for="(v, i) in volumes" :key="i">
								<div class="hp-flex hp-mb" style="margin-bottom:6px">
									<span class="hp-addon" style="border-radius:8px 0 0 8px">~/web/<span x-text="domain"></span>/</span>
									<input class="hp-input" style="border-radius:0" x-model="v.host" placeholder="data">
									<i class="fas fa-arrow-right hp-muted"></i>
									<input class="hp-input hp-mono" x-model="v.container" placeholder="/var/lib/app">
									<button type="button" class="hp-btn hp-btn-ghost hp-btn-sm" x-on:click="volumes.splice(i, 1)"><i class="fas fa-xmark"></i></button>
								</div>
							</template>
							<button type="button" class="hp-btn hp-btn-sm" x-on:click="volumes.push({host: 'data', container: ''})"><i class="fas fa-plus"></i>Agregar volumen</button>
						</div>
						<div class="hp-field">
							<label>Comando (opcional)</label>
							<input class="hp-input hp-mono" x-model="form.command" placeholder="Deja vacío para usar el de la imagen">
						</div>
					</div>
				</template>

				<!-- Proxy -->
				<template x-if="form.type === 'proxy'">
					<div class="hp-field">
						<label>Destino</label>
						<input class="hp-input hp-mono" x-model="form.target" placeholder="http://127.0.0.1:8080">
						<p class="hp-hint">Útil para servicios que ya corren en el servidor (otro puerto) o en otra máquina.</p>
					</div>
				</template>

				<!-- Variables de entorno -->
				<div class="hp-field" x-show="form.type !== 'proxy'">
					<label>Variables de entorno</label>
					<template x-for="(r, i) in env" :key="i">
						<div class="hp-flex" style="margin-bottom:6px">
							<input class="hp-input hp-mono" style="max-width:260px" x-model="r.k" placeholder="NOMBRE">
							<input class="hp-input hp-mono" x-model="r.v" placeholder="valor">
							<button type="button" class="hp-btn hp-btn-ghost hp-btn-sm" x-on:click="env.splice(i, 1)"><i class="fas fa-xmark"></i></button>
						</div>
					</template>
					<button type="button" class="hp-btn hp-btn-sm" x-on:click="env.push({k: '', v: ''})"><i class="fas fa-plus"></i>Agregar variable</button>
					<p class="hp-hint"><code>PORT</code> y <code>HOST</code> se definen solos. Los valores se guardan solo accesibles por root.</p>
				</div>

				<div class="hp-field" style="max-width:260px" x-show="form.type !== 'proxy'">
					<label>Límite de memoria (MB)</label>
					<input class="hp-input" type="number" min="0" x-model.number="form.memory_mb" placeholder="0 = sin límite">
				</div>

				<div class="hp-between hp-wrap" style="border-top:1px solid var(--hp-border);padding-top:16px">
					<button type="button" class="hp-btn hp-btn-danger" x-show="app && app.type" x-on:click="remove()"><i class="fas fa-trash"></i>Quitar aplicación</button>
					<span x-show="!(app && app.type)"></span>
					<button type="button" class="hp-btn hp-btn-primary" :class="busy && 'is-loading'" x-on:click="save()">
						<i class="fas" :class="busy ? 'fa-circle-notch fa-spin' : 'fa-check'"></i><span x-text="app && app.type ? 'Guardar y reiniciar' : 'Crear aplicación'"></span>
					</button>
				</div>
			</div>
		</div>
	</div>
	</template>
</div>
