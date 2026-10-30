<?php
hp_page_header("Asesor", "Revisión automática de seguridad, rendimiento y salud de los sitios, con accesos directos para resolver cada punto.");
?>
<div x-data="hpAdvisor">
	<template x-if="!data"><div class="hp-card"><div class="hp-empty"><i class="fas fa-circle-notch fa-spin"></i> Analizando el servidor…</div></div></template>
	<template x-if="data">
		<div>
			<div class="hp-card hp-mb">
				<div class="hp-card-body hp-flex hp-wrap" style="gap:24px">
					<div class="hp-score" :style="'background:' + (data.score >= 85 ? '#14945a' : data.score >= 60 ? '#e08a00' : '#d23f3f')" x-text="data.score"></div>
					<div style="flex:1;min-width:220px">
						<h2 style="margin:0;color:var(--hp-text)" x-text="data.score >= 85 ? 'Tu servidor está en buena forma' : data.score >= 60 ? 'Hay algunos puntos que mejorar' : 'Requiere atención'"></h2>
						<p class="hp-muted" style="margin:6px 0 0" x-text="counts()"></p>
					</div>
					<button type="button" class="hp-btn" x-on:click="load()"><i class="fas fa-rotate"></i>Volver a analizar</button>
				</div>
			</div>
			<div class="hp-card">
				<template x-if="!data.items.length"><div class="hp-empty"><i class="fas fa-circle-check hp-empty-icon" style="color:var(--hp-ok)"></i><h3>¡Nada pendiente!</h3></div></template>
				<template x-for="(it, i) in data.items" :key="i">
					<div class="hp-advice" :class="'hp-advice-' + it.level">
						<i class="fas" :class="{danger: 'fa-circle-exclamation', warning: 'fa-triangle-exclamation', info: 'fa-circle-info'}[it.level]"></i>
						<div style="flex:1">
							<b x-text="it.title" style="color:var(--hp-text)"></b>
							<div class="hp-muted hp-small" x-text="it.detail"></div>
						</div>
						<template x-if="it.action === 'ssl'"><button type="button" class="hp-btn hp-btn-sm hp-btn-primary" x-on:click="ssl(it)"><i class="fas fa-certificate"></i>Emitir SSL</button></template>
						<template x-if="it.link"><a class="hp-btn hp-btn-sm" :href="it.link">Resolver<i class="fas fa-arrow-right"></i></a></template>
					</div>
				</template>
			</div>
		</div>
	</template>
</div>
<script>
document.addEventListener('alpine:init', () => {
	Alpine.data('hpAdvisor', () => ({
		data: null,
		init() { this.load(); },
		async load() {
			this.data = null;
			try { this.data = await HP.api('advisor'); } catch (e) { HP.toast(e.message, 'error'); this.data = { score: 0, items: [] }; }
		},
		counts() {
			const c = { danger: 0, warning: 0, info: 0 };
			this.data.items.forEach((i) => c[i.level]++);
			return c.danger + ' críticos · ' + c.warning + ' avisos · ' + c.info + ' sugerencias';
		},
		async ssl(it) {
			const q = new URLSearchParams(it.link.split('?')[1]);
			try { await HP.runJob('ssl-issue', { user: q.get('user'), domain: q.get('domain') }, 'SSL para ' + q.get('domain')); this.load(); } catch (e) { HP.toast(e.message, 'error'); }
		},
	}));
});
</script>
