<?php
hp_page_header("Bases de datos", count($dbs) . " bases de datos" . (hp_admin() ? " en todas las suscripciones" : "") . " · importa y exporta volcados como en Plesk", [],
	'<a class="hp-btn hp-btn-primary" href="/add/db/"><i class="fas fa-plus"></i>Agregar base de datos</a>');
$tok = $_SESSION["token"];
?>
<div x-data="hpDatabases" x-on:dragover.prevent x-on:drop.prevent>
	<div class="hp-card hp-mb">
		<div class="hp-card-body">
			<div class="hp-search" style="max-width:420px"><i class="fas fa-magnifying-glass"></i><input type="search" x-model="q" placeholder="Buscar base de datos o usuario…"></div>
		</div>
	</div>

	<div class="hp-card">
		<?php if (!$dbs) { ?>
			<div class="hp-empty">
				<i class="fas fa-database hp-empty-icon"></i>
				<h3>No hay bases de datos</h3>
				<p>Crea una y luego importa tu volcado .sql, .sql.gz o .zip.</p>
				<a class="hp-btn hp-btn-primary" href="/add/db/"><i class="fas fa-plus"></i>Agregar base de datos</a>
			</div>
		<?php } else { ?>
			<div class="hp-table-wrap">
				<table class="hp-table">
					<thead><tr><th>Base de datos</th><?= hp_admin() ? "<th>Suscripción</th>" : "" ?><th>Usuario</th><th>Tipo</th><th class="hp-right">Tamaño</th><th class="hp-right">Acciones</th></tr></thead>
					<tbody>
						<?php foreach ($dbs as $d) {
							$own = $d["OWNER"] === hp_me();
							$cfg = ["user" => $d["OWNER"], "database" => $d["NAME"], "type" => $d["TYPE"]];
							$search = strtolower($d["NAME"] . " " . $d["DBUSER"] . " " . $d["OWNER"]);
							?>
							<tr x-show="!q || <?= e(json_encode($search)) ?>.includes(q.toLowerCase())" style="<?= $d["SUSPENDED"] === "yes" ? "opacity:.6" : "" ?>">
								<td>
									<b><?= e($d["NAME"]) ?></b>
									<?= $d["SUSPENDED"] === "yes" ? '<span class="hp-badge hp-badge-danger">Suspendida</span>' : "" ?>
									<div class="hp-muted hp-small"><?= e($d["CHARSET"]) ?> · creada <?= e($d["DATE"]) ?></div>
								</td>
								<?= hp_admin() ? "<td>" . e($d["OWNER"]) . "</td>" : "" ?>
								<td class="hp-mono"><?= e($d["DBUSER"]) ?></td>
								<td><span class="hp-tag"><?= $d["TYPE"] === "pgsql" ? "PostgreSQL" : "MySQL / MariaDB" ?></span></td>
								<td class="hp-right hp-nowrap"><?= hp_mb($d["U_DISK"]) ?></td>
								<td class="hp-right hp-nowrap">
									<?php if ($d["TYPE"] === "mysql" && $d["SUSPENDED"] !== "yes") { ?>
										<a class="hp-btn hp-btn-xs" target="_blank" rel="noopener" title="Abrir phpMyAdmin ya conectado a esta base de datos" href="/pro/databases/pma.php?<?= e(http_build_query(["user" => $d["OWNER"], "database" => $d["NAME"], "token" => $tok])) ?>"><i class="fas fa-table"></i>phpMyAdmin</a>
									<?php } ?>
									<?php if ($d["TYPE"] === "mysql") { ?>
										<button type="button" class="hp-btn hp-btn-xs hp-btn-primary" x-on:click="openImport(<?= e(json_encode($cfg)) ?>)"><i class="fas fa-file-import"></i>Importar volcado</button>
									<?php } ?>
									<a class="hp-btn hp-btn-xs" href="/pro/databases/export.php?<?= e(http_build_query(["user" => $d["OWNER"], "database" => $d["NAME"], "token" => $tok])) ?>" title="Descarga .sql.gz"><i class="fas fa-file-export"></i>Exportar</a>
									<button type="button" class="hp-btn hp-btn-xs" title="Tablas" x-on:click="showTables(<?= e(json_encode($cfg)) ?>)"><i class="fas fa-table-list"></i></button>
									<?php if ($own) { ?>
										<a class="hp-btn hp-btn-xs" title="Usuario y contraseña" href="/edit/db/?<?= e(http_build_query(["database" => $d["NAME"], "token" => $tok])) ?>"><i class="fas fa-key"></i></a>
										<a class="hp-btn hp-btn-xs hp-btn-danger js-confirm-action" title="Eliminar" href="/delete/db/?<?= e(http_build_query(["database" => $d["NAME"], "token" => $tok])) ?>" data-confirm-title="Eliminar" data-confirm-message="¿Eliminar la base de datos <?= e($d["NAME"]) ?> y todos sus datos?"><i class="fas fa-trash"></i></a>
									<?php } elseif (hp_admin()) { ?>
										<a class="hp-btn hp-btn-xs" title="Entrar como <?= e($d["OWNER"]) ?> para editar" href="/login/?<?= e(http_build_query(["loginas" => $d["OWNER"], "token" => $tok])) ?>"><i class="fas fa-right-to-bracket"></i></a>
									<?php } ?>
								</td>
							</tr>
						<?php } ?>
					</tbody>
				</table>
			</div>
		<?php } ?>
	</div>

	<!-- Importar -->
	<dialog class="hp-modal" x-ref="imp">
		<div class="hp-modal-head"><h3 x-text="'Importar volcado en ' + (target ? target.database : '')"></h3><button type="button" class="hp-icon-btn" x-on:click="$refs.imp.close()" :disabled="busy"><i class="fas fa-xmark"></i></button></div>
		<div class="hp-modal-body hp-form">
			<div class="hp-segmented">
				<button type="button" :class="mode === 'upload' && 'active'" x-on:click="mode = 'upload'">Subir desde mi equipo</button>
				<button type="button" :class="mode === 'server' && 'active'" x-on:click="mode = 'server'">Archivo ya en el servidor</button>
			</div>

			<template x-if="mode === 'upload'">
				<div>
					<label class="hp-choice" style="align-items:center;text-align:center;padding:28px;border-style:dashed" :class="dragging && 'active'"
						x-on:dragover.prevent="dragging = true" x-on:dragleave="dragging = false" x-on:drop.prevent="dragging = false; pick($event.dataTransfer.files[0])">
						<input type="file" accept=".sql,.gz,.zip,.bz2,.xz,.zst" x-on:change="pick($event.target.files[0])" style="position:static;opacity:1;pointer-events:auto;max-width:100%">
						<i class="fas fa-cloud-arrow-up" style="font-size:2rem;color:var(--hp-primary)"></i>
						<b x-text="file ? file.name + ' (' + HP.bytes(file.size) + ')' : 'Arrastra aquí el volcado o elígelo'"></b>
						<small>.sql, .sql.gz, .zip, .bz2, .xz o .zst · hasta 8 GB (se sube por partes)</small>
					</label>
					<template x-if="uploaded > 0">
						<div class="hp-mt">
							<div class="hp-between hp-small"><span x-text="uploaded >= (file ? file.size : 0) ? 'Subido' : 'Subiendo…'"></span><span x-text="HP.bytes(uploaded) + ' / ' + HP.bytes(file ? file.size : 0)"></span></div>
							<div class="hp-progress" style="margin-top:6px"><span :style="'width:' + (file ? Math.round(uploaded * 100 / file.size) : 0) + '%'"></span></div>
						</div>
					</template>
				</div>
			</template>

			<template x-if="mode === 'server'">
				<div class="hp-field">
					<label>Ruta del archivo</label>
					<input class="hp-input hp-mono" x-model="serverPath" :placeholder="'/home/' + (target ? target.user : 'usuario') + '/respaldo.sql.gz'">
					<p class="hp-hint">Para volcados muy grandes: súbelos por FTP/SFTP o con el Administrador de archivos a tu carpeta personal y escribe aquí la ruta.</p>
				</div>
			</template>

			<label class="hp-check"><input type="checkbox" x-model="wipe"><span><b>Vaciar la base de datos antes de importar</b><small>Elimina todas las tablas, vistas y rutinas actuales (como "Recrear la base de datos" en Plesk).</small></span></label>
			<p class="inline-alert inline-alert-info" style="margin:0"><i class="fas fa-shield-halved"></i><span>La importación usa un usuario temporal con permisos solo sobre esta base de datos: se ignoran <code>CREATE DATABASE</code>/<code>USE</code> y las vistas, triggers y procedimientos quedan a nombre de su usuario.</span></p>
			<p class="inline-alert inline-alert-danger" x-show="error" x-text="error" style="margin:0"></p>
		</div>
		<div class="hp-modal-foot">
			<button type="button" class="hp-btn" x-on:click="$refs.imp.close()" :disabled="busy">Cancelar</button>
			<button type="button" class="hp-btn hp-btn-primary" :class="busy && 'is-loading'" x-on:click="start()"><i class="fas" :class="busy ? 'fa-circle-notch fa-spin' : 'fa-file-import'"></i>Importar</button>
		</div>
	</dialog>
