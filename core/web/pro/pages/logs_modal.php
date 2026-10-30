<?php
// Explorador de registros (accesos y errores) con filtros. Se abre desde cualquier tabla con:
//   window.dispatchEvent(new CustomEvent('hp-logs', { detail: { domain, status, ip, q, range, bots, domains } }))
?>
<div x-data="hpLogs(<?= hp_admin() ? "true" : "false" ?>)" x-on:hp-logs.window="open($event.detail)">
	<dialog class="hp-modal hp-modal-wide" x-ref="dlg" x-on:close="rows = []">
		<div class="hp-modal-head">
			<h3><i class="fas fa-file-lines"></i> <?= __e('Registros') ?> <span class="hp-muted" style="font-weight:400" x-text="f.domain === '*' ? HP.t('de todos los dominios') : 'de ' + f.domain"></span></h3>
			<div class="hp-flex" style="gap:10px">
				<div class="hp-segmented">
					<button type="button" :class="kind === 'access' && 'active'" x-on:click="setKind('access')"><i class="fas fa-list"></i> <?= __e('Peticiones') ?></button>
					<button type="button" :class="kind === 'error' && 'active'" x-on:click="setKind('error')"><i class="fas fa-bug"></i> <?= __e('Errores del servidor') ?></button>
				</div>
				<button type="button" class="hp-icon-btn" x-on:click="$refs.dlg.close()"><i class="fas fa-xmark"></i></button>
			</div>
		</div>
		<div class="hp-modal-body">
			<!-- Filtros -->
			<form class="hp-logs-filters" x-on:submit.prevent="load()">
				<label x-show="domains.length > 1"><span><?= __e('Dominio') ?></span>
					<select class="hp-select" x-model="f.domain" x-on:change="load()">
						<option value="*"><?= __e('Todos los dominios') ?></option>
						<template x-for="d in domains" :key="d"><option :value="d" x-text="d" :selected="d === f.domain"></option></template>
					</select>
				</label>
				<label><span><?= __e('Periodo') ?></span>
					<select class="hp-select" x-model="f.range" x-on:change="load()">
						<option value="15m"><?= __e('Últimos 15 minutos') ?></option><option value="1h"><?= __e('Última hora') ?></option><option value="6h"><?= __e('Últimas 6 horas') ?></option>
						<option value="24h"><?= __e('Últimas 24 horas') ?></option><option value="7d"><?= __e('Últimos 7 días') ?></option><option value="30d"><?= __e('Últimos 30 días') ?></option>
						<option value="all"><?= __e('Todo lo guardado') ?></option>
					</select>
				</label>
				<label x-show="kind === 'access'"><span><?= __e('Respuesta') ?></span>
					<select class="hp-select" x-model="f.status" x-on:change="load()">
						<option value="all"><?= __e('Todas') ?></option><option value="err">Solo errores (4xx y 5xx)</option><option value="5xx">Fallos del servidor (5xx)</option>
						<option value="4xx">Errores del visitante (4xx)</option><option value="404">No encontrado (404)</option><option value="403">Prohibido (403)</option>
						<option value="2xx">Correctas (2xx)</option><option value="3xx">Redirecciones (3xx)</option>
						<template x-if="!['all','err','5xx','4xx','404','403','2xx','3xx'].includes(f.status)"><option :value="f.status" x-text="HP.t('Código ') + f.status" selected></option></template>
					</select>
				</label>
				<label x-show="kind === 'access'"><span>IP</span><input class="hp-input hp-mono" x-model.lazy="f.ip" placeholder="<?= __e('Cualquiera') ?>" x-on:change="load()"></label>
				<label class="hp-grow"><span x-text="kind === 'access' ? HP.t('La URL contiene') : HP.t('El mensaje contiene')"></span><input class="hp-input" x-model.lazy="f.q" :placeholder="kind === 'access' ? HP.t('/wp-login.php, .env, ?id=…') : HP.t('PHP Fatal, upstream, denied…')" x-on:change="load()"></label>
				<label x-show="kind === 'access'"><span><?= __e('Visitantes') ?></span>
					<select class="hp-select" x-model="f.bots" x-on:change="load()"><option value=""><?= __e('Personas y bots') ?></option><option value="exclude"><?= __e('Sin bots') ?></option><option value="only"><?= __e('Solo bots y scripts') ?></option></select>
				</label>
				<button class="hp-btn hp-btn-primary" :disabled="busy"><i class="fas" :class="busy ? HP.t('fa-circle-notch fa-spin') : 'fa-magnifying-glass'"></i><?= __e('Buscar') ?></button>
			</form>

			<template x-if="err"><div class="hp-logs-fail hp-mt" x-text="err"></div></template>

			<!-- Peticiones -->
			<div x-show="kind === 'access' && res">
				<div class="hp-logs-summary">
					<div><b x-text="num(res && res.matched)"></b> de <span x-text="num(res && res.total)"></span> <?= __e('peticiones coinciden') ?>
						<span class="hp-muted" x-show="res && res.matched > res.shown" x-text="'· se muestran las ' + num(res && res.shown) + ' más recientes'"></span>
						<span class="hp-muted" x-show="res && res.truncated"><?= __e('· registro muy grande: acota el periodo para verlo todo') ?></span></div>
					<div class="hp-flex">
						<button type="button" class="hp-btn hp-btn-sm" x-show="f.cc" x-on:click="f.cc = ''; load()" :title="HP.t('Quitar el filtro de país')"><span class="hp-cc-code" x-text="f.cc"></span><span x-text="HP.countryName(f.cc)"></span><i class="fas fa-xmark"></i></button>
						<button type="button" class="hp-btn hp-btn-sm" x-show="filtered()" x-on:click="reset()"><i class="fas fa-filter-circle-xmark"></i><?= __e('Quitar filtros') ?></button>
					</div>
				</div>
				<div class="hp-logs-codes">
					<template x-for="c in (res ? res.codes : [])" :key="c.code">
						<button type="button" class="hp-code" :class="['hp-code-' + String(c.code)[0], String(c.code) === f.status && 'active']" :title="codeHelp(c.code)"
							x-on:click="f.status = String(c.code) === f.status ? 'all' : String(c.code); load()">
							<b x-text="c.code"></b><span x-text="num(c.n)"></span>
						</button>
					</template>
				</div>
				<p class="hp-small hp-muted" x-show="codeNote()" x-html="codeNote()"></p>

				<div class="hp-grid hp-grid-2 hp-logs-tops" x-show="res && res.matched">
					<div>
						<div class="hp-small hp-muted"><?= __e('URLs más repetidas') ?> <span x-show="f.status !== 'all'"><?= __e('con este filtro') ?></span> <?= __e('· clic para filtrar') ?></div>
						<template x-for="p in (res ? res.paths.slice(0, 6) : [])" :key="p.k">
							<button type="button" class="hp-logs-top" x-on:click="f.q = p.k; load()"><span class="hp-mono hp-ellipsis" :title="p.k" x-text="p.k"></span><b x-text="num(p.n)"></b></button>
						</template>
					</div>
					<div>
						<div class="hp-small hp-muted"><?= __e('IPs que más aparecen · clic para filtrar') ?></div>
						<template x-for="p in (res ? res.ips.slice(0, 6) : [])" :key="p.k">
							<button type="button" class="hp-logs-top" x-on:click="f.ip = p.k; load()"><span><span class="hp-cc-code" x-show="p.cc" x-text="p.cc" :title="HP.countryName(p.cc)" style="margin-right:6px"></span><span class="hp-mono" x-text="p.k"></span></span><b x-text="num(p.n)"></b></button>
						</template>
					</div>
				</div>

				<div class="hp-table-wrap hp-logs-table">
					<table class="hp-table">
						<thead><tr><th><?= __e('Hora') ?></th><th x-show="f.domain === '*'"><?= __e('Dominio') ?></th><th>IP</th><th><?= __e('Petición') ?></th><th class="hp-right"><?= __e('Código') ?></th><th class="hp-right"><?= __e('Tamaño') ?></th><th><?= __e('Navegador / cliente') ?></th></tr></thead>
						<tbody>
							<template x-for="(r, i) in rows" :key="i">
								<tr class="hp-logs-row" :class="sel === i && 'open'" x-on:click="sel = sel === i ? -1 : i">
									<td class="hp-nowrap" :title="new Date(r.t * 1000).toLocaleString()" x-text="when(r.t)"></td>
									<td class="hp-small" x-show="f.domain === '*'" x-text="r.domain"></td>
									<td class="hp-nowrap"><span class="hp-cc-code" x-show="r.cc" x-text="r.cc" :title="HP.countryName(r.cc) + HP.t(' · clic para ver solo este país')" style="cursor:pointer;margin-right:5px" x-on:click.stop="f.cc = r.cc; load()"></span><a href="#" class="hp-mono" x-on:click.prevent.stop="f.ip = r.ip; load()" x-text="r.ip" title="<?= __e('Ver solo esta IP') ?>"></a></td>
									<td class="hp-logs-req">
										<span class="hp-logs-method" x-text="r.method"></span><span class="hp-mono" :class="sel !== i && 'hp-ellipsis'" x-text="r.url"></span>
										<template x-if="sel === i">
											<div class="hp-logs-detail" x-on:click.stop>
												<div><span><?= __e('Fecha') ?></span><b x-text="new Date(r.t * 1000).toLocaleString()"></b></div>
												<div x-show="r.cc"><span><?= __e('País') ?></span><b x-text="HP.countryName(r.cc)"></b></div>
												<div><span><?= __e('Protocolo') ?></span><b x-text="r.proto || '—'"></b></div>
												<div><span><?= __e('Viene de') ?></span><b class="hp-mono" x-text="r.ref && r.ref !== '-' ? r.ref : '(acceso directo)'"></b></div>
												<div><span><?= __e('Cliente') ?></span><b class="hp-mono" x-text="r.ua || HP.t('(vacío)')"></b></div>
												<div><span><?= __e('Código') ?></span><b x-text="r.status + ' · ' + codeHelp(r.status)"></b></div>
												<div class="hp-flex" style="gap:6px;margin-top:6px">
													<button type="button" class="hp-btn hp-btn-sm" x-on:click="f.q = r.url.split('?')[0]; load()"><i class="fas fa-link"></i><?= __e('Solo esta URL') ?></button>
													<button type="button" class="hp-btn hp-btn-sm" x-on:click="f.ip = r.ip; f.status = 'all'; load()"><i class="fas fa-user"></i><?= __e('Todo lo de esta IP') ?></button>
													<button type="button" class="hp-btn hp-btn-sm" x-on:click="HP.copy(line(r)); HP.toast(HP.t('Copiado'))"><i class="fas fa-copy"></i><?= __e('Copiar línea') ?></button>
													<button type="button" class="hp-btn hp-btn-sm" x-show="isAdmin && f.domain !== '*'" x-on:click="block(r.ip, 'site')"><i class="fas fa-ban"></i><?= __e('Bloquear IP en este sitio') ?></button>
													<button type="button" class="hp-btn hp-btn-sm hp-btn-danger" x-show="isAdmin" x-on:click="block(r.ip, 'server')"><i class="fas fa-ban"></i><?= __e('Bloquear IP en todo el servidor') ?></button>
												</div>
											</div>
										</template>
									</td>
									<td class="hp-right"><span class="hp-code hp-code-sm" :class="'hp-code-' + String(r.status)[0]" x-text="r.status"></span></td>
									<td class="hp-right hp-nowrap" x-text="HP.bytes(r.bytes)"></td>
									<td class="hp-small"><span class="hp-badge" x-show="r.bot">bot</span> <span :title="r.ua" x-text="agent(r.ua)"></span></td>
								</tr>
							</template>
							<template x-if="res && !rows.length && !busy"><tr><td colspan="7" class="hp-muted"><?= __e('Ninguna petición cumple estos filtros en el periodo elegido.') ?></td></tr></template>
						</tbody>
					</table>
				</div>
			</div>

			<!-- Errores del servidor (nginx / Apache / PHP) -->
			<div x-show="kind === 'error' && eres">
				<p class="hp-small hp-muted" style="margin:14px 0 10px"><?= __e('Aquí aparecen los fallos que el servidor web anotó: errores de PHP, la aplicación que no responde (502/504), permisos, archivos que no existen… Los 404 de visitantes están en') ?> <a href="#" x-on:click.prevent="setKind('access')"><?= __e('Peticiones') ?></a>.</p>
				<template x-for="(r, i) in (eres ? eres.rows : [])" :key="i">
					<div class="hp-logs-err" :class="errLevel(r.line)">
						<div class="hp-small"><b x-text="when(r.t)"></b> <span class="hp-badge" x-text="r.src"></span> <span class="hp-muted" x-show="f.domain === '*'" x-text="r.domain"></span> <span class="hp-badge hp-badge-danger" x-text="errLevel(r.line)"></span></div>
						<pre x-text="errText(r.line)"></pre>
					</div>
				</template>
				<template x-if="eres && !eres.rows.length && !busy"><p class="hp-muted"><?= __e('Sin errores del servidor en este periodo. 👍') ?></p></template>
			</div>
			<template x-if="busy && !rows.length && !(eres && eres.rows.length)"><div class="hp-empty"><i class="fas fa-circle-notch fa-spin"></i> <?= __e('Leyendo registros…') ?></div></template>
		</div>
	</dialog>
</div>
