<?php
// Despliegue Git de un dominio. Requiere $cfg = ["user", "domain"].
?>
<div x-data="hpGit(<?= e(json_encode($cfg)) ?>)">
	<template x-if="!loaded"><div class="hp-card"><div class="hp-empty"><i class="fas fa-circle-notch fa-spin"></i> <?= __e('Cargando…') ?></div></div></template>
	<div x-show="loaded" x-cloak>
		<template x-if="git">
			<div class="hp-card hp-mb">
				<div class="hp-card-body hp-between hp-wrap">
					<div>
						<div class="hp-flex hp-wrap"><i class="fas fa-code-branch" style="color:var(--hp-primary)"></i><b class="hp-mono" x-text="git.repo"></b><span class="hp-tag" x-text="git.branch"></span></div>
						<div class="hp-muted hp-small" style="margin-top:6px">
							<template x-if="git.head"><span x-text="HP.t('Último commit ') + git.head.commit + ': ' + git.head.subject + ' (' + git.head.author + ')'"></span></template>
							<template x-if="!git.head"><span><?= __e('Aún no desplegado') ?></span></template>
							<template x-if="git.last_deploy"><span x-text="HP.t(' · desplegado ') + new Date(git.last_deploy * 1000).toLocaleString()"></span></template>
						</div>
						<template x-if="git.last_status && git.last_status !== 'ok'"><div class="hp-badge hp-badge-danger hp-mt" x-text="git.last_status"></div></template>
					</div>
					<div class="hp-btn-group">
						<button type="button" class="hp-btn hp-btn-primary" x-on:click="deploy()"><i class="fas fa-cloud-arrow-down"></i><?= __e('Desplegar ahora') ?></button>
						<button type="button" class="hp-btn hp-btn-danger" x-on:click="remove()"><i class="fas fa-link-slash"></i><?= __e('Desvincular') ?></button>
					</div>
				</div>
			</div>
		</template>

		<div class="hp-card">
			<div class="hp-card-head"><h2 class="hp-card-title"><i class="fas fa-code-branch"></i><?= __e('Repositorio') ?></h2></div>
			<div class="hp-card-body hp-form">
				<div class="hp-form-row">
					<div class="hp-field">
						<label><?= __e('URL del repositorio') ?></label>
						<input class="hp-input hp-mono" x-model="form.repo" placeholder="<?= __e('https://github.com/org/proyecto.git · git@github.com:org/proyecto.git') ?>">
					</div>
					<div class="hp-field">
						<label><?= __e('Rama') ?></label>
						<input class="hp-input hp-mono" x-model="form.branch" placeholder="main">
					</div>
				</div>
				<div class="hp-field">
					<label><?= __e('Carpeta de destino') ?></label>
					<div class="hp-flex" style="gap:0"><span class="hp-addon" x-text="'~/web/' + domain + '/'"></span><input class="hp-input" x-model="form.path" placeholder="public_html"></div>
					<p class="hp-hint">Para una app Node.js/Python usa su misma carpeta (por defecto <code>app</code>).</p>
				</div>
				<div class="hp-field">
					<label><?= __e('Acciones después de desplegar (opcional)') ?></label>
					<textarea class="hp-textarea" x-model="form.post_deploy" placeholder="npm ci&#10;npm run build&#10;# o: composer install --no-dev / php artisan migrate --force"></textarea>
					<p class="hp-hint"><?= __e('Se ejecutan como') ?> <b x-text="user"></b> <?= __e('dentro de la carpeta de destino. Si fallan, el despliegue se marca con error.') ?></p>
				</div>
				<label class="hp-check"><input type="checkbox" x-model="form.restart_app"><span><b><?= __e('Reiniciar la aplicación del dominio al terminar') ?></b><small><?= __e('Si hay una app Node.js/Python/Docker configurada.') ?></small></span></label>
				<label class="hp-check"><input type="checkbox" x-model="form.auto"><span><b><?= __e('Despliegue automático con webhook') ?></b><small>Cada push a la rama despliega solo (GitHub, GitLab, Gitea, Bitbucket).</small></span></label>
				<template x-if="git && form.auto">
					<div class="hp-field">
						<label><?= __e('URL del webhook') ?></label>
						<div class="hp-input-group"><input class="hp-input hp-mono" readonly :value="webhook()"><button type="button" class="hp-btn" x-on:click="copy(webhook())"><i class="fas fa-copy"></i></button></div>
						<p class="hp-hint"><?= __e('Pégala en Settings → Webhooks del repositorio (evento push, tipo JSON). Guarda los cambios primero.') ?></p>
					</div>
				</template>
				<div class="hp-card" style="background:var(--hp-bg);box-shadow:none">
					<div class="hp-card-body">
						<div class="hp-between hp-wrap">
							<div><b><?= __e('¿Repositorio privado?') ?></b><div class="hp-muted hp-small">Usa la URL SSH y agrega esta llave de despliegue (solo lectura) al repositorio.</div></div>
							<button type="button" class="hp-btn hp-btn-sm" x-on:click="showKey()"><i class="fas fa-key"></i><?= __e('Ver llave pública') ?></button>
						</div>
						<template x-if="key">
							<div class="hp-input-group hp-mt"><input class="hp-input hp-mono" readonly :value="key"><button type="button" class="hp-btn" x-on:click="copy(key)"><i class="fas fa-copy"></i></button></div>
						</template>
					</div>
				</div>
				<div class="hp-right"><button type="button" class="hp-btn hp-btn-primary" x-on:click="save()"><i class="fas fa-check"></i><?= __e('Guardar repositorio') ?></button></div>
			</div>
		</div>
	</div>
</div>