</div>

<script>
document.addEventListener('alpine:init', () => {
	Alpine.data('hpDatabases', () => ({
		q: '',
		target: null,
		mode: 'upload',
		file: null,
		serverPath: '',
		wipe: false,
		uploaded: 0,
		busy: false,
		dragging: false,
		error: '',
		openImport(t) {
			Object.assign(this, { target: t, file: null, serverPath: '', wipe: false, uploaded: 0, error: '', mode: 'upload' });
			this.$refs.imp.showModal();
		},
		pick(f) {
			if (f) { this.file = f; this.uploaded = 0; this.error = ''; }
		},
		async upload() {
			const id = Array.from(crypto.getRandomValues(new Uint8Array(16)), (b) => b.toString(16).padStart(2, '0')).join('');
			const size = 8 * 1024 * 1024;
			for (let i = 0, off = 0; off < this.file.size; i++, off += size) {
				const res = await fetch('/pro/databases/upload.php', {
					method: 'POST',
					credentials: 'same-origin',
					headers: { 'Content-Type': 'application/octet-stream', 'X-Token': window.HP_TOKEN, 'X-Upload-Id': id, 'X-Chunk-Index': String(i), 'X-File-Size': String(this.file.size), 'X-File-Name': encodeURIComponent(this.file.name) },
					body: this.file.slice(off, off + size),
				});
				const j = await res.json().catch(() => ({ error: 'Error ' + res.status }));
				if (!res.ok || j.error) throw new Error(j.error || 'Error ' + res.status);
				this.uploaded = j.received;
			}
			return 'upload:' + id;
		},
		async start() {
			this.error = '';
			if (this.mode === 'upload' && !this.file) return (this.error = 'Elige el archivo del volcado.');
			if (this.mode === 'server' && !this.serverPath.trim()) return (this.error = 'Escribe la ruta del archivo.');
			if (this.wipe && !(await HP.confirm('Se borrarán todas las tablas actuales de ' + this.target.database + ' antes de importar. ¿Continuar?', { danger: true, ok: 'Vaciar e importar' }))) return;
			this.busy = true;
			try {
				const source = this.mode === 'upload' ? await this.upload() : this.serverPath.trim();
				this.$refs.imp.close();
				await HP.runJob('db-import', { user: this.target.user, database: this.target.database, source, wipe: this.wipe }, 'Importando en ' + this.target.database);
				HP.toast('Volcado importado');
				setTimeout(() => location.reload(), 900);
			} catch (e) {
				this.error = e.message;
				if (!this.$refs.imp.open) HP.toast(e.message, 'error');
			}
			this.busy = false;
		},
		async showTables(t) {
			const d = HP.dialog('Tablas de ' + t.database, '<div class="hp-empty"><i class="fas fa-circle-notch fa-spin"></i></div>', '<button type="button" class="hp-btn hp-btn-primary" data-close>Cerrar</button>');
			const body = d.querySelector('.hp-modal-body');
			try {
				const rows = await HP.api('db-tables', { user: t.user, database: t.database });
				body.innerHTML = rows.length
					? '<table class="hp-table"><thead><tr><th>Tabla</th><th>Motor</th><th class="hp-right">Filas</th><th class="hp-right">Tamaño</th></tr></thead><tbody>' +
					  rows.map((r) => '<tr><td class="hp-mono">' + HP.esc(r.name) + '</td><td>' + HP.esc(r.engine) + '</td><td class="hp-right">' + r.rows.toLocaleString() + '</td><td class="hp-right">' + HP.bytes(r.size) + '</td></tr>').join('') +
					  '</tbody></table>'
					: '<div class="hp-empty">La base de datos está vacía.</div>';
			} catch (e) {
				body.innerHTML = '<p class="inline-alert inline-alert-danger">' + HP.esc(e.message) + '</p>';
			}
		},
	}));
});
</script>
